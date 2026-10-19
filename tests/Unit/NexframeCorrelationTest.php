<?php

namespace Tests\Unit;

use App\Http\Controllers\NexframeAnalyticsController;
use App\Services\NexframeBusinessAnalytics;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class NexframeCorrelationTest extends TestCase
{
    public function test_lagged_analysis_matches_a_later_outcome_to_the_source_day(): void
    {
        $source = [1, 9, 3, 7, 2, 8, 4, 6, 0, 5];
        $rows = collect($source)->map(function ($value, $index) use ($source) {
            return [
                'date' => '2026-09-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'social' => $value,
                'installs' => $index === 0 ? 11 : $source[$index - 1],
            ];
        })->all();
        $controller = new NexframeAnalyticsController(Mockery::mock(NexframeBusinessAnalytics::class));
        $method = new ReflectionMethod($controller, 'laggedCorrelations');

        $result = $method->invoke($controller, $rows, 'social', 'installs');

        $this->assertSame(1, $result['best']['lag_days']);
        $this->assertSame(1.0, $result['best']['correlation']);
    }
}
