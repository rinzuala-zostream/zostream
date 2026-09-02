<?php

namespace Tests\Unit;

use App\Services\HomeRecommendationService;
use App\Services\LiveHomeSectionService;
use Mockery;
use Tests\TestCase;

class HomeRecommendationServiceTest extends TestCase
{
    public function test_live_only_request_skips_ai_and_unrequested_sections(): void
    {
        $liveSections = Mockery::mock(LiveHomeSectionService::class);
        $liveSections->shouldReceive('snapshot')
            ->once()
            ->with('trusted-user', 11, 'adult', false, ['trending_now'], false)
            ->andReturn([
                'signals' => ['watch_position' => [], 'wishlist' => []],
                'sections' => [
                    'trending_now' => [
                        ['id' => 'movie-1', 'title' => 'Movie 1'],
                    ],
                ],
                'version' => 'unused-for-live-only',
            ]);
        $liveSections->shouldNotReceive('filterAiSections');

        config()->set('recommender.script', '/definitely/missing/recommender.py');
        config()->set('recommender.model', '/definitely/missing/model.json.gz');

        $result = (new HomeRecommendationService($liveSections))->homepage(
            'trusted-user',
            11,
            'adult',
            false,
            ['trending_now']
        );

        $this->assertSame('movie-1', $result['trending_now'][0]['id']);
        $this->assertSame([], $result['top_picks_for_you']);
    }
}
