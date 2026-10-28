<?php

namespace App\Support\Chat;

use App\Models\SessionTokenModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class ChatLiveChannels
{
    public static function store(): string
    {
        return config('cache.stores.'.config('chat.cache_store').'.driver') === 'redis' && config('cache.stores.chat_socket')
            ? 'chat_socket' : config('chat.cache_store');
    }
    private function cache() { return Cache::store(self::store()); }
    private function index(int $room): string { return 'chat:feed-index:'.hash('sha256', (string) config('broadcasting.connections.reverb.app_id')).':'.$room; }

    public function issue(Request $request, int $room, string $socket, array $auth): array
    {
        if (! config('chat.socket_protocol_enabled')) { return $auth; }
        $session = SessionTokenModel::find($request->attributes->get('auth_session_token_id'));
        if (! $session) { return $auth; }
        $token = Str::random(64);
        $key = 'chat:socket-ticket:'.hash('sha256', $token);
        $controlChannel = 'private-chat-control.'.$room.'.'.bin2hex(random_bytes(16));
        $control = ChatChannels::authorization($socket, $controlChannel);
        $expires = min(now()->timestamp + $auth['expires_in'] + ChatChannels::GRACE_SECONDS, $session->access_expires_at->timestamp);
        $feed = $request->boolean('messages') ? ChatChannels::authorization($socket, 'private-chat-feed.'.$room.'.'.substr(hash('sha256', $token), 0, 32)) : null;
        $claim = ['room_id' => $room, 'socket_id' => $socket, 'channel' => $auth['channel'], 'control_channel' => $control['channel'], 'ticket_key' => $key,
            'feed_channel' => $feed['channel'] ?? null, 'expires' => $expires,
            'user_id' => (string) $session->user_id, 'device_id' => (string) $session->device_id,
            'session_id' => $session->getKey(), 'session_hash' => hash('sha256', $session->access_token),
            'playback_hash' => hash('sha256', (string) $request->header('X-Playback-Token'))];
        try {
            $this->cache()->put($key, $claim, max(1, $expires - now()->timestamp));
            $this->cache()->put('chat:control-ticket:'.hash('sha256', $control['channel']), $key, max(1, $expires - now()->timestamp));
            if ($feed) { $this->indexTicket($room, $key, min($expires, now()->timestamp + 90)); }
        } catch (\Throwable) { return $auth; }
        return $auth + ['control_channel' => $control['channel'], 'control_auth' => $control['auth'], 'control_token' => $token,
            'feed_channel' => $feed['channel'] ?? null, 'feed_auth' => $feed['auth'] ?? null];
    }

    private function indexTicket(int $room, string $key, int $expires): void
    {
        $index = $this->index($room);
        if (app()->environment('testing') && config('chat.cache_store') === 'array') {
            $this->cache()->put($index, array_replace($this->cache()->get($index, []), [$key => $expires]), 600);
        } else {
            $redis = Redis::connection('chat_socket');
            $redis->zadd($index, $expires, $key);
            $redis->expire($index, 600);
        }
    }

    public function ticket(string $token): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) { return null; }
        $claim = $this->cache()->get('chat:socket-ticket:'.hash('sha256', $token));
        return $claim && $claim['expires'] > now()->timestamp ? $claim : null;
    }

    public function ticketForControlChannel(string $channel): ?array
    {
        if (! preg_match('/^private-chat-control\.([0-9]+)\.([a-f0-9]{32})\.lease\.[0-9]+$/', $channel, $matches)) { return null; }
        $room = (int) $matches[1];
        $prefix = 'chat:socket-ticket:';
        // Ticket lookup is indexed by room in Redis to avoid a key scan on Reverb's event loop.
        $key = $this->cache()->get('chat:control-ticket:'.hash('sha256', $channel));
        if (! is_string($key) || ! str_starts_with($key, $prefix)) { return null; }
        $claim = $this->cache()->get($key);
        return $claim && (int) $claim['room_id'] === $room && ($claim['control_channel'] ?? null) === $channel
            && $claim['expires'] > now()->timestamp ? $claim : null;
    }

    public function renew(array $claim, bool $visible): void
    {
        if ($claim['feed_channel']) {
            $this->indexTicket($claim['room_id'], $claim['ticket_key'],
                $visible ? min($claim['expires'], now()->timestamp + 90) : 0);
        }
    }

    public function viewers(int $room): array
    {
        $index = $this->index($room);
        if (app()->environment('testing') && config('chat.cache_store') === 'array') {
            $keys = array_keys(array_filter($this->cache()->get($index, []), fn ($expiry) => $expiry > now()->timestamp));
        } else {
            $redis = Redis::connection('chat_socket');
            $redis->zremrangebyscore($index, '-inf', now()->timestamp);
            $keys = $redis->zrangebyscore($index, now()->timestamp + 1, '+inf');
        }
        if ($keys === []) { return []; }
        return array_values(array_filter($this->cache()->many($keys), fn ($claim) => $claim && $claim['expires'] > now()->timestamp));
    }
}
