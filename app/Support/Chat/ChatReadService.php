<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ChatReadService
{
    public function __construct(private readonly ChatService $chat) {}

    public function summary(Request $request, string $type, ?string $contentId): array
    {
        return ChatDatabase::connection()->transaction(fn () => $this->snapshot($request, $type, $contentId));
    }

    private function snapshot(Request $request, string $type, ?string $contentId): array
    {
        $cursor = (string) (ChatDatabase::connection()->table('chat_outbox')->max('id') ?? 0);
        $uid = (string) $request->input('auth_user_id');
        $invites = ChatDatabase::connection()->table('chat_invite_rooms as ir')->select('ir.room_id')->where('ir.active', true)
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
        $candidates = ChatDatabase::connection()->table('chat_rooms')->where(function ($q) use ($invites, $type, $contentId) {
            $q->where('type', 'global')->orWhereIn('id', $invites);
            if ($type !== 'global') {
                $q->orWhere(fn ($movie) => $movie->where('type', $type)->where('content_id', $contentId));
            }
        })->get();
        $rooms = [];
        foreach ($candidates as $room) {
            try {
                $this->chat->assertRoomAccess($request, $room);
                $rooms[] = $room;
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }
        $ids = array_column($rooms, 'id');
        $reads = ChatDatabase::connection()->table('chat_room_reads')->where('user_id', $uid)->whereIn('room_id', $ids)->pluck('last_read_message_id', 'room_id');
        // Constant ranges use the existing (room_id, id) index instead of scanning
        // already-read history through a per-message read-state join.
        $blocked = $this->chat->blocked($uid);
        $unread = $ids === [] ? collect() : ChatDatabase::connection()->table('chat_messages as m')->where(function ($query) use ($ids, $reads) {
            foreach ($ids as $id) {
                $query->orWhere(fn ($range) => $range->where('m.room_id', $id)->where('m.id', '>', $reads[$id] ?? 0));
            }
        })->where('m.user_id', '!=', $uid)->whereNull('m.deleted_at')
            ->whereNotIn('m.user_id', $blocked)
            ->where('m.created_at', '>=', now()->subDays(config('chat.retention_days')))
            ->groupBy('m.room_id')->selectRaw('m.room_id, COUNT(*) as unread_count, MIN(m.id) as first_unread_id')->get()->keyBy('room_id');
        // GROUP BY/MAX walks the room history. A bounded lookup per room can
        // seek directly to the end of the existing (room_id, id) index instead.
        $latest = $ids === [] ? collect() : ChatDatabase::connection()->table('chat_rooms')
            ->whereIn('id', $ids)->select('id')->selectSub(
                ChatDatabase::connection()->table('chat_messages')->select('id')
                    ->whereColumn('room_id', 'chat_rooms.id')->orderByDesc('id')->limit(1),
                'last_id'
            )->get()->pluck('last_id', 'id');
        $result = array_map(function ($room) use ($reads, $unread, $latest) {
            $state = $unread[$room->id] ?? null;
            return ['room_id' => (string) $room->id,
                'key' => $room->type === 'global' ? 'global' : $room->type.':'.($room->type === 'invite' ? $room->id : $room->content_id),
                'unread_count' => (int) ($state->unread_count ?? 0), 'last_message_id' => (string) ($latest[$room->id] ?? 0),
                'last_read_message_id' => (string) ($reads[$room->id] ?? 0),
                'first_unread_id' => isset($state->first_unread_id) ? (string) $state->first_unread_id : null];
        }, $rooms);

        return ['rooms' => $result, 'total_unread' => array_sum(array_column($result, 'unread_count')),
            'auth_batch' => false,
            'event_cursor' => $cursor, 'user_id' => $uid, 'blocked_user_ids' => $blocked,
            'server_time_ms' => now()->getTimestampMs(),
            'channels' => [],
            'websocket_url' => '',
            'firebase_realtime' => ChatChannels::firebaseAccountEventsAvailable()];
    }

    public function readState(string $uid, int $room): array
    {
        return ChatDatabase::connection()->transaction(function () use ($uid, $room) {
            $cursor = (string) (ChatDatabase::connection()->table('chat_outbox')->max('id') ?? 0);
            $through = (int) (ChatDatabase::connection()->table('chat_room_reads')->where('user_id', $uid)->where('room_id', $room)->value('last_read_message_id') ?? 0);
            $unread = ChatDatabase::connection()->table('chat_messages')->where('room_id', $room)->where('id', '>', $through)
                ->where('user_id', '!=', $uid)->whereNull('deleted_at')->whereNotIn('user_id', $this->chat->blocked($uid))
                ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))
                ->selectRaw('COUNT(*) as total, MIN(id) as first_id')->first();
            return ['kind' => 'read', 'room_id' => (string) $room, 'snapshot_cursor' => $cursor,
                'read_through' => (string) $through, 'unread_count' => (int) $unread->total,
                'first_unread_id' => $unread->first_id === null ? null : (string) $unread->first_id,
                'last_message_id' => (string) (ChatDatabase::connection()->table('chat_messages')->where('room_id', $room)->max('id') ?? 0)];
        });
    }

    public function mark(Request $request, int $room, int $message): array
    {
        $this->chat->room($request, $room);
        abort_unless(ChatDatabase::connection()->table('chat_messages')->where('room_id', $room)->where('id', $message)->exists(), 422, 'Message does not belong to this room.');
        $uid = (string) $request->input('auth_user_id');
        ChatDatabase::connection()->transaction(function () use ($request, $uid, $room, $message): void {
            ChatDatabase::connection()->table('chat_room_reads')->insertOrIgnore(['user_id' => $uid, 'room_id' => $room, 'last_read_message_id' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            // Conditional updates are monotonic even when two devices report out of order.
            $changed = ChatDatabase::connection()->table('chat_room_reads')->where('user_id', $uid)->where('room_id', $room)
                ->where('last_read_message_id', '<', $message)->update(['last_read_message_id' => $message, 'updated_at' => now()]);
            if ($changed) { $this->chat->outbox($request, $room, 'read', $message, null, $uid); }
        });

        return ['room_id' => (string) $room, 'last_read_message_id' => (string) ChatDatabase::connection()->table('chat_room_reads')
            ->where('user_id', $uid)->where('room_id', $room)->value('last_read_message_id'),
            'badge' => $this->readState($uid, $room)];
    }
}
