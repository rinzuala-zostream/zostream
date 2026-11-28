<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\SessionTokenModel;
use App\Services\Chat\ChatService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}

    public function handle(Request $r): JsonResponse
    {
        try {
            $uid = (string) $r->input('auth_user_id');
            $actor = $this->chat->user($uid);
            $room = (string) $r->route('roomId');
            $id = (string) $r->route('messageId');
            $action = $r->route('chatAction');
            $data = $this->execute($action, $r, $actor, $room, $id);
            if (array_key_exists('firebase_realtime', $data)) {
                $data['firebase_realtime'] = $r->header('X-Chat-Protocol') === 'rtdb-2';
            }

            return V4Response::success($data, status: $action === 'send' ? 201 : 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            return V4Response::error('chat_request_denied', $e->getMessage() ?: 'This chat request is unavailable.', $e->getStatusCode());
        } catch (Throwable $e) {
            report($e);

            return V4Response::error('chat_unavailable', 'Chat is temporarily unavailable. Please try again.', 503);
        }
    }

    private function execute(string $action, Request $r, array $actor, string $room, string $id): array
    {
        $uid = $actor['uid'];
        switch ($action) {
            case 'join':
                $p = $r->validate(['type' => 'required|in:global,movie,episode', 'content_id' => 'nullable|string|max:80']);
                if ($p['type'] !== 'global') {
                    abort_unless(ctype_digit($p['content_id'] ?? ''), 422, 'Choose a title.');
                }

                return $this->chat->join($actor, $p['type'], $p['type'] === 'global' ? null : $p['content_id']);
            case 'send':
                return $this->chat->send($actor, $room, $r->validate([
                    'body' => 'required|string|max:1000', 'request_id' => 'required|uuid', 'spoiler' => 'sometimes|boolean',
                    'reply_to_id' => ['nullable', 'string', 'regex:/^[0-9]{1,19}$/D'],
                    'movie_suggestion' => 'nullable|array', 'movie_suggestion.id' => 'required_with:movie_suggestion|string|max:80',
                    'room_invite' => 'nullable|array', 'room_invite.room_id' => ['required_with:room_invite', 'string', 'regex:/^[0-9]{1,19}$/D'],
                    'moment' => 'nullable|array', 'moment.seconds' => 'required_with:moment|integer|min:0|max:86400',
                    'moment.content_type' => 'required_with:moment|in:movie,episode', 'moment.content_id' => 'required_with:moment|string|max:80',
                    'isChildMode' => 'sometimes|boolean', 'age_restriction' => 'sometimes|boolean',
                ]));
            case 'history':
            case 'sync':
                $p = $r->validate(['cursor' => 'nullable|integer|min:0|max:9007199254740991', 'before' => 'nullable|integer|min:0|max:9007199254740991', 'after' => 'nullable|integer|min:0|max:9007199254740991', 'limit' => 'nullable|integer|min:1|max:30']);

                return $action === 'sync' ? $this->chat->sync($room, $uid, (int) ($p['cursor'] ?? 0))
                    : $this->chat->history($room, $uid, isset($p['before']) ? (int) $p['before'] : null, isset($p['after']) ? (int) $p['after'] : null, $r->boolean('initial'), (int) ($p['limit'] ?? 30));
            case 'message':
                $this->chat->room($room, $uid);
                $m = $this->chat->message($room, $id);
                abort_if($this->chat->blocked($uid, $m['sender_id']), 404);

                return $this->chat->publicMessage($m, $uid);
            case 'token':
            case 'lease':
                return $this->firebase($r, $actor, $action === 'token');
            case 'unread': return $this->chat->unread($uid);
            case 'read': return $this->chat->read($room, $uid, $r->validate(['message_id' => ['required', 'string', 'regex:/^[0-9]{1,19}$/D']])['message_id']);
            case 'react':
                $p = $r->validate(['emoji' => 'required|in:😂,❤️,🔥,😢,👏,😮', 'active' => 'required|boolean']);

                return $this->chat->react($actor, $room, $id, $p['emoji'], $p['active']);
            case 'pins': return $this->chat->pins($room, $uid);
            case 'pin':
            case 'unpin': return $this->chat->pin($actor, $room, $id, $action === 'pin');
            case 'report':
                $this->chat->room($room, $uid);
                $this->chat->message($room, $id);
                $p = $r->validate(['reason' => 'required|string|max:500']);
                $this->chat->store->update(["reports/$room/$id/$uid" => ['reason' => trim($p['reason']), 'created_at' => now()->utc()->toIso8601String()]]);

                return ['reported' => true];
            case 'blocks':
                if ($r->isMethod('get')) {
                    return $this->chat->blocks($uid);
                }
                $p = $r->validate(['user_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D'], 'blocked' => 'required|boolean']);

                return $this->chat->block($uid, $p['user_id'], $p['blocked']);
            case 'rooms':
                if ($r->isMethod('get')) {
                    return $this->chat->privateRooms($uid, $r->validate(['recipient_user_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D']])['recipient_user_id'] ?? null);
                }
                $p = $r->validate(['type' => 'required|in:global', 'room_name' => 'required|string|max:80', 'content_id' => 'prohibited', 'invitee_ids' => 'required|array|min:1|max:20', 'invitee_ids.*' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D']]);

                return $this->chat->createRoom($actor, $p);
            case 'private-join':
                return $this->chat->locked($room, function () use ($room, $uid) {
                    $this->chat->room($room, $uid);

                    return $this->chat->descriptor($room, $uid);
                });
            case 'invitees':
                return $this->chat->invitePeople($actor, $room, $r->validate(['invitee_ids' => 'required|array|min:1|max:20', 'invitee_ids.*' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D']])['invitee_ids']);
            case 'lookup': return $this->lookup($r, $uid);
            case 'invites': return $this->chat->invitations($uid);
            case 'respond': return $this->chat->invitation($uid, (string) $r->route('inviteId'), (bool) $r->validate(['accept' => 'required|boolean'])['accept']);
            case 'invitation-link': return $this->chat->invitation($uid, (string) $r->route('token'), null, true);
            case 'link-respond': return $this->chat->invitation($uid, (string) $r->route('token'), (bool) $r->validate(['accept' => 'required|boolean'])['accept'], true);
            case 'claim': return $this->chat->claimInvitation($actor, $room, $id);
            case 'end': return $this->chat->endRoom($actor, $room);
            case 'invite-status': return $this->inviteStatus($r, $room, $uid);
            case 'movies': return $this->movies($r, $room, $uid);
            case 'heartbeat': return $this->heartbeat($r, $room, $actor);
            case 'typing': return $this->typing($r, $room, $actor);
        }
        abort(404);
    }

    private function firebase(Request $r, array $actor, bool $token): array
    {
        $session = SessionTokenModel::find($r->attributes->get('auth_session_token_id'));
        abort_unless($session && $session->user_id === $actor['uid'] && $session->access_expires_at->isFuture(), 401);
        $key = hash('sha256', (string) $session->getKey().':'.$session->device_id);
        $uid = $actor['uid'];
        $room = $r->validate(['active_room_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,80}$/D']])['active_room_id'] ?? null;
        $granted = true;
        if ($room) {
            try {
                $this->chat->room($room, $uid);
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404, 410])) {
                    throw $e;
                }
                $granted = false;
            }
        }
        $this->chat->store->update(["access/$uid/$key" => ['expires_at' => min(now()->addSeconds(90)->valueOf(), $session->access_expires_at->valueOf())]]);
        $result = ['room_projection_refreshed' => $granted, 'refresh_after_seconds' => 30];
        if ($token) {
            $result += ['firebase_uid' => $uid, 'display_name' => $actor['name'], 'custom_token' => $this->chat->store->customToken($uid, ['chat_session' => $key, 'chat_name' => $actor['name']])];
        }

        return $result;
    }

    private function lookup(Request $r, string $uid): array
    {
        $phone = preg_replace('/\D/', '', $r->validate(['phone' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,24}$/D']])['phone']);
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }
        abort_unless(strlen($phone) >= 7 && strlen($phone) <= 15, 422, 'Enter a valid phone number.');
        // Contacts may include a prefix; accounts store national numbers and
        // country_code separately. Never guess a country from a suffix alone.
        $users = DB::table('user')->where(function ($q) use ($phone) {
            $q->whereIn('auth_phone', [$phone, '+'.$phone]);
            for ($length = 1; $length <= 4 && strlen($phone) - $length >= 7; $length++) {
                $prefix = substr($phone, 0, $length);
                $national = substr($phone, $length);
                $q->orWhere(fn ($q) => $q->whereIn('auth_phone', [$national, '+'.$national])->whereIn('country_code', [$prefix, '+'.$prefix]));
            }
        })->limit(2)->get(['uid', 'name']);
        abort_unless($users->count() === 1, 404, 'This Zo Stream account is unavailable.');
        $user = $users->first();
        abort_if($user->uid === $uid || $this->chat->blocked($uid, $user->uid) || $this->chat->blocked($user->uid, $uid), 403, 'This person cannot be invited.');

        return ['user_id' => $user->uid, 'name' => $user->name];
    }

    private function movies(Request $r, string $room, string $uid): array
    {
        $this->chat->room($room, $uid);
        $p = $r->validate(['q' => 'required|string|min:3|max:80', 'isChildMode' => 'nullable|boolean', 'age_restriction' => 'nullable|boolean']);
        $q = DB::table('movie')->where('isEnable', true)->where('status', 'Published')->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $p['q']).'%');
        if ($r->boolean('isChildMode')) {
            $q->where('isChildMode', true);
        }
        if (! $r->boolean('age_restriction')) {
            $q->where('isAgeRestricted', false);
        }

        return ['movies' => $q->orderBy('title')->limit(6)->get(['id', 'title', 'poster'])->map(fn ($m) => ['id' => (string) $m->id, 'title' => $m->title, 'poster' => $m->poster])->all()];
    }

    private function heartbeat(Request $r, string $room, array $actor): array
    {
        $uid = $actor['uid'];
        $this->chat->room($room, $uid);
        $p = $r->validate(['visible' => 'sometimes|boolean', 'visibility_id' => 'nullable|string|max:80', 'visibility_sequence' => 'nullable|integer|min:0']);
        $key = hash('sha256', (string) $r->attributes->get('auth_session_token_id').':'.($p['visibility_id'] ?? 'default'));
        $path = "room_presence/$room/$uid/$key";
        $old = $this->chat->store->get($path);
        if (($p['visibility_sequence'] ?? 0) >= ($old['sequence'] ?? 0)) {
            $this->chat->store->update([$path => ['expires_at' => ($p['visible'] ?? true) ? now()->addSeconds(90)->valueOf() : 0, 'sequence' => $p['visibility_sequence'] ?? 0]]);
        }
        $count = 0;
        foreach ($this->chat->store->get("room_presence/$room") ?? [] as $sessions) {
            if (collect($sessions)->contains(fn ($s) => ($s['expires_at'] ?? 0) > now()->valueOf())) {
                $count++;
            }
        }

        return ['online_count' => $count];
    }

    private function typing(Request $r, string $room, array $actor): array
    {
        $uid = $actor['uid'];
        $this->chat->room($room, $uid, $r->isMethod('post'));
        if ($r->isMethod('post')) {
            $p = $r->validate(['active' => 'required|boolean', 'typing_session_id' => 'required|uuid', 'typing_sequence' => 'required|integer|min:0']);
            $path = "room_typing/$room/".$uid.'_'.$p['typing_session_id'];
            $old = $this->chat->store->get($path);
            if ($p['typing_sequence'] >= ($old['sequence'] ?? 0)) {
                $this->chat->store->update([$path => ['user_id' => $uid, 'name' => $actor['name'], 'sequence' => $p['typing_sequence'], 'expires_at' => $p['active'] ? now()->addSeconds(6)->valueOf() : 0]]);
            }
        }
        $users = [];
        foreach ($this->chat->store->get("room_typing/$room") ?? [] as $entry) {
            if (($entry['expires_at'] ?? 0) <= now()->valueOf() || $entry['user_id'] === $uid || $this->chat->blocked($uid, $entry['user_id'])) {
                continue;
            }
            $users[$entry['user_id']] = ['user_id' => $entry['user_id'], 'name' => $entry['name'], 'is_admin' => false, 'expires_in' => (int) ceil(($entry['expires_at'] - now()->valueOf()) / 1000)];
        }

        return ['users' => array_values($users)];
    }

    private function inviteStatus(Request $r, string $room, string $uid): array
    {
        $this->chat->room($room, $uid);
        $p = $r->validate(['room_ids' => 'required|array|max:200', 'room_ids.*' => ['string', 'regex:/^[0-9]{1,19}$/D']]);
        $shared = [];
        foreach ($this->chat->store->get("rooms/$room/messages") ?? [] as $m) {
            if (! ($m['deleted'] ?? false) && ! $this->chat->blocked($uid, $m['sender_id']) && isset($m['invitation'])) {
                $shared[$m['invitation']['room_id']] = $m['invitation'];
            }
        }
        $result = [];
        foreach ($p['room_ids'] as $id) {
            abort_unless(isset($shared[$id]), 403);
            $active = true;
            try {
                $this->chat->room($id);
            } catch (HttpExceptionInterface $e) {
                if (in_array($e->getStatusCode(), [404, 410])) {
                    $active = false;
                } else {
                    throw $e;
                }
            }
            $result[] = array_merge($shared[$id], ['active' => $active]);
        }

        return ['rooms' => $result];
    }
}
