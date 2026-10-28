<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ChatVisibility
{
    private function key(int $room, string $user): string
    {
        return 'chat:visible:'.$room.':'.hash('sha256', $user);
    }

    public function update(Request $request, int $room, bool $visible): void
    {
        $key = $this->key($room, (string) $request->input('auth_user_id'));
        $device = hash('sha256', $request->input('auth_device_id').':'.$request->input('visibility_id', 'legacy'));
        $sequence = (int) $request->input('visibility_sequence');
        try {
            $cache = Cache::store(config('chat.cache_store'));
            $cache->lock($key.':lock', 3)->block(1, function () use ($cache, $key, $device, $visible, $sequence): void {
                $devices = array_filter($cache->get($key, []), fn ($state) => $state['expires'] > now()->timestamp);
                if (($devices[$device]['sequence'] ?? 0) >= $sequence) { return; }
                $devices[$device] = ['expires' => now()->addSeconds(90)->timestamp, 'visible' => $visible, 'sequence' => $sequence];
                $cache->put($key, $devices, 90);
            });
        } catch (\Throwable) {
            // Presence is best effort; a cache outage must not hide messages.
        }
    }

    public function visible(int $room, string $user): bool
    {
        try {
            return count(array_filter(Cache::store(config('chat.cache_store'))->get($this->key($room, $user), []),
                fn ($state) => $state['expires'] > now()->timestamp && $state['visible'])) > 0;
        } catch (\Throwable) { return false; }
    }
}
