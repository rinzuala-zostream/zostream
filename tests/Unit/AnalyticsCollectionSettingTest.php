<?php

namespace Tests\Unit;

use App\Support\Analytics\AnalyticsCollectionSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class AnalyticsCollectionSettingTest extends TestCase
{
    public function test_remote_flag_is_cached_and_admin_changes_replace_cached_values(): void
    {
        Cache::flush();
        $setting = new class extends AnalyticsCollectionSetting
        {
            public int $reads = 0;

            public bool $remote = true;

            protected function readRemote(): bool
            {
                $this->reads++;

                return $this->remote;
            }
        };
        $this->assertTrue($setting->enabled());
        $this->assertTrue($setting->enabled());
        $this->assertSame(1, $setting->reads);
        $setting->publish(false);
        $this->assertFalse($setting->enabled());
        $this->assertSame(1, $setting->reads);
        $setting->publish(true);
        $this->assertTrue($setting->enabled());
        $setting->remote = false;
        $this->travel(16)->seconds();
        $this->assertFalse($setting->enabled());
        $this->assertSame(2, $setting->reads);
        $this->travelBack();
    }

    public function test_firebase_outage_fails_closed_and_suppresses_repeated_remote_attempts(): void
    {
        Cache::flush();
        $setting = new class extends AnalyticsCollectionSetting
        {
            public int $reads = 0;

            protected function readRemote(): bool
            {
                $this->reads++;
                throw new RuntimeException('Firebase is unavailable');
            }
        };

        $this->assertFalse($setting->enabled());
        $this->assertFalse($setting->enabled());
        $this->assertSame(1, $setting->reads);

        $this->travel(4)->seconds();
        $this->assertFalse($setting->enabled());
        $this->assertSame(2, $setting->reads);

        $setting->publish(true);
        $this->assertTrue($setting->enabled());
        $this->assertSame(2, $setting->reads);
        $this->travelBack();
    }

    public function test_cache_backend_failure_fails_closed_without_reading_firebase(): void
    {
        Cache::shouldReceive('store')->once()->andThrow(new RuntimeException('Cache unavailable'));
        $setting = new class extends AnalyticsCollectionSetting
        {
            public int $reads = 0;

            protected function readRemote(): bool
            {
                $this->reads++;

                return true;
            }
        };

        $this->assertFalse($setting->enabled());
        $this->assertSame(0, $setting->reads);
    }

    public function test_overlapping_cache_miss_does_not_start_a_second_firebase_read(): void
    {
        $repository = new class
        {
            public array $values = [];

            public object $lock;

            public function __construct()
            {
                $this->lock = new class
                {
                    private bool $held = false;

                    public function block(int $seconds, callable $callback): mixed
                    {
                        if ($this->held) {
                            throw new LockTimeoutException('Another request is refreshing the value.');
                        }

                        $this->held = true;
                        try {
                            return $callback();
                        } finally {
                            $this->held = false;
                        }
                    }
                };
            }

            public function lock(string $key, int $seconds): object
            {
                return $this->lock;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? null;
            }

            public function put(string $key, mixed $value, int $seconds): void
            {
                $this->values[$key] = $value;
            }
        };
        Cache::shouldReceive('store')->twice()->andReturn($repository);

        $setting = new class extends AnalyticsCollectionSetting
        {
            public int $reads = 0;

            public ?bool $overlapResult = null;

            protected function readRemote(): bool
            {
                $this->reads++;
                $this->overlapResult = $this->enabled();

                return true;
            }
        };

        $this->assertTrue($setting->enabled());
        $this->assertFalse($setting->overlapResult);
        $this->assertSame(1, $setting->reads);
    }
}
