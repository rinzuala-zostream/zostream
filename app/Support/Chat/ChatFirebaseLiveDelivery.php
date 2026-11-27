<?php

namespace App\Support\Chat;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Publishes only SQL-accepted state into recipient-scoped RTDB paths. */
final class ChatFirebaseLiveDelivery
{
    public function __construct(private readonly ChatFirebase $firebase, private readonly ChatRecipientAccess $access) {}

    public function deliver(object $outbox): void
    {
        $startedAt = microtime(true);
        if (! config('chat.firebase.enabled')) { return; }
        $roomId = (int) $outbox->room_id;
        $db = ChatDatabase::connection();
        $room = $roomId > 0 ? $db->table('chat_rooms')->find($roomId) : null;
        if ($outbox->kind === 'ended') {
            app(ChatFirebaseAccountEvents::class)->publish($outbox, $room);
            $this->firebase->revokeRoomAuthorization($roomId);
            return;
        }
        if (in_array($outbox->kind, ['badge_sync', 'identity'], true)) {
            app(ChatFirebaseAccountEvents::class)->publish($outbox, $room);
            return;
        }
        if (! in_array($outbox->kind, ['feed_created', 'feed_deleted', 'sync', 'reactions', 'read', 'room_invite_status'], true)) {
            return;
        }
        if (! $room) { return; }
        $message = in_array($outbox->kind, ['feed_created', 'feed_deleted', 'reactions', 'sync'], true) && $outbox->message_id
            ? $db->table('chat_messages')->where('room_id', $roomId)->where('id', $outbox->message_id)->first()
            : null;
        if (in_array($outbox->kind, ['feed_created', 'feed_deleted', 'reactions'], true) && ! $message) { return; }
        if ($message && Carbon::parse($message->created_at)->lt(now()->subDays(config('chat.retention_days')))) { return; }
        // Pins and reactions affect the open room only. Account listeners
        // refresh unread/discovery state for messages, reads and room changes.
        if ($outbox->kind !== 'reactions' && ! ($outbox->kind === 'sync' && $outbox->message_id)) {
            app(ChatFirebaseAccountEvents::class)->publish($outbox, $room, $message);
        }
        if ($room->closed) { return; }

        $authorization = $this->firebase->roomAuthorizations($roomId);
        $nowMs = now()->getTimestampMs();
        $sessionIds = [];
        foreach ($authorization as $firebaseUid => $sessions) {
            foreach (is_array($sessions) ? $sessions : [] as $sessionId => $grant) {
                if (! is_array($grant) || ($grant['can_read'] ?? false) !== true || (int) ($grant['read_until'] ?? 0) <= $nowMs) { continue; }
                $sessionIds[] = (string) $sessionId;
            }
        }
        if ($sessionIds === []) { return; }
        $sessionRows = collect();
        foreach (array_chunk(array_values(array_unique($sessionIds)), 500) as $ids) {
            $sessionRows = $sessionRows->merge(DB::table('session_tokens')->whereIn('id', $ids)
                ->where('access_expires_at', '>', now())->get());
        }
        $sessionRows = $sessionRows->keyBy('id');
        $viewers = [];
        foreach ($authorization as $firebaseUid => $sessions) {
            foreach (is_array($sessions) ? $sessions : [] as $sessionId => $grant) {
                $session = $sessionRows->get($sessionId);
                if (! $session || ChatFirebase::uid((string) $session->user_id) !== $firebaseUid
                    || ! is_array($grant) || ($grant['can_read'] ?? false) !== true || (int) ($grant['read_until'] ?? 0) <= $nowMs) { continue; }
                $viewers[] = [
                    'user_id' => (string) $session->user_id,
                    'device_id' => (string) $session->device_id,
                    'session_id' => (string) $session->id,
                    'session_hash' => hash('sha256', $session->access_token),
                    'playback_hash' => (string) ($grant['playback_hash'] ?? ''),
                ];
            }
        }
        $eligible = iterator_to_array($this->access->eligibleViewers($room, $viewers, $sessionRows), false);
        if ($eligible === []) { return; }

        $blocked = [];
        if ($message) {
            $blocked = $db->table('chat_blocks')->where('blocked_user_id', $message->user_id)->pluck('user_id')->flip()->all();
        }
        $eligibleByUid = [];
        foreach ($eligible as $viewer) {
            if (isset($blocked[$viewer['user_id']])) { continue; }
            $eligibleByUid[$viewer['user_id']] = ChatFirebase::uid($viewer['user_id']);
        }
        if ($outbox->recipient_user_id ?? null) {
            $eligibleByUid = array_intersect_key($eligibleByUid, [(string) $outbox->recipient_user_id => true]);
        }
        if ($eligibleByUid === []) { return; }

        $cursor = (int) ($outbox->firebase_cursor ?? 0);
        if ($cursor < 1) { throw new \RuntimeException('Firebase chat outbox cursor is missing'); }
        $eventKey = str_pad((string) $cursor, 20, '0', STR_PAD_LEFT);
        $eventKind = match ($outbox->kind) {
            'feed_deleted' => 'deleted',
            'feed_created' => ($message?->deleted_at ? 'deleted' : 'created'),
            default => $outbox->kind,
        };
        $revision = (int) ($message->revision ?? 0);
        $event = ['cursor' => $cursor, 'kind' => $eventKind,
            'message_id' => $outbox->message_id ? (string) $outbox->message_id : null,
            'revision' => $revision, 'server_at' => $nowMs];
        if ($eventKind === 'created' && $message) {
            $event['overlay_live'] = Carbon::parse($message->created_at)->gte(now()->subSeconds(10));
        }
        if ($outbox->kind === 'read' && ($outbox->recipient_user_id ?? null) !== null) {
            $event['read_state'] = app(ChatReadService::class)->readState((string) $outbox->recipient_user_id, $roomId);
        }
        $readThrough = [];
        if ($message && in_array($outbox->kind, ['feed_created', 'feed_deleted'], true)) {
            foreach (array_chunk(array_keys($eligibleByUid), 500) as $ids) {
                foreach ($db->table('chat_room_reads')->where('room_id', $roomId)->whereIn('user_id', $ids)->get(['user_id', 'last_read_message_id']) as $read) {
                    $readThrough[(string) $read->user_id] = (int) $read->last_read_message_id;
                }
            }
        }
        // Pin and reaction events invalidate the SQL view without copying old
        // message bodies into the new Firebase namespace.
        $record = $message && in_array($outbox->kind, ['feed_created', 'feed_deleted'], true)
            ? $this->record($message, $roomId, $cursor, isset($outbox->sender_is_admin) ? (bool) $outbox->sender_is_admin : null) : null;
        $updates = [];
        foreach ($eligibleByUid as $userId => $firebaseUid) {
            if ($record !== null) {
                $messageKey = str_pad((string) $message->id, 20, '0', STR_PAD_LEFT);
                $updates["user_messages/$firebaseUid/$roomId/$messageKey"] = $record;
            }
            $userEvent = $event;
            if ($message && in_array($outbox->kind, ['feed_created', 'feed_deleted'], true)
                && (string) $message->user_id !== (string) $userId
                && (int) $message->id > ($readThrough[(string) $userId] ?? 0)) {
                $userEvent['unread_delta'] = $outbox->kind === 'feed_created' && $message->deleted_at === null ? 1 : -1;
            }
            $updates["user_sync_events/$firebaseUid/$roomId/$eventKey"] = $userEvent;
        }

        $recipientCount = count($eligibleByUid);
        $payloadBytes = strlen(json_encode($updates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $batchCount = 0;
        foreach (array_chunk($updates, 200, true) as $chunk) {
            // Keep each recipient's data and event in the same atomic update.
            $perUser = [];
            foreach ($chunk as $path => $value) {
                $perUser[$path] = $value;
                $prefix = explode('/', $path, 3);
                if (isset($prefix[2])) {
                    $uid = $prefix[1];
                    $counterpart = str_starts_with($path, 'user_messages/')
                        ? "user_sync_events/$uid/$roomId/$eventKey"
                        : ($record !== null ? 'user_messages/'.$uid.'/'.$roomId.'/'.str_pad((string) $message->id, 20, '0', STR_PAD_LEFT) : null);
                    if ($counterpart !== null && array_key_exists($counterpart, $updates)) { $perUser[$counterpart] = $updates[$counterpart]; }
                }
            }
            $this->firebase->publishUserFeedBatch($perUser);
            $batchCount++;
            $eventRows = [];
            foreach (array_keys($perUser) as $path) {
                if (preg_match('#^user_sync_events/([^/]+)/#', $path, $matches)) {
                    $eventRows[$matches[1]] = ['firebase_uid' => $matches[1], 'room_id' => $roomId,
                        'cursor' => $cursor, 'published_at' => now()];
                }
            }
            if ($eventRows !== []) {
                $db->table('chat_firebase_event_recipients')->insertOrIgnore(array_values($eventRows));
            }
            if ($record !== null) {
                $deliveryRows = [];
                foreach (array_keys($perUser) as $path) {
                    if (preg_match('#^user_messages/([^/]+)/([0-9]+)/#', $path, $matches)) {
                        $deliveryRows[$matches[1]] = ['firebase_uid' => $matches[1], 'room_id' => $roomId,
                            'message_id' => (int) $message->id, 'published_at' => now()];
                    }
                }
                if ($deliveryRows !== []) {
                    $db->table('chat_firebase_message_recipients')->insertOrIgnore(array_values($deliveryRows));
                }
            }
        }
        Log::info('Firebase chat feed batch published', [
            'room_id' => $roomId, 'cursor' => $cursor, 'outbox_kind' => $outbox->kind,
            'recipient_count' => $recipientCount, 'payload_bytes' => $payloadBytes,
            'changed_path_count' => count($updates), 'admin_batch_count' => $batchCount,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    private function record(object $message, int $roomId, int $cursor, ?bool $senderIsAdmin): array
    {
        $deleted = $message->deleted_at !== null;
        $api = app(ChatService::class)->message($message);
        $overrides = $senderIsAdmin === null ? [] : [$message->user_id => $senderIsAdmin];
        $api = ChatDisplayNames::messages([$api], $overrides)[0];
        $suggestion = $api['movie_suggestion'];
        $invitation = $api['room_invite'];
        $watch = $api['watch_invite'] === null ? null : app(PrivateWatchService::class)->hydrate([$api])[0]['watch_invite'];
        $type = $invitation !== null ? 'room_invitation' : ($watch !== null ? 'watch_invitation' : ($suggestion !== null ? 'movie_suggestion' : 'text'));

        return [
            'id' => (string) $message->id,
            'room_id' => (string) $roomId,
            'message_type' => $type,
            'sender_uid' => ChatFirebase::uid((string) $message->user_id),
            'sender_id' => $api['user_id'],
            'sender_name' => $api['display_name'],
            'sender_is_admin' => $api['is_admin'],
            'body' => $deleted ? null : (string) $message->body,
            'spoiler' => (bool) $message->spoiler,
            'reply_to' => $deleted || ! $message->reply_to_id ? null : (string) $message->reply_to_id,
            'client_op_id' => (string) $message->request_id,
            'created_at' => Carbon::parse($message->created_at)->getTimestampMs(),
            'created_at_iso' => $api['created_at'],
            'revision' => (int) ($message->revision ?? 1),
            'deleted' => $deleted,
            'deleted_at' => $deleted ? Carbon::parse($message->deleted_at)->getTimestampMs() : null,
            'suggestion' => $suggestion,
            'invitation' => $invitation,
            'watch_invite' => $watch,
            'moment' => $api['moment'],
            'pinned' => ChatDatabase::connection()->table('chat_pins')->where('room_id', $roomId)->where('message_id', $message->id)->exists(),
            'cursor' => $cursor,
        ];
    }
}
