<?php

namespace Tests\Unit;

use App\Support\Analytics\AnalyticsPresenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class AnalyticsPresenceStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_heartbeat_writes_only_expiring_redis_presence(): void
    {
        CarbonImmutable::setTestNow('2026-10-07T12:00:00Z');
        $connection = Mockery::mock();
        $commands = [];
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($connection);
        $connection->shouldReceive('command')->times(11)->andReturnUsing(
            function (string $command, array $arguments) use (&$commands) {
                $commands[] = [$command, $arguments];
            }
        );

        $result = app(AnalyticsPresenceStore::class)->heartbeat([
            'user_id' => 'user-1',
            'device_id' => 'device-1',
            'platform' => 'android',
            'app_version' => '3.0.0',
        ]);

        $this->assertSame('2026-10-07T12:00:00+00:00', $result['last_seen_at']);
        $this->assertTrue(collect($commands)->contains(fn (array $call) => $call[0] === 'zadd'
            && $call[1][0] === 'analytics:presence:devices'
            && $call[1][1] === 1791374400
        ));
        $this->assertTrue(collect($commands)->contains(fn (array $call) => $call[0] === 'setex'
            && str_starts_with($call[1][0], 'analytics:presence:device:')
            && $call[1][1] === 150
            && str_contains($call[1][2], '"user_id":"user-1"')
        ));
    }

    public function test_snapshot_counts_unique_users_and_devices(): void
    {
        CarbonImmutable::setTestNow('2026-10-07T12:00:00Z');
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($connection);
        $connection->shouldReceive('command')->andReturnUsing(function (string $command, array $arguments) {
            if ($command === 'zrevrange') {
                return ['member-a', 'member-b'];
            }
            if ($command === 'mget') {
                return [
                    json_encode(['user_id' => 'user-1', 'device_id' => 'phone', 'platform' => 'android', 'last_seen_at' => '2026-10-07T11:59:55+00:00']),
                    json_encode(['user_id' => 'user-1', 'device_id' => 'tv', 'platform' => 'tv', 'last_seen_at' => '2026-10-07T11:59:50+00:00']),
                ];
            }
            if ($command !== 'zcard') {
                return 1;
            }

            return match ($arguments[0]) {
                'analytics:presence:devices' => 2,
                'analytics:presence:users' => 1,
                'analytics:presence:platform:android:devices',
                'analytics:presence:platform:android:users',
                'analytics:presence:platform:tv:devices',
                'analytics:presence:platform:tv:users' => 1,
                default => 0,
            };
        });

        $snapshot = app(AnalyticsPresenceStore::class)->snapshot();

        $this->assertSame(1, $snapshot['online_users']);
        $this->assertSame(2, $snapshot['online_devices']);
        $this->assertCount(2, $snapshot['platforms']);
    }
}
