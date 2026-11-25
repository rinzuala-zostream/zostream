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
        $room = ChatDatabase::connection()->table('chat_rooms')->find($row->room_id);
        $message = ChatDatabase::connection()->table('chat_messages')->where('room_id', $row->room_id)->find($row->message_id);
        if (! $room || $room->closed || ! $message || Carbon::parse($message->created_at)->lt(now()->subDays(config('chat.retention_days')))) { return; }
        $blocked = ChatDatabase::connection()->table('chat_blocks')->where('blocked_user_id', $message->user_id)->pluck('user_id')->flip();
        $channels = []; $freshChannels = [];
        foreach ($this->eligibleViewers($room, $viewers) as $viewer) {
            if ($blocked->has($viewer['user_id'])) { continue; }
            $channels[] = $viewer['feed_channel'];
            if (isset($viewer['message_cursor']) && (int) $message->id > (int) $viewer['message_cursor']) {
                $freshChannels[] = $viewer['feed_channel'];
            }
        }
        if ($channels === []) { return; }
        $data = ['overlay_live' => ! $message->deleted_at && Carbon::parse($message->created_at)->gte(now()->subSeconds(10)),
            'kind' => $message->deleted_at ? 'deleted' : 'created', 'event_id' => (string) $row->id,
            'room_id' => (string) $room->id, 'message' => app(ChatService::class)->message($message)];
        $data['message'] = ChatDisplayNames::messages([$data['message']])[0];
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
        $freshChannels = $data['overlay_live'] ? array_unique($freshChannels) : [];
        $historyChannels = array_values(array_diff(array_unique($channels), $freshChannels));
        foreach ([$freshChannels, $historyChannels] as $index => $group) {
            foreach (array_chunk(array_values($group), 500) as $chunk) {
                $renew();
                $this->publish($chunk, array_replace($data, ['overlay_live' => $index === 0]));
            }
        }
    }

    public function deliverTyping(object $row, callable $renew): bool
    {
        if (! config('chat.enabled')) { throw new \RuntimeException('Chat is disabled'); }
        if (! config('chat.socket_protocol_enabled')) { return false; }
        $roomId = (int) $row->room_id;
        $viewers = app(ChatLiveChannels::class)->viewers($roomId);
        if ($viewers === []) { return false; }
        $room = ChatDatabase::connection()->table('chat_rooms')->find($roomId);
        if (! $room || $room->closed) { return false; }
        $eligible = iterator_to_array($this->eligibleViewers($room, $viewers), false);
        if ($eligible === []) { return false; }

        $typing = app(ChatInteractions::class)->activeTypists($roomId);
        if ($room->type === 'invite' && $typing !== []) {
            $host = ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $roomId)->value('host_user_id');
            $members = ChatDatabase::connection()->table('chat_room_invites')->where('room_id', $roomId)
                ->where('status', 'accepted')->whereIn('invitee_user_id', array_column($typing, 'user_id'))
                ->pluck('invitee_user_id')->push($host)->flip();
            $typing = array_values(array_filter($typing, fn ($user) => $members->has($user['user_id'])));
        }
        $typing = ChatDisplayNames::typists($typing);
        $typistIds = array_column($typing, 'user_id');
        $blocks = [];
        if ($typistIds !== []) {
            foreach (ChatDatabase::connection()->table('chat_blocks')->whereIn('user_id', $typistIds)
                ->orWhereIn('blocked_user_id', $typistIds)->cursor() as $block) {
                $blocks[$block->user_id][$block->blocked_user_id] = true;
                $blocks[$block->blocked_user_id][$block->user_id] = true;
            }
        }

        // Viewers with the same filtered snapshot share a single publish.
        $groups = [];
        foreach ($eligible as $viewer) {
            $uid = $viewer['user_id'];
            $users = array_values(array_filter($typing, fn ($user) => $user['user_id'] !== $uid && ! isset($blocks[$uid][$user['user_id']])));
            $key = json_encode(array_column($users, 'user_id'), JSON_THROW_ON_ERROR);
            $groups[$key]['users'] = $users;
            $groups[$key]['channels'][] = $viewer['feed_channel'];
        }
        foreach ($groups as $group) {
            foreach (array_chunk(array_values(array_unique($group['channels'])), 100) as $chunk) {
                $renew();
                $now = now()->timestamp;
                $users = [];
                foreach ($group['users'] as $user) {
                    if ($user['expires_at'] > $now) {
                        $users[] = ['user_id' => $user['user_id'], 'name' => $user['name'], 'is_admin' => $user['is_admin'],
                            'expires_in' => $user['expires_at'] - $now];
                    }
                }
                $this->publish($chunk, ['room_id' => (string) $roomId, 'event_id' => (string) $row->id,
                    'users' => $users], 'chat.typing');
            }
        }

        return true;
    }

    private function eligibleViewers(object $room, array $viewers): iterable
    {
        // One batch query per 500 sessions, instead of one authenticated HTTP
        // request per recipient. Token rotation, revocation and expiry are current.
        $sessions = collect();
        foreach (array_chunk(array_unique(array_column($viewers, 'session_id')), 500) as $ids) {
            $sessions = $sessions->merge(DB::table('session_tokens')->whereIn('id', $ids)->where('access_expires_at', '>', now())->get());
        }
        $sessions = $sessions->keyBy('id');
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
            if ($viewer['feed_channel']) { yield $viewer; }
        }
    }

    private function publish(array $channels, array $data, string $event = 'chat.message'): void
    {
        $config = config('broadcasting.connections.reverb');
        $body = json_encode(['name' => $event, 'channels' => $channels, 'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = '/apps/'.rawurlencode($config['app_id']).'/events';
        $query = ['auth_key' => $config['key'], 'auth_timestamp' => (string) time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
        ksort($query);
        $query['auth_signature'] = hash_hmac('sha256', "POST\n".$path."\n".http_build_query($query, '', '&', PHP_QUERY_RFC3986), $config['secret']);
        $host = $config['options']['scheme'].'://'.$config['options']['host'].':'.$config['options']['port'];
        Http::connectTimeout(2)->timeout(5)->withBody($body, 'application/json')->post($host.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->throw();
    }
}
