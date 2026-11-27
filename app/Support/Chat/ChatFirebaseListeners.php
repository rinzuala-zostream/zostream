<?php

namespace App\Support\Chat;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

/** Presence of a foreground account listener, independent of room membership. */
final class ChatFirebaseListeners
{
    private const KEY = 'chat:firebase:account-listeners';

    public function renew(string $userId, int $expiresAt): void
    {
        $cache = Cache::store(config('chat.cache_store'));
        $store = $cache->getStore();
        if ($store instanceof RedisStore) {
            $store->connection()->eval(<<<'LUA'
                local previous = redis.call('ZSCORE', KEYS[1], ARGV[1])
                if not previous or tonumber(previous) < tonumber(ARGV[2]) then
                    redis.call('ZADD', KEYS[1], ARGV[2], ARGV[1])
                end
                redis.call('EXPIRE', KEYS[1], 600)
                LUA, 1, $store->getPrefix().self::KEY, $userId, $expiresAt);

            return;
        }

        $cache->lock(self::KEY.':lock', 3)->block(1, function () use ($cache, $userId, $expiresAt): void {
            $leases = array_filter($cache->get(self::KEY, []), fn (int $expiry) => $expiry > now()->timestamp);
            $leases[$userId] = max($expiresAt, $leases[$userId] ?? 0);
            $cache->put(self::KEY, $leases, 600);
        });
    }

    /** @return list<string> */
    public function active(): array
    {
        $cache = Cache::store(config('chat.cache_store'));
        $store = $cache->getStore();
        $now = now()->timestamp;
        if ($store instanceof RedisStore) {
            return $store->connection()->eval(<<<'LUA'
                redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
                return redis.call('ZRANGE', KEYS[1], 0, -1)
                LUA, 1, $store->getPrefix().self::KEY, $now);
        }

        return array_map('strval', array_keys(array_filter($cache->get(self::KEY, []), fn (int $expiry) => $expiry > $now)));
    }
}
