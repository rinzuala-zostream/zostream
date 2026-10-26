<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use App\Support\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

final class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function unread(Request $r, \App\Support\Chat\ChatReadService $reads)
    {
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        $v = $r->validate(['type' => ['sometimes', Rule::in(['global', 'movie', 'episode'])], 'content_id' => 'nullable|string|max:150']);
        return V4Response::success($reads->summary($r, $v['type'] ?? 'global', $v['content_id'] ?? null));
    }

    public function markRead(Request $r, int $room, \App\Support\Chat\ChatReadService $reads)
    {
        $v = $r->validate(['message_id' => 'required|integer|min:1']);
        return V4Response::success($reads->mark($r, $room, (int) $v['message_id']));
    }

    public function join(Request $r)
    {
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        $v = $r->validate(['type' => ['required', Rule::in(['global', 'movie', 'episode'])],
            'content_id' => 'required_unless:type,global|nullable|string|max:150']);
        if ($v['type'] !== 'global') {
            abort_unless($this->chat->playbackAllowed($r, $v['type'], $v['content_id']), 403, 'Start online playback to join this movie chat.');
        }
        $key = $v['type'] === 'global' ? 'global' : $v['type'].':'.$v['content_id'];
        DB::table('chat_rooms')->insertOrIgnore(['room_key' => $key, 'type' => $v['type'],
            'content_id' => $v['type'] === 'global' ? null : $v['content_id'], 'created_at' => now(), 'updated_at' => now()]);
        $room = DB::table('chat_rooms')->where('room_key', $key)->first();
        $this->chat->room($r, $room->id);

        return V4Response::success(['room_id' => (string) $room->id, 'room_type' => $room->type,
            'channel' => 'private-chat.'.$room->id, 'websocket_url' => config('chat.websocket_url'),
            'online_count' => $this->chat->presence($r, $room->id),
            'user_id' => (string) $r->input('auth_user_id'), 'slow_mode_seconds' => config('chat.slow_mode_seconds')]);
    }

    public function lookupInvitee(Request $r)
    {
        $v = $r->validate(['phone' => 'required|string|min:7|max:32']);
        $digits = preg_replace('/\D+/', '', $v['phone']);
        abort_if(strlen($digits) < 7, 422, 'Enter a valid phone number.');
        $user = DB::table('user')->where('uid', '!=', (string) $r->input('auth_user_id'))
            ->whereNotNull('auth_phone')->where('auth_phone', '!=', '')
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(auth_phone, '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?", ['%'.$digits])
            ->first(['uid', 'name']);
        abort_unless($user, 404, 'No Zo Stream user found for this phone number.');

        return V4Response::success(['user_id' => (string) $user->uid, 'name' => $user->name ?: 'Viewer']);
    }

    public function createInviteRoom(Request $r)
    {
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        $v = $r->validate([
            'type' => ['required', Rule::in(['global', 'movie', 'episode'])],
            'room_name' => ['required', 'string', 'min:1', 'max:60', 'regex:/\\S/'],
            'content_id' => 'required_unless:type,global|nullable|string|max:150',
            'watch_position_ms' => 'nullable|integer|min:0|max:86400000',
            'invitee_ids' => 'required|array|min:1|max:20',
            'invitee_ids.*' => 'required|string|max:191|distinct',
        ]);
        $host = (string) $r->input('auth_user_id');
        $type = $v['type'];
        if ($type === 'global') {
            $v['content_id'] = null;
        }
        $content = null;
        if ($type !== 'global') {
            abort_unless($this->chat->playbackAllowed($r, $type, $v['content_id']), 403, 'Start online playback before creating a movie invite room.');
            $table = $type === 'episode' ? 'episodes' : 'movie';
            $content = DB::table($table)->where('num', $v['content_id'])->first();
            abort_unless($content, 404, 'This content is unavailable.');
            abort_if((bool) ($content->isPayPerView ?? false), 403, 'Invite rooms are unavailable for PPV content.');
        }
        $invitees = DB::table('user')->whereIn('uid', $v['invitee_ids'])->where('uid', '!=', $host)->get(['uid', 'name', 'token'])->keyBy('uid');
        abort_if($invitees->count() !== count($v['invitee_ids']), 422, 'One or more invited users are unavailable.');
        if ($content && (bool) ($content->isPremium ?? false)) {
            $eligible = DB::table('n_subscriptions')->whereIn('user_id', $invitees->keys())
                ->where('is_active', true)->where('end_at', '>=', now()->startOfDay())->distinct()->pluck('user_id')->map(fn ($id) => (string) $id)->all();
            abort_unless(count($eligible) === $invitees->count(), 403, 'Every invited user needs an active subscription for this title.');
        }

        $roomId = DB::transaction(function () use ($host, $type, $v, $invitees, $content): int {
            $now = now();
            $key = 'invite:'.Str::uuid();
            $room = DB::table('chat_rooms')->insertGetId([
                'room_key' => $key, 'type' => 'invite', 'content_id' => $v['content_id'] ?? null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('chat_invite_rooms')->insert([
                'room_id' => $room, 'host_user_id' => $host,
                'room_name' => trim($v['room_name']),
                'content_type' => $type === 'global' ? null : $type,
                'content_id' => $type === 'global' ? null : $v['content_id'],
                'watch_position_ms' => (int) ($v['watch_position_ms'] ?? 0),
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
                'idle_expires_at' => $type === 'global' ? $now->copy()->addHour() : null,
            ]);
            foreach ($invitees as $invitee) {
                DB::table('chat_room_invites')->insert([
                    'room_id' => $room, 'inviter_user_id' => $host, 'invitee_user_id' => $invitee->uid,
                    'status' => 'pending', 'expires_at' => $now->copy()->addHours(24), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            return $room;
        });

        foreach ($invitees as $invitee) {
            if (! empty($invitee->token)) {
                app(\App\Http\Controllers\FCMNotificationController::class)->sendToToken(
                    $invitee->token,
                    'Zo Stream chat invitation',
                    $type === 'global' ? 'You were invited to a private chat room.' : 'You were invited to watch '.($content->title ?? 'a title').' together.',
                    key: 'chat_invite', data: ['invite_id' => (string) DB::table('chat_room_invites')->where('room_id', $roomId)->where('invitee_user_id', $invitee->uid)->value('id'), 'room_id' => (string) $roomId]
                );
            }
        }

        return V4Response::success(['room_id' => (string) $roomId, 'room_type' => 'invite', 'room_name' => trim($v['room_name']), 'content_type' => $type === 'global' ? null : $type,
            'content_id' => $v['content_id'] ?? null, 'content_title' => $content->title ?? null, 'invite_count' => $invitees->count()], status: 201);
    }

    public function inviteRooms(Request $r)
    {
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        $uid = (string) $r->input('auth_user_id');
        $rooms = DB::table('chat_invite_rooms as ir')
            ->join('chat_rooms as r', 'r.id', '=', 'ir.room_id')
            ->where('ir.active', true)->where('r.closed', false)
            ->where(fn ($q) => $q->whereNotNull('ir.content_type')->orWhere('ir.idle_expires_at', '>', now()))
            ->where(function ($q) use ($uid) {
                $q->where('ir.host_user_id', $uid)->orWhereExists(function ($member) use ($uid) {
                    $member->selectRaw('1')->from('chat_room_invites as i')
                        ->whereColumn('i.room_id', 'ir.room_id')
                        ->where('i.invitee_user_id', $uid)->where('i.status', 'accepted');
                });
            })
            ->orderByDesc('ir.room_id')
            ->get(['ir.room_id', 'ir.room_name', 'ir.content_type', 'ir.content_id', 'ir.host_user_id', 'ir.idle_expires_at']);
        $rooms->transform(function ($room) use ($uid) {
            $room->room_id = (string) $room->room_id;
            $room->is_host = $room->host_user_id === $uid;
            unset($room->host_user_id);
            return $room;
        });

        return V4Response::success(['rooms' => $rooms]);
    }

    public function endInviteRoom(Request $r, int $room)
    {
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        DB::transaction(function () use ($r, $room): void {
            $inviteRoom = DB::table('chat_invite_rooms')->where('room_id', $room)->lockForUpdate()->first();
            abort_unless($inviteRoom, 404);
            abort_unless($inviteRoom->host_user_id === (string) $r->input('auth_user_id'), 403, 'Only the room creator can end this room.');
            abort_unless($inviteRoom->content_type === null, 422, 'Only private chat rooms can be ended here.');
            if ($inviteRoom->active) {
                DB::table('chat_invite_rooms')->where('room_id', $room)->update(['active' => false, 'updated_at' => now()]);
                $this->chat->outbox($r, $room, 'ended', null, null);
            }
        });
        $this->chat->invalidate($room, true);

        return V4Response::success(['ended' => true, 'room_id' => (string) $room]);
    }

    public function invites(Request $r)
    {
        $uid = (string) $r->input('auth_user_id');
        $rows = DB::table('chat_room_invites as i')->join('chat_rooms as r', 'r.id', '=', 'i.room_id')
            ->join('chat_invite_rooms as ir', 'ir.room_id', '=', 'i.room_id')->leftJoin('user as u', 'u.uid', '=', 'i.inviter_user_id')
            ->where('i.invitee_user_id', $uid)->where('i.status', 'pending')->where('i.expires_at', '>', now())
            ->where('r.closed', false)
            ->where(fn ($q) => $q->whereNotNull('ir.content_type')->orWhere('ir.idle_expires_at', '>', now()))
            ->where('ir.active', true)->orderByDesc('i.id')->limit(50)
            ->get(['i.id as invite_id', 'i.room_id', 'i.expires_at', 'ir.room_name', 'ir.content_type', 'ir.content_id', 'ir.watch_position_ms', 'u.name as inviter_name']);
        $rows->transform(function ($invite) {
            $invite->invite_id = (string) $invite->invite_id;
            $invite->room_id = (string) $invite->room_id;
            $invite->watch_position_ms = (int) $invite->watch_position_ms;
            $invite->inviter_name = $invite->inviter_name ?? 'Viewer';
            if ($invite->content_type && $invite->content_id) {
                $content = DB::table($invite->content_type === 'episode' ? 'episodes' : 'movie')->where('num', $invite->content_id)->first();
                $invite->content_title = $content->title ?? null;
                $invite->season_id = $invite->content_type === 'episode' && isset($content->season_id) ? (string) $content->season_id : null;
                $invite->episode_number = $invite->content_type === 'episode' && isset($content->episode_number) ? (int) $content->episode_number : null;
                $seasonNumber = $invite->season_id ? DB::table('seasons')->where('id', $invite->season_id)->value('season_number') : null;
                $invite->season_number = $seasonNumber !== null ? (int) $seasonNumber : null;
            } else {
                $invite->content_title = null; $invite->season_id = null; $invite->episode_number = null; $invite->season_number = null;
            }
            return $invite;
        });
        return V4Response::success(['invites' => $rows]);
    }

    public function respondInvite(Request $r, int $invite)
    {
        $v = $r->validate(['accept' => 'required|boolean']);
        $uid = (string) $r->input('auth_user_id');
        $row = DB::table('chat_room_invites as i')->join('chat_invite_rooms as ir', 'ir.room_id', '=', 'i.room_id')
            ->where('i.id', $invite)->where('i.invitee_user_id', $uid)->where('i.status', 'pending')
            ->where('i.expires_at', '>', now())->where('ir.active', true)
            ->where(fn ($q) => $q->whereNotNull('ir.content_type')->orWhere('ir.idle_expires_at', '>', now()))
            ->first(['i.room_id', 'i.inviter_user_id', 'ir.room_name', 'ir.content_type', 'ir.content_id', 'ir.watch_position_ms']);
        abort_unless($row, 404, 'This invitation has expired or is no longer available.');
        $content = null;
        if ($v['accept'] && $row->content_type && $row->content_id) {
            $content = DB::table($row->content_type === 'episode' ? 'episodes' : 'movie')->where('num', $row->content_id)->first();
            abort_unless($content, 404, 'This content is unavailable.');
            abort_if((bool) ($content->isPayPerView ?? false), 403, 'Invite rooms are unavailable for PPV content.');
            if ((bool) ($content->isPremium ?? false)) {
                abort_unless(DB::table('n_subscriptions')->where('user_id', $uid)->where('is_active', true)
                    ->where('end_at', '>=', now()->startOfDay())->exists(), 403, 'An active subscription is required to accept this invitation.');
            }
            // Use the host's latest persisted playback progress at accept time,
            // instead of a potentially stale position from when the invite was sent.
            if (\Illuminate\Support\Facades\Schema::hasTable('watch_position')
                && \Illuminate\Support\Facades\Schema::hasColumn('watch_position', 'updated_at')) {
                $progress = DB::table('watch_position')->where('user_id', $row->inviter_user_id)
                    ->where('movie_id', $row->content_id)->when(\Illuminate\Support\Facades\Schema::hasColumn('watch_position', 'movie_type'),
                        fn ($q) => $q->where('movie_type', $row->content_type))
                    ->where('updated_at', '>=', now()->subMinute())->value('position');
                if ($progress !== null) {
                    $positionMs = min(86400000, max(0, (int) $progress * 1000));
                    DB::table('chat_invite_rooms')->where('room_id', $row->room_id)->update(['watch_position_ms' => $positionMs, 'updated_at' => now()]);
                    $row->watch_position_ms = $positionMs;
                }
            }
        }
        DB::table('chat_room_invites')->where('id', $invite)->where('status', 'pending')->update([
            'status' => $v['accept'] ? 'accepted' : 'declined', 'responded_at' => now(), 'updated_at' => now(),
        ]);
        $seasonId = $row->content_type === 'episode' && isset($content->season_id) ? (string) $content->season_id : null;
        $seasonNumber = $seasonId ? DB::table('seasons')->where('id', $seasonId)->value('season_number') : null;
        return V4Response::success(['accepted' => (bool) $v['accept'], 'room_id' => (string) $row->room_id, 'room_name' => $row->room_name,
            'content_type' => $row->content_type, 'content_id' => $row->content_id,
            'content_title' => $content->title ?? null,
            'season_id' => $seasonId,
            'season_number' => $seasonNumber !== null ? (int) $seasonNumber : null,
            'episode_number' => ($row->content_type === 'episode' && isset($content->episode_number) ? (int) $content->episode_number : null),
            'watch_position_ms' => (int) $row->watch_position_ms]);
    }

    public function joinInviteRoom(Request $r, int $room)
    {
        $roomInfo = $this->chat->room($r, $room);
        abort_unless($roomInfo->type === 'invite', 404);
        $inviteRoom = DB::table('chat_invite_rooms')->where('room_id', $room)->first();
        $content = $inviteRoom->content_type && $inviteRoom->content_id
            ? DB::table($inviteRoom->content_type === 'episode' ? 'episodes' : 'movie')->where('num', $inviteRoom->content_id)->first() : null;
        $seasonId = $inviteRoom->content_type === 'episode' ? ($content->season_id ?? null) : null;
        $this->chat->room($r, $room);
        return V4Response::success(['room_id' => (string) $room, 'room_type' => 'invite', 'channel' => 'private-chat.'.$room,
            'websocket_url' => config('chat.websocket_url'), 'online_count' => $this->chat->presence($r, $room),
            'user_id' => (string) $r->input('auth_user_id'), 'room_name' => $inviteRoom->room_name, 'content_type' => $inviteRoom->content_type,
            'content_id' => $inviteRoom->content_id, 'watch_position_ms' => (int) $inviteRoom->watch_position_ms,
            'is_host' => $inviteRoom->host_user_id === (string) $r->input('auth_user_id'),
            'content_title' => $content->title ?? null, 'season_id' => $seasonId,
            'season_number' => $seasonId ? DB::table('seasons')->where('id', $seasonId)->value('season_number') : null,
            'episode_number' => $content->episode_number ?? null,
            'slow_mode_seconds' => config('chat.slow_mode_seconds')]);
    }

    public function updateInvitePosition(Request $r, int $room)
    {
        $v = $r->validate(['position_ms' => 'nullable|integer|min:0|max:86400000']);
        $this->chat->room($r, $room, true);
        $inviteRoom = DB::table('chat_invite_rooms')->where('room_id', $room)->first();
        abort_unless($inviteRoom, 404);
        $isHost = $inviteRoom->host_user_id === (string) $r->input('auth_user_id');
        if ($isHost && isset($v['position_ms'])) {
            DB::table('chat_invite_rooms')->where('room_id', $room)->update(['watch_position_ms' => $v['position_ms'], 'updated_at' => now()]);
            $inviteRoom->watch_position_ms = $v['position_ms'];
        }
        abort_if(! $isHost && isset($v['position_ms']), 403, 'Only the host can update playback position.');
        return V4Response::success(['position_ms' => (int) $inviteRoom->watch_position_ms, 'is_host' => $isHost]);
    }

    public function auth(Request $r, int $room)
    {
        $this->chat->room($r, $room);
        $v = $r->validate(['socket_id' => ['required', 'regex:/^[0-9]+\.[0-9]+$/']]);
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        abort_unless($key && $secret, 503, 'Live chat is not configured.');
        $channel = 'private-chat.'.$room;

        return V4Response::success(['auth' => $key.':'.hash_hmac('sha256', $v['socket_id'].':'.$channel, $secret), 'channel' => $channel]);
    }

    public function messages(Request $r, int $room)
    {
        $this->chat->room($r, $room);
        $v = $r->validate(['before' => ['nullable', 'integer', 'min:1', Rule::prohibitedIf($r->filled('after'))], 'after' => 'nullable|integer|min:0']);
        $limit = config('chat.page_size');
        $blocked = $this->chat->blocked((string) $r->input('auth_user_id'));
        $recent = $this->chat->recent($room);
        $after = $v['after'] ?? null;
        $before = $v['before'] ?? null;
        $min = $recent ? min(array_column($recent, 'id')) : null;
        if ($before === null && (($after === null && (count($recent) < config('chat.recent_limit') || count(array_filter($recent, fn ($m) => ! in_array($m['user_id'], $blocked, true))) > $limit)) || ($after !== null && ($min === null || $after >= $min)))) {
            $rows = array_values(array_filter($recent, fn ($m) => ! in_array($m['user_id'], $blocked, true) && ($after === null || $m['id'] > $after)));
            if ($after !== null) {
                $rows = array_reverse($rows);
            }
            $hasMore = count($rows) > $limit || ($after === null && count($recent) >= config('chat.recent_limit'));
            $rows = array_slice($rows, 0, $limit);
        } else {
            $q = DB::table('chat_messages')->where('room_id', $room)->whereNotIn('user_id', $blocked)
                ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))
                ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
                ->when($after !== null, fn ($q) => $q->where('id', '>', $after));
            $rows = $q->orderBy('id', $after !== null ? 'asc' : 'desc')->limit($limit + 1)->get()->map(fn ($m) => $this->chat->message($m))->all();
            $hasMore = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
        }
        if ($after === null) {
            $rows = array_reverse($rows);
        }

        return V4Response::success(['messages' => $rows, 'has_more' => $hasMore]);
    }

    public function send(Request $r, int $room)
    {
        $roomInfo = $this->chat->room($r, $room, true);
        $v = $r->validate(['request_id' => 'required|uuid', 'body' => 'required|string|max:1000',
            'spoiler' => 'sometimes|boolean', 'app_session_id' => 'nullable|uuid', 'playback_session_id' => 'nullable|uuid']);
        $uid = (string) $r->input('auth_user_id');
        $body = trim($v['body']);
        abort_if($body === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $body), 422, 'Enter a valid message.');
        $old = DB::table('chat_messages')->where('user_id', $uid)->where('request_id', $v['request_id'])->first();
        if ($old) {
            abort_unless((int) $old->room_id === $room, 409);

            return V4Response::success($this->chat->message($old));
        }
        // Atomic Redis add also serializes sends from multiple app servers.
        try {
            $allowed = Cache::store(config('chat.cache_store'))->add('chat:send:'.hash('sha256', $uid), true, config('chat.slow_mode_seconds'));
        } catch (\Throwable $e) {
            abort(503, 'Chat is temporarily unavailable. Please retry.');
        }
        abort_unless($allowed, 429, 'Please wait before sending another message.');
        $m = DB::transaction(function () use ($r, $room, $uid, $v, $body, $roomInfo) {
            // Serialize messages with host termination, and recheck expiry after acquiring the lock.
            if ($roomInfo->type === 'invite') {
                $inviteRoom = DB::table('chat_invite_rooms')->where('room_id', $room)->lockForUpdate()->first();
                $this->chat->assertInviteRoomActive($inviteRoom);
            }
            $name = DB::table('user')->where('uid', $uid)->value('name');
            $inserted = DB::table('chat_messages')->insertOrIgnore(['room_id' => $room, 'user_id' => $uid, 'request_id' => $v['request_id'],
                'display_name' => mb_substr(trim((string) $name) ?: 'Viewer', 0, 100), 'body' => $body,
                'spoiler' => $v['spoiler'] ?? false, 'created_at' => now(), 'updated_at' => now()]);
            $m = DB::table('chat_messages')->where('user_id', $uid)->where('request_id', $v['request_id'])->first();
            abort_unless((int) $m->room_id === $room, 409);
            if ($inserted) {
                if ($roomInfo->type === 'invite' && $inviteRoom->content_type === null) {
                    DB::table('chat_invite_rooms')->where('room_id', $room)->update(['idle_expires_at' => now()->addHour()]);
                }
                $this->chat->outbox($r, $room, 'created', $m->id, 'chat_message_sent');
            }

            return $m;
        });
        $this->chat->invalidate($room);

        return V4Response::success($this->chat->message($m), status: 201);
    }

    public function report(Request $r, int $room, int $message)
    {
        $this->chat->room($r, $room);
        $v = $r->validate(['reason' => 'required|string|max:500', 'app_session_id' => 'nullable|uuid', 'playback_session_id' => 'nullable|uuid']);
        abort_unless(DB::table('chat_messages')->where('room_id', $room)->where('id', $message)->exists(), 404);
        DB::transaction(function () use ($r, $room, $message, $v) {
            $new = DB::table('chat_reports')->insertOrIgnore(['message_id' => $message, 'user_id' => $r->input('auth_user_id'),
                'reason' => $v['reason'], 'created_at' => now(), 'updated_at' => now()]);
            if ($new) {
                $this->chat->outbox($r, $room, 'reported', $message, 'chat_message_reported');
            }
        });

        return V4Response::success(['reported' => true]);
    }

    public function heartbeat(Request $r, int $room)
    {
        $this->chat->room($r, $room);

        return V4Response::success(['online_count' => $this->chat->presence($r, $room)]);
    }

    public function leave(Request $r, int $room)
    {
        $this->chat->presence($r, $room, true);

        return V4Response::success(['left' => true]);
    }

    public function blocks(Request $r)
    {
        $ids = $this->chat->blocked((string) $r->input('auth_user_id'));
        $names = DB::table('user')->whereIn('uid', $ids)->pluck('name', 'uid');

        return V4Response::success(['user_ids' => $ids, 'users' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'Viewer'], $ids)]);
    }

    public function reports(Request $r)
    {
        $v = $r->validate(['before' => 'nullable|integer|min:1']);
        $rows = DB::table('chat_reports as r')->leftJoin('chat_messages as m', 'm.id', '=', 'r.message_id')
            ->where('r.status', 'pending')->when(isset($v['before']), fn ($q) => $q->where('r.id', '<', $v['before']))
            ->orderByDesc('r.id')->limit(50)->get(['r.*', 'm.room_id', 'm.user_id as sender_id', 'm.body', 'm.deleted_at']);

        return V4Response::success(['reports' => $rows]);
    }

    public function resolveReport(Request $r, int $report)
    {
        $v = $r->validate(['reason' => 'required|string|max:500']);
        DB::transaction(function () use ($r, $report, $v) {
            $row = DB::table('chat_reports')->where('id', $report)->first();
            abort_unless($row, 404);
            $message = DB::table('chat_messages')->find($row->message_id);
            DB::table('chat_reports')->where('id', $report)->update(['status' => 'resolved', 'updated_at' => now()]);
            DB::table('chat_moderation_actions')->insert(['admin_id' => $r->input('auth_user_id'),
                'room_id' => $message?->room_id ?? 0, 'action' => 'resolve_report', 'target' => (string) $report,
                'reason' => $v['reason'], 'created_at' => now(), 'updated_at' => now()]);
        });

        return V4Response::success(['resolved' => true]);
    }

    public function block(Request $r)
    {
        $v = $r->validate(['user_id' => 'required|string|max:191', 'blocked' => 'required|boolean']);
        $uid = (string) $r->input('auth_user_id');
        abort_if($uid === $v['user_id'], 422);
        $q = DB::table('chat_blocks')->where('user_id', $uid)->where('blocked_user_id', $v['user_id']);
        if ($v['blocked']) {
            DB::table('chat_blocks')->insertOrIgnore(['user_id' => $uid, 'blocked_user_id' => $v['user_id']]);
        } else {
            $q->delete();
        }
        $this->chat->invalidateBlocks($uid);

        return V4Response::success(['blocked' => $v['blocked']]);
    }

    public function moderate(Request $r, int $room)
    {
        abort_unless(DB::table('chat_rooms')->where('id', $room)->exists() || $room === 0, 404);
        $v = $r->validate(['action' => ['required', Rule::in(['delete', 'mute', 'ban', 'unban', 'close', 'open'])],
            'target' => 'required|string|max:191', 'reason' => 'required|string|max:500', 'expires_at' => 'nullable|date|after:now']);
        if ($room === 0) {
            abort_unless(in_array($v['action'], ['mute', 'ban', 'unban'], true), 422, 'Only viewer restrictions can apply to all rooms.');
        }
        if ($v['action'] === 'delete') {
            abort_unless(ctype_digit($v['target']), 422, 'A message ID is required.');
        }
        DB::transaction(function () use ($r, $room, $v) {
            switch ($v['action']) {
                case 'delete':
                    $message = DB::table('chat_messages')->where('room_id', $room)->where('id', $v['target'])->lockForUpdate()->first();
                    abort_unless($message, 404);
                    if ($message->deleted_at !== null) {
                        return;
                    }
                    DB::table('chat_messages')->where('id', $message->id)->update(['body' => '', 'deleted_at' => now()]);
                    $this->chat->outbox($r, $room, 'deleted', (int) $v['target'], null);
                    break;
                case 'mute': case 'ban':
                    DB::table('chat_bans')->updateOrInsert(['room_id' => $room, 'user_id' => $v['target']], ['kind' => $v['action'], 'expires_at' => $v['expires_at'] ?? null]);
                    break;
                case 'unban': DB::table('chat_bans')->where('room_id', $room)->where('user_id', $v['target'])->delete();
                    break;
                default: DB::table('chat_rooms')->where('id', $room)->update(['closed' => $v['action'] === 'close']);
            }
            DB::table('chat_moderation_actions')->insert(['admin_id' => $r->input('auth_user_id'), 'room_id' => $room,
                'action' => $v['action'], 'target' => $v['target'], 'reason' => $v['reason'], 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->chat->invalidate($room, true);
        $this->chat->invalidateBan($v['target'], $room);

        return V4Response::success(['updated' => true]);
    }
}
