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
        abort_unless($info->type === 'invite', 422, 'Typing indicators are only available in private rooms.');
        $uid = (string) $r->input('auth_user_id');
        $members = ChatDatabase::connection()->table('chat_room_invites')->where('room_id', $room)->where('status', 'accepted')
            ->pluck('invitee_user_id')->push(ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room)->value('host_user_id'))->unique();
        $cache = Cache::store(config('chat.cache_store'));
        $key = fn ($id) => 'chat:typing:'.$room.':'.hash('sha256', $id);
        if ($active !== null) {
            if ($active) {
                $name = DB::table('user')->where('uid', $uid)->value('name') ?: 'Someone';
                $cache->put($key($uid), ['user_id' => $uid, 'name' => mb_substr($name, 0, 100)], 6);
            } else { $cache->forget($key($uid)); }
            $chat->outbox($r, $room, 'typing', null, null);
        }
        $blocked = $chat->blocked($uid);
        $eligible = $members->reject(fn ($id) => $id === $uid || in_array($id, $blocked, true));
        return array_values(array_filter($cache->many($eligible->map($key)->all())));
    }
}
