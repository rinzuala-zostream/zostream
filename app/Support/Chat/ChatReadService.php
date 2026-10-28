<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ChatReadService
{
    public function __construct(private readonly ChatService $chat) {}

    public static function userChannel(string $uid): string
    {
        return 'private-chat-user-'.hash('sha256', $uid);
    }

    public static function contextChannel(string $type, ?string $contentId = null): string
    {
        return 'private-chat-context-'.hash('sha256', $type.':'.($contentId ?? ''));
    }

    public function authorizeChannel(Request $r, string $channel): void
    {
        $uid = (string) $r->input('auth_user_id');
        if ($channel === self::userChannel($uid)) { return; }
        if (preg_match('/^private-chat\.([1-9][0-9]*)$/', $channel, $match)) { $this->chat->room($r, (int) $match[1]); return; }
        $this->chat->refreshPlayback($r);
        $contexts = [['global', null]];
        $stream = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('d.user_id', $uid)->where('d.device_token', $r->input('auth_device_id'))
            ->where('s.stream_token', (string) $r->header('X-Playback-Token'))->where('s.status', 'active')->first(['s.content_type', 's.content_key']);
        if ($stream && in_array($stream->content_type, ['movie', 'episode'], true)) { $contexts[] = [$stream->content_type, $stream->content_key]; }
        foreach ($contexts as [$type, $id]) {
            if ($channel === self::contextChannel($type, $id)) {
                $room = DB::table('chat_rooms')->where('room_key', $type === 'global' ? 'global' : $type.':'.$id)->first();
                if ($room) { $this->chat->room($r, $room->id); }
                else { abort_if($this->rootBanned($uid), 403); }
                return;
            }
        }
        abort(403);
    }

    private function rootBanned(string $uid): bool
    {
        return DB::table('chat_bans')->where('user_id', $uid)->where('room_id', 0)->where('kind', 'ban')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    public function summary(Request $request, string $type, ?string $contentId): array
    {
        return DB::transaction(fn () => $this->snapshot($request, $type, $contentId));
    }

    private function snapshot(Request $request, string $type, ?string $contentId): array
    {
        $cursor = (string) (DB::table('chat_outbox')->max('id') ?? 0);
        $uid = (string) $request->input('auth_user_id');
        $invites = DB::table('chat_invite_rooms as ir')->select('ir.room_id')->where('ir.active', true)
            ->tap(fn ($q) => app(InviteRoomLifecycle::class)->candidates($q))
            ->where(function ($q) use ($uid) {
                $q->where('ir.host_user_id', $uid)->orWhereExists(function ($member) use ($uid) {
                    $member->selectRaw('1')->from('chat_room_invites as i')->whereColumn('i.room_id', 'ir.room_id')
                        ->where('i.invitee_user_id', $uid)->where('i.status', 'accepted');
                });
            })->where(function ($q) use ($type, $contentId) {
                $q->whereNull('ir.content_type');
                if ($type !== 'global') {
                    $q->orWhere(fn ($watch) => $watch->where('ir.content_type', $type)->where('ir.content_id', $contentId));
                }
            });
        $candidates = DB::table('chat_rooms')->where('closed', false)->where(function ($q) use ($invites, $type, $contentId) {
            $q->where('type', 'global')->orWhereIn('id', $invites);
            if ($type !== 'global') {
                $q->orWhere(fn ($movie) => $movie->where('type', $type)->where('content_id', $contentId));
            }
        })->get();
        $rooms = [];
        foreach ($candidates as $room) {
            try {
                $this->chat->room($request, $room->id);
                $rooms[] = $room;
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }
        $ids = array_column($rooms, 'id');
        $reads = DB::table('chat_room_reads')->where('user_id', $uid)->whereIn('room_id', $ids)->pluck('last_read_message_id', 'room_id');
        // Constant ranges use the existing (room_id, id) index instead of scanning
        // already-read history through a per-message read-state join.
        $unread = DB::table('chat_messages as m')->where(function ($query) use ($ids, $reads) {
            if ($ids === []) {
                $query->whereRaw('1 = 0');
            }
            foreach ($ids as $id) {
                $query->orWhere(fn ($range) => $range->where('m.room_id', $id)->where('m.id', '>', $reads[$id] ?? 0));
            }
        })->where('m.user_id', '!=', $uid)->whereNull('m.deleted_at')
            ->whereNotIn('m.user_id', $this->chat->blocked($uid))
            ->where('m.created_at', '>=', now()->subDays(config('chat.retention_days')))
            ->groupBy('m.room_id')->selectRaw('m.room_id, COUNT(*) as unread_count, MIN(m.id) as first_unread_id')->get()->keyBy('room_id');
        $latest = DB::table('chat_messages')->whereIn('room_id', $ids)->groupBy('room_id')->selectRaw('room_id, MAX(id) as last_id')->pluck('last_id', 'room_id');
        $result = array_map(function ($room) use ($reads, $unread, $latest) {
            $state = $unread[$room->id] ?? null;
            return ['room_id' => (string) $room->id,
                'key' => $room->type === 'global' ? 'global' : $room->type.':'.($room->type === 'invite' ? $room->id : $room->content_id),
                'unread_count' => (int) ($state->unread_count ?? 0), 'last_message_id' => (string) ($latest[$room->id] ?? 0),
                'last_read_message_id' => (string) ($reads[$room->id] ?? 0),
                'first_unread_id' => isset($state->first_unread_id) ? (string) $state->first_unread_id : null];
        }, $rooms);

        $channels = [self::userChannel($uid)];
        foreach ($rooms as $room) {
            if ($room->type === 'invite') { $channels[] = 'private-chat.'.$room->id; }
        }
        // Stable public-context channels discover the first message even before
        // anyone has created the corresponding numeric chat room.
        $contexts = [['global', null]];
        if ($type !== 'global' && $contentId !== null && $this->chat->playbackAllowed($request, $type, $contentId)) { $contexts[] = [$type, $contentId]; }
        if (! $this->rootBanned($uid)) {
            foreach ($contexts as [$context, $id]) {
                $existing = DB::table('chat_rooms')->where('room_key', $context === 'global' ? 'global' : $context.':'.$id)->value('id');
                if (! $existing || in_array($existing, $ids)) { $channels[] = self::contextChannel($context, $id); }
            }
        }
        return ['rooms' => $result, 'total_unread' => array_sum(array_column($result, 'unread_count')),
            'event_cursor' => $cursor, 'user_id' => $uid, 'blocked_user_ids' => $this->chat->blocked($uid),
            'server_time_ms' => now()->getTimestampMs(),
            'channels' => $channels,
            'websocket_url' => config('chat.websocket_url')];
    }

    public function readState(string $uid, int $room): array
    {
        return DB::transaction(function () use ($uid, $room) {
            $cursor = (string) (DB::table('chat_outbox')->max('id') ?? 0);
            $through = (int) (DB::table('chat_room_reads')->where('user_id', $uid)->where('room_id', $room)->value('last_read_message_id') ?? 0);
            $unread = DB::table('chat_messages')->where('room_id', $room)->where('id', '>', $through)
                ->where('user_id', '!=', $uid)->whereNull('deleted_at')->whereNotIn('user_id', $this->chat->blocked($uid))
                ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))
                ->selectRaw('COUNT(*) as total, MIN(id) as first_id')->first();
            return ['kind' => 'read', 'room_id' => (string) $room, 'snapshot_cursor' => $cursor,
                'read_through' => (string) $through, 'unread_count' => (int) $unread->total,
                'first_unread_id' => $unread->first_id === null ? null : (string) $unread->first_id,
                'last_message_id' => (string) (DB::table('chat_messages')->where('room_id', $room)->max('id') ?? 0)];
        });
    }

    public function mark(Request $request, int $room, int $message): array
    {
        $this->chat->room($request, $room);
        abort_unless(DB::table('chat_messages')->where('room_id', $room)->where('id', $message)->exists(), 422, 'Message does not belong to this room.');
        $uid = (string) $request->input('auth_user_id');
        DB::transaction(function () use ($request, $uid, $room, $message): void {
            DB::table('chat_room_reads')->insertOrIgnore(['user_id' => $uid, 'room_id' => $room, 'last_read_message_id' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            // Conditional updates are monotonic even when two devices report out of order.
            $changed = DB::table('chat_room_reads')->where('user_id', $uid)->where('room_id', $room)
                ->where('last_read_message_id', '<', $message)->update(['last_read_message_id' => $message, 'updated_at' => now()]);
            if ($changed) { $this->chat->outbox($request, $room, 'read', $message, null, $uid); }
        });

        return ['room_id' => (string) $room, 'last_read_message_id' => (string) DB::table('chat_room_reads')
            ->where('user_id', $uid)->where('room_id', $room)->value('last_read_message_id'),
            'badge' => $this->readState($uid, $room)];
    }
}
