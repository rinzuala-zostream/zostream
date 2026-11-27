<?php

namespace App\Support\Chat;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Rechecks room access for a batch of projected Firebase sessions. */
final class ChatRecipientAccess
{
    public function eligibleViewers(object $room, array $viewers, Collection $sessions): iterable
    {
        $banned = ChatDatabase::connection()->table('chat_bans')->whereIn('room_id', [0, $room->id])->where('kind', 'ban')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('user_id')->flip();
        $members = null;
        if ($room->type === 'invite') {
            $invite = ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room->id)->first();
            if (! app(InviteRoomLifecycle::class)->active($invite)) { return; }
            $members = ChatDatabase::connection()->table('chat_room_invites')->where('room_id', $room->id)->where('status', 'accepted')
                ->pluck('invitee_user_id')->push($invite->host_user_id)->flip();
            if ($invite->content_type) {
                $content = ChatContent::find($invite->content_type, $invite->content_id);
                if (! $content || ($content->isPayPerView ?? false)) { return; }
                if ($content->isPremium ?? false) {
                    $eligible = DB::table('n_subscriptions as s')->join('n_plans as p', 'p.id', '=', 's.plan_id')
                        ->whereIn('s.user_id', $members->keys())->where('s.is_active', true)->where('s.end_at', '>=', now()->startOfDay())->pluck('s.user_id')->flip();
                    $members = $members->intersectByKeys($eligible);
                }
            }
        }
        $streams = [];
        if (in_array($room->type, ['movie', 'episode'], true)) {
            foreach (array_chunk(array_unique(array_column($viewers, 'user_id')), 500) as $users) {
                foreach (DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
                    ->whereIn('d.user_id', $users)->where('s.status', 'active')->where('s.content_type', $room->type)
                    ->where('s.content_key', $room->content_id)->where('s.last_ping', '>=', now()->subMinutes(3))
                    ->get(['d.user_id', 'd.device_token', 's.stream_token']) as $stream) {
                    $streams[$stream->user_id.':'.$stream->device_token.':'.hash('sha256', $stream->stream_token)] = true;
                }
            }
        }
        foreach ($viewers as $viewer) {
            $session = $sessions->get($viewer['session_id']);
            if (! $session || $session->user_id !== $viewer['user_id'] || (string) $session->device_id !== $viewer['device_id']
                || ! hash_equals($viewer['session_hash'], hash('sha256', $session->access_token))
                || $banned->has($viewer['user_id'])
                || ($members !== null && ! $members->has($viewer['user_id']))) { continue; }
            if (in_array($room->type, ['movie', 'episode'], true)
                && ! isset($streams[$viewer['user_id'].':'.$viewer['device_id'].':'.$viewer['playback_hash']])) { continue; }
            yield $viewer;
        }
    }
}
