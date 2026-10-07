<?php

namespace App\Support\Analytics;

use Illuminate\Support\Facades\Redis;

class AnalyticsPresenceStore
{
    public const TTL_SECONDS = 150;

    private const INDEX_KEY = 'analytics:presence:devices';

    private const USER_INDEX_KEY = 'analytics:presence:users';

    private const PLATFORMS = ['ios', 'tvos', 'android', 'tv'];

    private const META_PREFIX = 'analytics:presence:device:';

    public function heartbeat(array $presence): array
    {
        $now = now('UTC');
        $member = $this->member((string) $presence['user_id'], (string) $presence['device_id']);
        $metadata = [
            'user_id' => (string) $presence['user_id'],
            'device_id' => (string) $presence['device_id'],
            'platform' => (string) $presence['platform'],
            'app_version' => (string) ($presence['app_version'] ?? ''),
            'build_number' => (string) ($presence['build_number'] ?? ''),
            'device_model' => (string) ($presence['device_model'] ?? ''),
            'network_type' => (string) ($presence['network_type'] ?? ''),
            'last_seen_at' => $now->toIso8601String(),
        ];

        $redis = Redis::connection('cache');
        $cutoff = $now->copy()->subSeconds(self::TTL_SECONDS)->timestamp;
        $userDeviceKey = $this->userDeviceKey($metadata['user_id']);
        $platformUserDeviceKey = $this->platformUserDeviceKey($metadata['platform'], $metadata['user_id']);
        $redis->command('zadd', [self::INDEX_KEY, $now->timestamp, $member]);
        $redis->command('zadd', [self::USER_INDEX_KEY, $now->timestamp, (string) $presence['user_id']]);
        $redis->command('zadd', [$this->platformDeviceKey($metadata['platform']), $now->timestamp, $member]);
        $redis->command('zadd', [$this->platformUserKey($metadata['platform']), $now->timestamp, (string) $presence['user_id']]);
        $redis->command('zremrangebyscore', [$userDeviceKey, '-inf', $cutoff]);
        $redis->command('zadd', [$userDeviceKey, $now->timestamp, $member]);
        $redis->command('expire', [$userDeviceKey, self::TTL_SECONDS]);
        $redis->command('zremrangebyscore', [$platformUserDeviceKey, '-inf', $cutoff]);
        $redis->command('zadd', [$platformUserDeviceKey, $now->timestamp, $member]);
        $redis->command('expire', [$platformUserDeviceKey, self::TTL_SECONDS]);
        $redis->command('setex', [self::META_PREFIX.$member, self::TTL_SECONDS, json_encode($metadata, JSON_THROW_ON_ERROR)]);

        return $metadata;
    }

    public function offline(string $userId, string $deviceId, ?string $platform = null): void
    {
        $member = $this->member($userId, $deviceId);
        $redis = Redis::connection('cache');
        $redis->command('zrem', [self::INDEX_KEY, $member]);
        $this->removeUserDevice($redis, self::USER_INDEX_KEY, $this->userDeviceKey($userId), $userId, $member);
        if ($platform !== null && in_array($platform, self::PLATFORMS, true)) {
            $redis->command('zrem', [$this->platformDeviceKey($platform), $member]);
            $this->removeUserDevice(
                $redis,
                $this->platformUserKey($platform),
                $this->platformUserDeviceKey($platform, $userId),
                $userId,
                $member
            );
        }
        $redis->command('del', [self::META_PREFIX.$member]);
    }

    public function snapshot(array $filters = []): array
    {
        $redis = Redis::connection('cache');
        $cutoff = now('UTC')->subSeconds(self::TTL_SECONDS)->timestamp;
        $this->removeExpired($redis, $cutoff);

        $platform = trim((string) ($filters['platform'] ?? ''));
        $deviceIndex = $platform !== '' ? $this->platformDeviceKey($platform) : self::INDEX_KEY;
        $userIndex = $platform !== '' ? $this->platformUserKey($platform) : self::USER_INDEX_KEY;
        $needsMetadataFilter = trim((string) ($filters['app_version'] ?? '')) !== ''
            || trim((string) ($filters['user_id'] ?? '')) !== '';
        $members = $redis->command(
            $needsMetadataFilter ? 'zrangebyscore' : 'zrevrange',
            $needsMetadataFilter
                ? [$deviceIndex, $cutoff + 1, '+inf']
                : [$deviceIndex, 0, 99]
        );

        if (! is_array($members) || $members === []) {
            return $this->emptySnapshot();
        }

        $keys = array_map(fn (string $member) => self::META_PREFIX.$member, $members);
        $encoded = $redis->command('mget', [$keys]);
        $devices = [];
        $missing = [];

        foreach ($members as $index => $member) {
            $value = is_array($encoded) ? ($encoded[$index] ?? null) : null;
            if (! is_string($value) || $value === '') {
                $missing[] = $member;

                continue;
            }

            $metadata = json_decode($value, true);
            if (! is_array($metadata)) {
                $missing[] = $member;

                continue;
            }
            if (! $this->matches($metadata, $filters)) {
                continue;
            }
            $devices[] = $metadata;
        }

        if ($missing !== []) {
            $redis->command('zrem', array_merge([self::INDEX_KEY], $missing));
        }

        usort($devices, fn (array $left, array $right) => strcmp(
            (string) ($right['last_seen_at'] ?? ''),
            (string) ($left['last_seen_at'] ?? '')
        ));

        if ($needsMetadataFilter) {
            $onlineDevices = count($devices);
            $onlineUsers = count(array_unique(array_column($devices, 'user_id')));
            $platforms = $this->platformCountsFromDevices($devices);
        } else {
            $onlineDevices = (int) $redis->command('zcard', [$deviceIndex]);
            $onlineUsers = (int) $redis->command('zcard', [$userIndex]);
            $platforms = [];
            foreach ($platform !== '' ? [$platform] : self::PLATFORMS as $name) {
                $deviceCount = (int) $redis->command('zcard', [$this->platformDeviceKey($name)]);
                $userCount = (int) $redis->command('zcard', [$this->platformUserKey($name)]);
                if ($deviceCount > 0 || $userCount > 0) {
                    $platforms[] = ['platform' => $name, 'devices' => $deviceCount, 'users' => $userCount];
                }
            }
        }
        usort($platforms, fn (array $left, array $right) => $right['devices'] <=> $left['devices']);

        return [
            'available' => true,
            'online_users' => $onlineUsers,
            'online_devices' => $onlineDevices,
            'heartbeat_interval_seconds' => 60,
            'presence_ttl_seconds' => self::TTL_SECONDS,
            'as_of' => now('UTC')->toIso8601String(),
            'platforms' => $platforms,
            'devices' => array_slice($devices, 0, 100),
            'devices_truncated' => $onlineDevices > 100,
        ];
    }

    private function member(string $userId, string $deviceId): string
    {
        return hash('sha256', $userId."\0".$deviceId);
    }

    private function matches(array $metadata, array $filters): bool
    {
        foreach (['platform', 'app_version', 'user_id'] as $filter) {
            $expected = trim((string) ($filters[$filter] ?? ''));
            if ($expected !== '' && (string) ($metadata[$filter] ?? '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function removeExpired(mixed $redis, int $cutoff): void
    {
        $redis->command('zremrangebyscore', [self::INDEX_KEY, '-inf', $cutoff]);
        $redis->command('zremrangebyscore', [self::USER_INDEX_KEY, '-inf', $cutoff]);
        foreach (self::PLATFORMS as $platform) {
            $redis->command('zremrangebyscore', [$this->platformDeviceKey($platform), '-inf', $cutoff]);
            $redis->command('zremrangebyscore', [$this->platformUserKey($platform), '-inf', $cutoff]);
        }
    }

    private function platformCountsFromDevices(array $devices): array
    {
        $platforms = [];
        foreach ($devices as $device) {
            $platform = $device['platform'] ?: 'unknown';
            $platforms[$platform] ??= ['platform' => $platform, 'devices' => 0, 'users' => []];
            $platforms[$platform]['devices']++;
            $platforms[$platform]['users'][$device['user_id']] = true;
        }

        return array_values(array_map(fn (array $row) => [
            'platform' => $row['platform'],
            'devices' => $row['devices'],
            'users' => count($row['users']),
        ], $platforms));
    }

    private function platformDeviceKey(string $platform): string
    {
        return 'analytics:presence:platform:'.$platform.':devices';
    }

    private function platformUserKey(string $platform): string
    {
        return 'analytics:presence:platform:'.$platform.':users';
    }

    private function userDeviceKey(string $userId): string
    {
        return 'analytics:presence:user:'.hash('sha256', $userId).':devices';
    }

    private function platformUserDeviceKey(string $platform, string $userId): string
    {
        return 'analytics:presence:platform:'.$platform.':user:'.hash('sha256', $userId).':devices';
    }

    private function removeUserDevice(
        mixed $redis,
        string $userIndex,
        string $userDeviceIndex,
        string $userId,
        string $member
    ): void {
        $cutoff = now('UTC')->subSeconds(self::TTL_SECONDS)->timestamp;
        $redis->command('zremrangebyscore', [$userDeviceIndex, '-inf', $cutoff]);
        $redis->command('zrem', [$userDeviceIndex, $member]);
        if ((int) $redis->command('zcard', [$userDeviceIndex]) === 0) {
            $redis->command('zrem', [$userIndex, $userId]);
            $redis->command('del', [$userDeviceIndex]);
        }
    }

    private function emptySnapshot(): array
    {
        return [
            'available' => true,
            'online_users' => 0,
            'online_devices' => 0,
            'heartbeat_interval_seconds' => 60,
            'presence_ttl_seconds' => self::TTL_SECONDS,
            'as_of' => now('UTC')->toIso8601String(),
            'platforms' => [],
            'devices' => [],
            'devices_truncated' => false,
        ];
    }
}
