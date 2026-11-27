<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Factory;
use Throwable;

class ChatMessageController extends Controller
{
    public function join(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'type' => ['required', 'in:global,movie,episode'],
            'content_id' => ['nullable', 'string', 'max:80'],
        ]);
        if ($payload['type'] !== 'global' && blank($payload['content_id'] ?? null)) {
            return V4Response::error('content_required', 'Choose a title before opening this chat room.', 422);
        }
        $contentId = (string) ($payload['content_id'] ?? '');
        if ($payload['type'] !== 'global' && ! ctype_digit($contentId)) {
            return V4Response::error('content_unavailable', 'This chat room is unavailable for the selected title.', 404);
        }

        $userId = trim((string) $request->input('auth_user_id'));
        if ($userId === '') {
            return V4Response::error('unauthenticated', 'Your sign-in session is no longer valid.', 401);
        }

        try {
            $contentTitle = null;
            if ($payload['type'] === 'movie') {
                $contentTitle = DB::table('movie')->where('num', $contentId)->where('isEnable', true)->value('title');
            } elseif ($payload['type'] === 'episode') {
                $contentTitle = DB::table('episode')->where('num', $contentId)->where('isEnable', true)->value('title');
            }
            if ($payload['type'] !== 'global' && ! is_string($contentTitle)) {
                return V4Response::error('content_unavailable', 'This chat room is unavailable for the selected title.', 404);
            }

            $user = DB::table('user')->where('num', $userId)->first(['uid', 'name']);
            $firebaseUid = trim((string) ($user->uid ?? ''));
            if ($firebaseUid === '' || preg_match('/^[A-Za-z0-9_-]+$/', $firebaseUid) !== 1) {
                return V4Response::error('chat_identity_unavailable', 'Your chat identity is unavailable.', 403);
            }

            $roomId = $payload['type'] === 'global'
                ? 'global'
                : $payload['type'].'-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $payload['content_id']);
            $database = $this->database();
            $stateRef = $database->getReference("chat_v1/rooms/{$roomId}/state");
            $state = $stateRef->getValue();
            if (! is_array($state)) {
                $state = [
                    'active' => true,
                    'can_send' => true,
                    'room_type' => $payload['type'],
                    'content_id' => $payload['content_id'] ?? null,
                    'created_at' => now()->utc()->toIso8601String(),
                    'sync_cursor' => 0,
                ];
                $stateRef->set($state);
            }
            if (($state['active'] ?? false) !== true || ($state['can_send'] ?? false) !== true) {
                return V4Response::error('room_unavailable', 'This chat room is unavailable.', 403);
            }

            $memberRef = $database->getReference("chat_v1/rooms/{$roomId}/members/{$firebaseUid}");
            $membership = $memberRef->getValue();
            if (! is_array($membership) || ($membership['active'] ?? false) !== true) {
                $memberRef->set([
                    'active' => true,
                    'can_send' => true,
                    'user_id' => $userId,
                    'display_name' => trim((string) ($user->name ?? '')) ?: 'Viewer',
                    'joined_at' => now()->utc()->toIso8601String(),
                ]);
            }

            $lastMessage = $database->getReference("chat_v1/rooms/{$roomId}/last_message_id")->getValue();
            return V4Response::success([
                'room_id' => $roomId,
                'room_type' => $payload['type'],
                'channel' => "private-chat.{$roomId}",
                'websocket_url' => '',
                'user_id' => $userId,
                'room_name' => $payload['type'] === 'global' ? 'Global Chat' : null,
                'content_type' => $payload['type'] === 'global' ? null : $payload['type'],
                'content_id' => $payload['content_id'] ?? null,
                'content_title' => $contentTitle,
                'is_admin' => false,
                'is_host' => false,
                'firebase_realtime' => true,
                'sync_cursor' => (int) ($lastMessage ?: 0),
            ]);
        } catch (Throwable $error) {
            report($error);
            return V4Response::error('chat_unavailable', 'Chat is temporarily unavailable. Please try again.', 503);
        }
    }

    public function firebaseToken(Request $request): JsonResponse
    {
        $userId = trim((string) $request->input('auth_user_id'));
        if ($userId === '') {
            return V4Response::error('unauthenticated', 'Your sign-in session is no longer valid.', 401);
        }
        try {
            $uid = trim((string) DB::table('user')->where('num', $userId)->value('uid'));
            if ($uid === '' || preg_match('/^[A-Za-z0-9_-]+$/', $uid) !== 1) {
                return V4Response::error('chat_identity_unavailable', 'Your chat identity is unavailable.', 403);
            }
            $auth = (new Factory)->withServiceAccount((string) config('firebase.credentials'))->createAuth();
            $token = $auth->createCustomToken($uid);
            return V4Response::success(['firebase_uid' => $uid, 'custom_token' => $token->toString()]);
        } catch (Throwable $error) {
            report($error);
            return V4Response::error('firebase_auth_unavailable', 'Firebase chat authentication is unavailable.', 503);
        }
    }

    public function firebaseLease(Request $request): JsonResponse
    {
        $userId = trim((string) $request->input('auth_user_id'));
        $roomId = trim((string) $request->input('active_room_id', ''));
        try {
            $uid = trim((string) DB::table('user')->where('num', $userId)->value('uid'));
            if ($uid === '' || preg_match('/^[A-Za-z0-9_-]+$/', $uid) !== 1) {
                return V4Response::error('chat_identity_unavailable', 'Your chat identity is unavailable.', 403);
            }
            if ($roomId !== '') {
                if (preg_match('/^[A-Za-z0-9_-]+$/', $roomId) !== 1) {
                    return V4Response::error('room_access_denied', 'This chat room is unavailable.', 403);
                }
                $database = $this->database();
                $state = $database->getReference("chat_v1/rooms/{$roomId}/state")->getValue();
                $member = $database->getReference("chat_v1/rooms/{$roomId}/members/{$uid}")->getValue();
                if (! is_array($state) || ($state['active'] ?? false) !== true || ! is_array($member) || ($member['active'] ?? false) !== true) {
                    return V4Response::error('room_access_denied', 'You no longer have access to this chat room.', 403);
                }
            }
            return V4Response::success(['room_projection_refreshed' => true, 'refresh_after_seconds' => 60]);
        } catch (Throwable $error) {
            report($error);
            return V4Response::error('chat_unavailable', 'Chat is temporarily unavailable. Please try again.', 503);
        }
    }

    public function sync(Request $request, string $roomId): JsonResponse
    {
        $userId = trim((string) $request->input('auth_user_id'));
        $cursor = max(0, (int) $request->query('cursor', 0));
        try {
            $uid = trim((string) DB::table('user')->where('num', $userId)->value('uid'));
            $database = $this->database();
            $member = $database->getReference("chat_v1/rooms/{$roomId}/members/{$uid}")->getValue();
            if (! is_array($member) || ($member['active'] ?? false) !== true) {
                return V4Response::error('room_access_denied', 'You no longer have access to this chat room.', 403);
            }
            $startKey = str_pad((string) ($cursor + 1), 20, '0', STR_PAD_LEFT);
            $events = $database->getReference("chat_v1/user_sync_events/{$uid}/{$roomId}")
                ->orderByKey()->startAt($startKey)->limitToFirst(101)->getValue() ?? [];
            $events = is_array($events) ? $events : [];
            ksort($events, SORT_STRING);
            $hasMore = count($events) > 100;
            $events = array_slice($events, 0, 100, true);
            $result = [];
            $next = $cursor;
            foreach ($events as $event) {
                if (! is_array($event)) continue;
                $eventCursor = (int) ($event['cursor'] ?? 0);
                $messageId = isset($event['message_id']) ? (string) $event['message_id'] : null;
                $item = ['kind' => $event['kind'] ?? 'sync', 'cursor' => $eventCursor, 'message_id' => $messageId];
                if (in_array($item['kind'], ['created', 'deleted'], true) && $messageId !== null) {
                    $record = $database->getReference("chat_v1/user_messages/{$uid}/{$roomId}/{$messageId}")->getValue();
                    if (is_array($record)) $item['message'] = $this->publicMessage($record);
                }
                $result[] = $item;
                $next = max($next, $eventCursor);
            }
            return V4Response::success([
                'cursor_expired' => false,
                'next_cursor' => $next,
                'has_more' => $hasMore,
                'events' => $result,
            ]);
        } catch (Throwable $error) {
            report($error);
            return V4Response::error('chat_unavailable', 'Chat is temporarily unavailable. Please try again.', 503);
        }
    }

    public function history(Request $request, string $roomId): JsonResponse
    {
        $userId = trim((string) $request->input('auth_user_id'));
        try {
            $uid = trim((string) DB::table('user')->where('num', $userId)->value('uid'));
            $database = $this->database();
            $member = $database->getReference("chat_v1/rooms/{$roomId}/members/{$uid}")->getValue();
            if (! is_array($member) || ($member['active'] ?? false) !== true) {
                return V4Response::error('room_access_denied', 'You no longer have access to this chat room.', 403);
            }
            $reference = $database->getReference("chat_v1/user_messages/{$uid}/{$roomId}")->orderByChild('cursor');
            $limit = 31;
            if ($request->filled('before')) {
                $items = $reference->endAt(max(0, (int) $request->query('before') - 1))->limitToLast($limit)->getValue() ?? [];
            } elseif ($request->filled('after')) {
                $items = $reference->startAt((int) $request->query('after') + 1)->limitToFirst($limit)->getValue() ?? [];
            } else {
                $items = $reference->limitToLast($limit)->getValue() ?? [];
            }
            $items = is_array($items) ? array_values($items) : [];
            usort($items, fn ($a, $b) => (int) ($a['cursor'] ?? 0) <=> (int) ($b['cursor'] ?? 0));
            $hasMore = count($items) > 30;
            if ($hasMore) {
                if ($request->filled('after')) array_pop($items); else array_shift($items);
            }
            return V4Response::success([
                'messages' => array_map(fn ($item) => $this->publicMessage($item), $items),
                'has_more' => $hasMore,
                'first_unread_id' => null,
                'initial_after' => null,
            ]);
        } catch (Throwable $error) {
            report($error);
            return V4Response::error('chat_unavailable', 'Chat is temporarily unavailable. Please try again.', 503);
        }
    }

    public function store(Request $request, string $roomId): JsonResponse
    {
        $payload = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'request_id' => ['required', 'uuid'],
            'spoiler' => ['sometimes', 'boolean'],
            'reply_to_id' => ['nullable', 'string', 'max:80'],
            'movie_suggestion' => ['nullable', 'array'],
            'movie_suggestion.id' => ['required_with:movie_suggestion', 'string', 'max:80'],
            'room_invite' => ['nullable', 'array'],
            'room_invite.room_id' => ['required_with:room_invite', 'string', 'max:80'],
            'moment' => ['nullable', 'array'],
            'moment.seconds' => ['required_with:moment', 'integer', 'min:0', 'max:86400'],
            'moment.content_type' => ['required_with:moment', 'string', 'max:32'],
            'moment.content_id' => ['required_with:moment', 'string', 'max:80'],
            'watch' => ['nullable', 'array'],
            'playback_session_id' => ['nullable', 'string', 'max:100'],
            'app_session_id' => ['nullable', 'uuid'],
        ]);

        $userId = trim((string) $request->input('auth_user_id'));
        $sessionId = $request->attributes->get('auth_session_token_id');
        if ($userId === '' || ! $sessionId) {
            return V4Response::error('unauthenticated', 'Your sign-in session is no longer valid.', 401);
        }

        try {
            $database = $this->database();
            $roomState = $database->getReference("chat_v1/rooms/{$roomId}/state")->getValue();
            if (! is_array($roomState) || ($roomState['active'] ?? false) !== true) {
                return V4Response::error('room_unavailable', 'This chat room is unavailable.', 404);
            }

            $userRecord = DB::table('user')->where('num', $userId)->first(['uid', 'name']);
            $firebaseUid = trim((string) ($userRecord->uid ?? ''));
            if ($firebaseUid === '' || preg_match('/^[A-Za-z0-9_-]+$/', $firebaseUid) !== 1) {
                return V4Response::error('chat_identity_unavailable', 'Your chat identity is unavailable.', 403);
            }

            // Membership is server-owned data. Client supplied user ids and
            // room permissions are deliberately ignored.
            $members = $database->getReference("chat_v1/rooms/{$roomId}/members")->getValue();
            $members = is_array($members) ? $members : [];
            $membership = $members[$firebaseUid] ?? null;
            if (! is_array($membership) || ($membership['active'] ?? false) !== true) {
                return V4Response::error('room_access_denied', 'You are not a member of this chat room.', 403);
            }
            if (($membership['can_send'] ?? false) !== true || ($roomState['can_send'] ?? false) !== true) {
                return V4Response::error('send_not_allowed', 'You do not have permission to send messages in this room.', 403);
            }

            $requestId = strtolower($payload['request_id']);
            $requestRef = $database->getReference("chat_v1/room_requests/{$roomId}/{$userId}/{$requestId}");
            $existingId = $requestRef->getValue();
            if (is_string($existingId) && $existingId !== '') {
                $existing = $database->getReference("chat_v1/rooms/{$roomId}/messages/{$existingId}")->getValue();
                if (is_array($existing)) {
                    return V4Response::success($this->publicMessage($existing), 'Message already sent.');
                }
            }

            $now = now()->utc();
            // IDs are sortable numeric cursors consumed by the existing mobile
            // Firebase stream. The random suffix prevents same-millisecond IDs.
            $messageId = (string) ($now->valueOf() * 1000 + random_int(0, 999));
            $displayName = trim((string) ($userRecord->name ?? '')) ?: 'Viewer';
            $record = [
                'id' => $messageId,
                'room_id' => $roomId,
                'sender_id' => $userId,
                'sender_name' => $displayName,
                'sender_is_admin' => false,
                'client_op_id' => $requestId,
                'body' => trim($payload['body']),
                'spoiler' => (bool) ($payload['spoiler'] ?? false),
                'deleted' => false,
                'reply_to_id' => $payload['reply_to_id'] ?? null,
                'suggestion' => $payload['movie_suggestion'] ?? null,
                'invitation' => $payload['room_invite'] ?? null,
                'moment' => $payload['moment'] ?? null,
                'watch' => $payload['watch'] ?? null,
                'created_at_iso' => $now->toIso8601String(),
                'created_at' => $now->toIso8601String(),
                'cursor' => (int) $messageId,
            ];

            $updates = [
                "chat_v1/rooms/{$roomId}/messages/{$messageId}" => $record,
                "chat_v1/room_requests/{$roomId}/{$userId}/{$requestId}" => $messageId,
                "chat_v1/rooms/{$roomId}/last_message_id" => $messageId,
                "chat_v1/rooms/{$roomId}/state/sync_cursor" => (int) $messageId,
            ];
            foreach ($members as $memberId => $member) {
                if (preg_match('/^[A-Za-z0-9_-]+$/', (string) $memberId) !== 1) {
                    continue;
                }
                if (! is_array($member) || ($member['active'] ?? false) !== true) {
                    continue;
                }
                $updates["chat_v1/user_messages/{$memberId}/{$roomId}/{$messageId}"] = $record;
                $updates["chat_v1/user_sync_events/{$memberId}/{$roomId}/".str_pad($messageId, 20, '0', STR_PAD_LEFT)] = [
                    'kind' => 'created', 'cursor' => (int) $messageId, 'message_id' => $messageId,
                ];
            }

            // A single multi-location update keeps the room transcript and
            // participant feeds consistent if Firebase accepts the write.
            $database->getReference()->update($updates);

            return V4Response::success($this->publicMessage($record), 'Message sent.', status: 201);
        } catch (Throwable $error) {
            report($error);

            return V4Response::error('chat_unavailable', 'Chat is temporarily unavailable. Please try again.', 503);
        }
    }

    private function publicMessage(array $record): array
    {
        return [
            'id' => (string) $record['id'],
            'room_id' => (string) $record['room_id'],
            'user_id' => (string) $record['sender_id'],
            'request_id' => (string) $record['client_op_id'],
            'display_name' => (string) $record['sender_name'],
            'is_admin' => (bool) ($record['sender_is_admin'] ?? false),
            'body' => (string) $record['body'],
            'spoiler' => (bool) ($record['spoiler'] ?? false),
            'deleted' => (bool) ($record['deleted'] ?? false),
            'created_at' => (string) ($record['created_at'] ?? $record['created_at_iso']),
            'movie_suggestion' => $record['suggestion'] ?? null,
            'room_invite' => $record['invitation'] ?? null,
            'watch_invite' => $record['watch_invite'] ?? null,
            'reply_to_id' => $record['reply_to_id'] ?? null,
            'reply' => null,
            'reactions' => [],
            'my_reactions' => [],
            'moment' => $record['moment'] ?? null,
        ];
    }

    private function database(): Database
    {
        $url = (string) config('firebase.database_url', '');
        if ($url === '') {
            throw new \RuntimeException('Firebase Realtime Database is not configured.');
        }

        return (new Factory)
            ->withServiceAccount((string) config('firebase.credentials'))
            ->withDatabaseUri($url)
            ->createDatabase();
    }
}
