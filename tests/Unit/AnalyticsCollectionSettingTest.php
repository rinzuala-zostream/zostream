<?php

namespace Tests\Unit;

use App\Support\Analytics\AnalyticsCollectionSetting;
use Illuminate\Support\Facades\Cache;
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
}
