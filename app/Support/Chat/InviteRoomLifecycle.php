<?php

namespace App\Support\Chat;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

final class InviteRoomLifecycle
{
    public function active(?object $room): bool
    {
        return $room && $room->active && ! ($room->closed ?? false)
            && ($room->content_type !== null
                || ($room->idle_expires_at !== null && Carbon::parse($room->idle_expires_at)->isFuture())
                || app(PrivateWatchService::class)->liveSession((int) $room->room_id) !== null);
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
