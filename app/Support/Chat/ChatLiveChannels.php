<?php

namespace App\Support\Chat;

use App\Models\SessionTokenModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ChatLiveChannels
{
    public static function store(): string
    {
        return config('cache.stores.'.config('chat.cache_store').'.driver') === 'redis' && config('cache.stores.chat_socket')
            ? 'chat_socket' : config('chat.cache_store');
    }
    private function cache() { return Cache::store(self::store()); }

    public function issue(Request $request, int $room, string $socket, array $auth): array
    {
        if (! config('chat.socket_protocol_enabled')) { return $auth; }
        $session = SessionTokenModel::find($request->attributes->get('auth_session_token_id'));
        if (! $session) { return $auth; }
        $token = Str::random(64);
        $key = 'chat:socket-ticket:'.hash('sha256', $token);
        $controlChannel = 'private-watch-control.'.$room.'.'.bin2hex(random_bytes(16));
        $control = ChatChannels::authorization($socket, $controlChannel);
        $expires = min(now()->timestamp + $auth['expires_in'] + ChatChannels::GRACE_SECONDS, $session->access_expires_at->timestamp);
        $claim = ['room_id' => $room, 'socket_id' => $socket, 'control_channel' => $control['channel'], 'expires' => $expires,
            'user_id' => (string) $session->user_id, 'device_id' => (string) $session->device_id];
        try {
            $this->cache()->put($key, $claim, max(1, $expires - now()->timestamp));
            $this->cache()->put('chat:control-ticket:'.hash('sha256', $control['channel']), $key, max(1, $expires - now()->timestamp));
        } catch (\Throwable) { return $auth; }
        return $auth + ['control_channel' => $control['channel'], 'control_auth' => $control['auth'], 'control_token' => $token];
    }

    public function ticketForControlChannel(string $channel): ?array
    {
        if (! preg_match('/^private-watch-control\.([0-9]+)\.([a-f0-9]{32})\.lease\.[0-9]+$/', $channel, $matches)) { return null; }
        $room = (int) $matches[1];
        $prefix = 'chat:socket-ticket:';
        // Ticket lookup is indexed by room in Redis to avoid a key scan on Reverb's event loop.
        $key = $this->cache()->get('chat:control-ticket:'.hash('sha256', $channel));
        if (! is_string($key) || ! str_starts_with($key, $prefix)) { return null; }
        $claim = $this->cache()->get($key);
        return $claim && (int) $claim['room_id'] === $room && ($claim['control_channel'] ?? null) === $channel
            && $claim['expires'] > now()->timestamp ? $claim : null;
    }
}
