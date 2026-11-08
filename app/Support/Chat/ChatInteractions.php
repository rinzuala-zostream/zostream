<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ChatInteractions
{
    public const EMOJI = ['😂', '❤️', '🔥', '😢', '👏', '😮'];

    public function decorate(array $messages, string $uid): array
    {
        if ($messages === []) { return []; }
        $db = ChatDatabase::connection();
        $blocked = app(ChatService::class)->blocked($uid);
        $ids = array_column($messages, 'id');
        $counts = $db->table('chat_reactions')->whereIn('message_id', $ids)->whereNotIn('user_id', $blocked)
            ->selectRaw('message_id, emoji, COUNT(*) as count')->groupBy('message_id', 'emoji')->get()->groupBy('message_id');
        $mine = $db->table('chat_reactions')->whereIn('message_id', $ids)->where('user_id', $uid)->get()->groupBy('message_id');
        $parents = $db->table('chat_messages')->whereIn('id', array_filter(array_column($messages, 'reply_to_id')))->get()->keyBy('id');
        foreach ($messages as &$m) {
            $m['reactions'] = $m['deleted'] ? [] : collect($counts->get($m['id'], []))->map(fn ($r) => ['emoji' => $r->emoji, 'count' => (int) $r->count])->values()->all();
            $m['my_reactions'] = $m['deleted'] ? [] : collect($mine->get($m['id'], []))->pluck('emoji')->all();
            $m['reply'] = null;
            if (! $m['deleted'] && ($parent = $parents->get($m['reply_to_id'] ?? 0))) {
                $hidden = $parent->deleted_at || in_array($parent->user_id, $blocked, true)
                    || \Illuminate\Support\Carbon::parse($parent->created_at)->lt(now()->subDays(config('chat.retention_days')));
                $m['reply'] = ['id' => (string) $parent->id, 'user_id' => $hidden ? null : $parent->user_id, 'display_name' => $hidden ? 'Message unavailable' : $parent->display_name,
                    'body' => $hidden || $parent->spoiler ? null : mb_substr($parent->body, 0, 180),
                    'spoiler' => ! $hidden && (bool) $parent->spoiler, 'deleted' => (bool) $hidden];
            }
        }
        return $messages;
    }

    public function target(Request $r, int $room, int $message): object
    {
        app(ChatService::class)->room($r, $room);
        $m = ChatDatabase::connection()->table('chat_messages')->where('room_id', $room)->where('id', $message)
            ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))->first();
        abort_unless($m && ! in_array($m->user_id, app(ChatService::class)->blocked((string) $r->input('auth_user_id')), true), 404, 'This message is unavailable.');
        return $m;
    }

    public function validateMoment(Request $r, object $room, array $moment): void
    {
        $chat = app(ChatService::class);
        abort_unless($chat->playbackAllowed($r, $moment['content_type'], $moment['content_id']), 403, 'Start playback before sharing a moment.');
        if ($room->type === 'invite') {
            $invite = ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room->id)->first();
            $context = $invite->content_type ? $invite : app(PrivateWatchService::class)->liveSession((int) $room->id);
        } else { $context = (object) ['content_type' => $room->type, 'content_id' => $room->content_id]; }
        abort_unless($context && $context->content_type === $moment['content_type'] && (string) $context->content_id === $moment['content_id'], 422, 'Share this moment in the matching movie or watch room.');
    }

    public function typing(Request $r, int $room, ?bool $active = null): array
    {
        $chat = app(ChatService::class);
        $info = $chat->room($r, $room, $active !== null);
        $uid = (string) $r->input('auth_user_id');
        $cache = Cache::store(config('chat.cache_store'));
        $key = 'chat:typists:'.$room;
        if ($active !== null) {
            // Keep a bounded room index so public rooms need no membership scan.
            // Serialize updates: two simultaneous typists must not overwrite one another.
            $cache->lock($key.':lock', 3)->block(1, function () use ($cache, $key, $active, $uid): void {
                $users = array_filter($cache->get($key, []), fn ($user) => $user['expires_at'] > now()->timestamp);
                $userKey = hash('sha256', $uid);
                unset($users[$userKey]);
                if ($active) {
                    $name = DB::table('user')->where('uid', $uid)->value('name') ?: 'Viewer';
                    $users[$userKey] = ['user_id' => $uid, 'name' => mb_substr(trim($name), 0, 100), 'expires_at' => now()->timestamp + 6];
                }
                $cache->put($key, array_slice($users, -20, null, true), 6);
            });
            $chat->outbox($r, $room, 'typing', null, null);
        }
        $blocked = ChatDatabase::connection()->table('chat_blocks')
            ->where('user_id', $uid)->orWhere('blocked_user_id', $uid)->get()
            ->map(fn ($block) => (string) ($block->user_id === $uid ? $block->blocked_user_id : $block->user_id))->all();
        $members = null;
        if ($info->type === 'invite') {
            $members = ChatDatabase::connection()->table('chat_room_invites')->where('room_id', $room)->where('status', 'accepted')
                ->pluck('invitee_user_id')->push(ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room)->value('host_user_id'))
                ->map(fn ($id) => (string) $id)->all();
        }
        $users = array_filter($cache->get($key, []), fn ($user) => $user['expires_at'] > now()->timestamp
            && $user['user_id'] !== $uid && ! in_array($user['user_id'], $blocked, true)
            && ($members === null || in_array($user['user_id'], $members, true)));
        return array_values(array_map(fn ($user) => ['user_id' => $user['user_id'], 'name' => $user['name']], $users));
    }
}
