<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use App\Support\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

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
        $this->chat->room($r, $room, true);
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
        $m = DB::transaction(function () use ($r, $room, $uid, $v, $body) {
            $name = DB::table('user')->where('uid', $uid)->value('name');
            $inserted = DB::table('chat_messages')->insertOrIgnore(['room_id' => $room, 'user_id' => $uid, 'request_id' => $v['request_id'],
                'display_name' => mb_substr(trim((string) $name) ?: 'Viewer', 0, 100), 'body' => $body,
                'spoiler' => $v['spoiler'] ?? false, 'created_at' => now(), 'updated_at' => now()]);
            $m = DB::table('chat_messages')->where('user_id', $uid)->where('request_id', $v['request_id'])->first();
            abort_unless((int) $m->room_id === $room, 409);
            if ($inserted) {
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
