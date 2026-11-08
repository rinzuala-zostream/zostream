<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;

final class ChatSocketPresence
{
    public function handle(MessageReceived $event): void
    {
        $frame = json_decode($event->message, true);
        if (($frame['event'] ?? null) !== 'chat:presence') { return; }
        $controlChannel = $frame['channel'] ?? null;
        if (! is_string($controlChannel)) { return; }
        $data = $frame['data'] ?? [];
        if (! is_array($data) || ! is_bool($data['visible'] ?? null)
            || ! is_int($data['visibility_sequence'] ?? null) || $data['visibility_sequence'] < 1 || $data['visibility_sequence'] > 9007199254740991
            || ! is_string($data['visibility_id'] ?? null) || ! \Illuminate\Support\Str::isUuid($data['visibility_id'])) { return; }
        try {
            if (! config('chat.enabled') || ! config('chat.socket_protocol_enabled')) { return; }
            $channels = app(ChatLiveChannels::class);
            $claim = $channels->ticketForControlChannel($controlChannel);
            if (! $claim || $claim['socket_id'] !== $event->connection->id()) { return; }
            $member = app(ChannelManager::class)->for($event->connection->app())->find($controlChannel)?->find($event->connection);
            if (! $member) { return; }
            $cache = Cache::store(ChatLiveChannels::store());
            // A device may renew at most once per second; visibility transitions
            // retain their sequence ordering in ChatVisibility.
            if (! $cache->add('chat:socket-rate:'.hash('sha256', $claim['socket_id'].':'.$data['visibility_id'].':'.(int) $data['visible']), true, 1)) { return; }
            $request = new Request(array_merge($data, ['auth_user_id' => $claim['user_id'], 'auth_device_id' => $claim['device_id']]));
            if (app(ChatVisibility::class)->update($request, $claim['room_id'], $data['visible'], true)) {
                $channels->renew($claim, $data['visible']);
            }
            $event->connection->send(json_encode(['event' => 'chat:presence_ack', 'channel' => $controlChannel, 'data' => ['visibility_id' => $data['visibility_id'],
                'visibility_sequence' => $data['visibility_sequence']]], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // No SQL or provider calls on Reverb's event loop. Native clients
            // retain their HTTP fallback when no acknowledgement arrives.
        }
    }
}
