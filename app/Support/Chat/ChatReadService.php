<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ChatReadService
{
    public function __construct(private readonly ChatService $chat) {}

    public function summary(Request $request, string $type, ?string $contentId): array
    {
        $uid = (string) $request->input('auth_user_id');
        $invites = DB::table('chat_invite_rooms as ir')->select('ir.room_id')->where('ir.active', true)
            ->where(fn ($q) => $q->whereNotNull('ir.content_type')->orWhere('ir.idle_expires_at', '>', now()))
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
        $result = array_map(function ($room) use ($reads, $unread) {
            $state = $unread[$room->id] ?? null;
            return ['room_id' => (string) $room->id,
                'key' => $room->type === 'global' ? 'global' : $room->type.':'.($room->type === 'invite' ? $room->id : $room->content_id),
                'unread_count' => (int) ($state->unread_count ?? 0),
                'last_read_message_id' => (string) ($reads[$room->id] ?? 0),
                'first_unread_id' => isset($state->first_unread_id) ? (string) $state->first_unread_id : null];
        }, $rooms);

        return ['rooms' => $result, 'total_unread' => array_sum(array_column($result, 'unread_count'))];
    }

    public function mark(Request $request, int $room, int $message): array
    {
        $this->chat->room($request, $room);
        abort_unless(DB::table('chat_messages')->where('room_id', $room)->where('id', $message)->exists(), 422, 'Message does not belong to this room.');
        $uid = (string) $request->input('auth_user_id');
        DB::transaction(function () use ($uid, $room, $message): void {
            DB::table('chat_room_reads')->insertOrIgnore(['user_id' => $uid, 'room_id' => $room, 'last_read_message_id' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            // Conditional updates are monotonic even when two devices report out of order.
            DB::table('chat_room_reads')->where('user_id', $uid)->where('room_id', $room)
                ->where('last_read_message_id', '<', $message)->update(['last_read_message_id' => $message, 'updated_at' => now()]);
        });

        return ['room_id' => (string) $room, 'last_read_message_id' => (string) DB::table('chat_room_reads')
            ->where('user_id', $uid)->where('room_id', $room)->value('last_read_message_id')];
    }
}
