<?php

namespace Tests\Unit;

use App\Services\NexframePlayStoreAnalytics;
use App\Services\NexframePlayStoreReportClient;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class NexframePlayStoreAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nexframe_dashboard.business_cache_store', 'array');
    }

    public function test_unconfigured_source_returns_an_explicit_unavailable_report(): void
    {
        config()->set('services.nexframe_dashboard.play_store', []);
        $client = Mockery::mock(NexframePlayStoreReportClient::class);
        $client->shouldNotReceive('downloadFirst');

        $report = (new NexframePlayStoreAnalytics($client))->report(
            Carbon::parse('2026-10-14')->startOfDay(),
            Carbon::parse('2026-10-19')->endOfDay(),
        );

        $this->assertFalse($report['configured']);
        $this->assertFalse($report['healthy']);
        $this->assertNull($report['metrics']['installs']);
    }

    public function test_reports_are_combined_by_day_and_normalized_for_the_dashboard(): void
    {
        config()->set('services.nexframe_dashboard.play_store', [
            'package_name' => 'in.zostream.app',
            'bucket' => 'pubsite_prod_rev_test',
            'installs_dimension' => 'overview',
            'store_performance_dimension' => 'country',
        ]);

        $installs = implode("\n", [
            'Date,Package Name,Daily User Installs,Daily User Uninstalls,Installs on active devices',
            '2026-10-14,in.zostream.app,10,2,100',
            '2026-10-15,in.zostream.app,12,1,105',
            '2026-10-20,in.zostream.app,99,9,999',
        ]);
        $storeUtf8 = implode("\n", [
            'Date,Package name,Country/region,Store listing acquisitions,Store listing visitors,Store listing conversion rate',
            '2026-10-14,in.zostream.app,IN,10,100,0.10',
            '2026-10-14,in.zostream.app,US,5,50,0.10',
            '2026-10-15,in.zostream.app,IN,20,100,0.20',
        ]);
        $store = "\xFF\xFE".mb_convert_encoding($storeUtf8, 'UTF-16LE', 'UTF-8');
        $client = Mockery::mock(NexframePlayStoreReportClient::class);
        $client->shouldReceive('downloadFirst')->twice()->andReturnUsing(
            fn ($config, $prefix) => str_contains($prefix, '/installs_')
                ? ['name' => $prefix.'_overview.csv', 'contents' => $installs]
                : ['name' => $prefix.'_country.csv', 'contents' => $store],
        );

        $report = (new NexframePlayStoreAnalytics($client))->report(
            Carbon::parse('2026-10-14')->startOfDay(),
            Carbon::parse('2026-10-19')->endOfDay(),
            true,
        );

        $this->assertTrue($report['configured']);
        $this->assertTrue($report['connected']);
        $this->assertTrue($report['healthy']);
        $this->assertSame('healthy', $report['status']);
        $this->assertSame('2026-10-15', $report['as_of']);
        $this->assertSame(22, $report['metrics']['installs']);
        $this->assertSame(3, $report['metrics']['uninstalls']);
        $this->assertSame(105, $report['metrics']['active_device_installs']);
        $this->assertSame(250, $report['metrics']['store_visitors']);
        $this->assertSame(35, $report['metrics']['store_acquisitions']);
        $this->assertSame(14.0, $report['metrics']['store_conversion_rate']);
        $this->assertSame(10, $report['daily']['2026-10-14']['installs']);
        $this->assertSame(150, $report['daily']['2026-10-14']['store_visitors']);
    }
}
