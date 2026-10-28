<?php

namespace App\Support\Chat;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

final class InviteRoomLifecycle
{
    public function active(?object $room): bool
    {
        if (! $room || ! $room->active || ($room->closed ?? false)) { return false; }
        if ($room->content_type !== null) { return $this->hostActive($room); }
        return ($room->idle_expires_at !== null && Carbon::parse($room->idle_expires_at)->isFuture())
            || app(PrivateWatchService::class)->liveSession((int) $room->room_id) !== null;
    }

    public function hostActive(object $room): bool
    {
        // List/link queries intentionally select different subsets of metadata.
        $room = DB::table('chat_invite_rooms')->where('room_id', $room->room_id)->first();
        if (! $room || ! $room->active || $room->content_type === null) { return false; }
        $query = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('d.user_id', $room->host_user_id)->where('s.status', 'active')
            ->where('s.content_type', $room->content_type)->where('s.content_key', $room->content_id)
            ->where('s.last_ping', '>=', now()->subMinutes(3));
        // Nullable bindings support legacy rows until migration backfill completes.
        if ($room->host_stream_id !== null) { $query->where('s.id', $room->host_stream_id); }
        $streams = $query->get(['s.stream_token']);
        return $streams->contains(fn ($stream) => $room->stream_token_hash === null
            || hash_equals($room->stream_token_hash, hash('sha256', $stream->stream_token)));
    }

    public function endInactiveWatchRooms(Request $request, ?int $stream = null): void
    {
        $query = DB::table('chat_invite_rooms')->whereNotNull('content_type')->where('active', true);
        if ($stream !== null) {
            $query->where(fn ($q) => $q->where('host_stream_id', $stream)->orWhereNull('host_stream_id'));
        }
        $query->orderBy('id')->chunkById(200, function ($rooms) use ($request): void {
            foreach ($rooms as $room) {
                DB::transaction(function () use ($room, $request): void {
                    $current = DB::table('chat_invite_rooms')->where('id', $room->id)->lockForUpdate()->first();
                    if (! $current?->active || $this->hostActive($current)) { return; }
                    DB::table('chat_invite_rooms')->where('id', $room->id)->update(['active' => false, 'updated_at' => now()]);
                    $chat = app(ChatService::class);
                    $chat->outbox($request, (int) $room->room_id, 'ended', null, null);
                    $chat->invalidate((int) $room->room_id, true);
                });
            }
        });
    }

    /** Narrow candidates in SQL; active() also checks the bound stream token. */
    public function candidates(Builder $query, string $alias = 'ir'): Builder
    {
        return $query->where($alias.'.active', true)->where(function (Builder $q) use ($alias) {
            $q->whereNotNull($alias.'.content_type')->orWhere($alias.'.idle_expires_at', '>', now())
                ->orWhereExists(function (Builder $live) use ($alias) {
                    $live->selectRaw('1')->from('chat_watch_sessions as cw')
                        ->join('n_active_streams as cs', 'cs.id', '=', 'cw.host_stream_id')
                        ->join('chat_messages as cm', 'cm.id', '=', 'cw.message_id')
                        ->whereColumn('cw.room_id', $alias.'.room_id')->where('cw.active', true)
                        ->whereNull('cm.deleted_at')->where('cs.status', 'active')
                        ->whereColumn('cs.content_type', 'cw.content_type')->whereColumn('cs.content_key', 'cw.content_id')
                        ->where('cs.last_ping', '>=', now()->subMinutes(3));
                });
        });
    }
}
