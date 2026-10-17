<?php

namespace Tests\Unit;

use App\Services\NexframeSocialHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NexframeSocialHistoryTest extends TestCase
{
    public function test_history_records_observed_days_and_retains_only_real_daily_activity(): void
    {
        Storage::fake('local');
        $service = app(NexframeSocialHistory::class);
        $from = Carbon::parse('2026-10-14');
        $to = Carbon::parse('2026-10-16')->endOfDay();
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $account = ['healthy' => true, 'account_id' => 'ig', 'followers' => 100, 'daily_engagement' => ['2026-10-13' => 12, '2026-10-14' => 3]];
        $service->observe('instagram', $account, $from, $to);
        $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));
        $history = $service->observe('instagram', [...$account, 'followers' => 125, 'daily_engagement' => ['2026-10-14' => 9]], $from, $to);
        $this->assertSame(25, $history['change']);
        $this->assertSame(['2026-10-14', '2026-10-16'], array_column($history['points'], 'date'));
        $this->assertSame(['2026-10-14' => 9], $history['daily_engagement']);
        $later = $service->observe('instagram', [...$account, 'daily_engagement' => []], $from, $to);
        $this->assertSame(['2026-10-14' => 9], $later['daily_engagement']);
        $facebook = $service->observe('facebook', [...$account, 'followers' => 400, 'daily_engagement' => []], $from, $to);
        $this->assertNull($facebook['change']);
        $this->assertSame([], $facebook['daily_engagement']);
    }
}
