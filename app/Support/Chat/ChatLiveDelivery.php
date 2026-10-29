<?php

namespace App\Support\Chat;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Read access is checked in batches before publishing to per-socket feeds.
 * Message bodies never enter the legacy shared metadata channels. */
final class ChatLiveDelivery
{
    public function deliver(object $row, callable $renew): void
    {
        if (! config('chat.enabled')) { throw new \RuntimeException('Chat is disabled'); }
        if (! config('chat.socket_protocol_enabled')) { return; }
        $viewers = app(ChatLiveChannels::class)->viewers((int) $row->room_id);
        if ($viewers === []) { return; }
        $room = DB::table('chat_rooms')->find($row->room_id);
        $message = DB::table('chat_messages')->where('room_id', $row->room_id)->find($row->message_id);
        if (! $room || $room->closed || ! $message || Carbon::parse($message->created_at)->lt(now()->subDays(config('chat.retention_days')))) { return; }

        // One batch query per 500 sessions, instead of one authenticated HTTP
        // request per recipient. Token rotation, revocation and expiry are current.
        $sessions = collect();
        foreach (array_chunk(array_unique(array_column($viewers, 'session_id')), 500) as $ids) {
            $sessions = $sessions->merge(DB::table('session_tokens')->whereIn('id', $ids)->where('access_expires_at', '>', now())->get());
        }
        $sessions = $sessions->keyBy('id');
        $banned = DB::table('chat_bans')->whereIn('room_id', [0, $room->id])->where('kind', 'ban')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('user_id')->flip();
        $blocked = DB::table('chat_blocks')->where('blocked_user_id', $message->user_id)->pluck('user_id')->flip();
        $members = null;
        if ($room->type === 'invite') {
            $invite = DB::table('chat_invite_rooms')->where('room_id', $room->id)->first();
            if (! app(InviteRoomLifecycle::class)->active($invite)) { return; }
            $members = DB::table('chat_room_invites')->where('room_id', $room->id)->where('status', 'accepted')
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
        $channels = [];
        foreach ($viewers as $viewer) {
            $session = $sessions->get($viewer['session_id']);
            if (! $session || $session->user_id !== $viewer['user_id'] || (string) $session->device_id !== $viewer['device_id']
                || ! hash_equals($viewer['session_hash'], hash('sha256', $session->access_token))
                || $banned->has($viewer['user_id']) || $blocked->has($viewer['user_id'])
                || ($members !== null && ! $members->has($viewer['user_id']))) { continue; }
            if (in_array($room->type, ['movie', 'episode'], true)
                && ! isset($streams[$viewer['user_id'].':'.$viewer['device_id'].':'.$viewer['playback_hash']])) { continue; }
            if ($viewer['feed_channel']) { $channels[] = $viewer['feed_channel']; }
        }
        $data = ['kind' => $message->deleted_at ? 'deleted' : 'created', 'event_id' => (string) $row->id,
            'room_id' => (string) $room->id, 'message' => app(ChatService::class)->message($message)];
        // Deliver a watch card with its current state. The separate metadata
        // channel may arrive earlier/later or be recovering its subscription.
        if ($data['message']['watch_invite'] !== null) {
            $watch = app(PrivateWatchService::class)->snapshot((int) $room->id);
            if ($watch !== null) {
                $data['watch'] = $watch + ['event_id' => (string) $row->id];
                $data['message']['watch_invite']['active'] = $watch['active']
                    && $watch['session_id'] === $data['message']['watch_invite']['session_id'];
            }
        }
        foreach (array_chunk(array_unique($channels), 500) as $chunk) {
            $renew();
            $this->publish($chunk, $data);
        }
    }

    private function publish(array $channels, array $data): void
    {
        $config = config('broadcasting.connections.reverb');
        $body = json_encode(['name' => 'chat.message', 'channels' => $channels, 'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = '/apps/'.rawurlencode($config['app_id']).'/events';
        $query = ['auth_key' => $config['key'], 'auth_timestamp' => (string) time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
        ksort($query);
        $query['auth_signature'] = hash_hmac('sha256', "POST\n".$path."\n".http_build_query($query, '', '&', PHP_QUERY_RFC3986), $config['secret']);
        $host = $config['options']['scheme'].'://'.$config['options']['host'].':'.$config['options']['port'];
        Http::connectTimeout(2)->timeout(5)->withBody($body, 'application/json')->post($host.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->throw();
    }
}
