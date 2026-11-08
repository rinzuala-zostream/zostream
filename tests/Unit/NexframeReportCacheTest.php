<?php

namespace Tests\Unit;

use App\Services\NexframeReportCache;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NexframeReportCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nexframe_dashboard.business_cache_store', 'array');
    }

    public function test_refresh_lock_serves_stale_report_without_running_duplicate_queries(): void
    {
        $service = app(NexframeReportCache::class);
        $calls = 0;
        $compute = function () use (&$calls) {
            $calls++;

            return ['value' => $calls, 'source_status' => ['streaming' => true]];
        };
        $service->remember('test-report', $compute);
        $this->travel(6)->minutes();
        $lock = Cache::store('array')->lock('test-report:refresh', 300);
        $this->assertTrue($lock->get());
        $stale = $service->remember('test-report', $compute);
        $this->assertTrue($stale['business_snapshot']['stale']);
        $this->assertSame(1, $calls);
        $lock->release();
        $updated = $service->remember('test-report', $compute);
        $this->assertFalse($updated['business_snapshot']['stale']);
        $this->assertSame(2, $calls);
    }

    public function test_cold_cache_lock_does_not_allow_another_expensive_query(): void
    {
        $lock = Cache::store('array')->lock('preparing-report:refresh', 300);
        $lock->get();
        try {
            app(NexframeReportCache::class)->remember('preparing-report', fn () => $this->fail('A second report build must not run.'));
            $this->fail('Expected a retry response.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('5', $exception->getHeaders()['Retry-After']);
        } finally {
            $lock->release();
        }
    }
}
