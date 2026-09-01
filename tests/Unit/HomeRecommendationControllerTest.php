<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V4\HomeRecommendationController;
use App\Services\HomeRecommendationService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class HomeRecommendationControllerTest extends TestCase
{
    public function test_it_uses_trusted_identity_and_paginates_one_section(): void
    {
        $service = Mockery::mock(HomeRecommendationService::class);
        $service->shouldReceive('homepage')
            ->once()
            ->with('trusted-user', 5, 'kids', true)
            ->andReturn([
                'user' => 'trusted-user',
                'history_size' => 12,
                'top_picks_for_you' => [
                    ['id' => 'a', 'title' => 'A', 'status' => 'Published'],
                    ['id' => 'b', 'title' => 'B', 'status' => 'Published'],
                    ['id' => 'c', 'title' => 'C', 'status' => 'Published'],
                    ['id' => 'd', 'title' => 'D', 'status' => 'Published'],
                    ['id' => 'e', 'title' => 'E', 'status' => 'Published'],
                ],
            ]);

        $request = Request::create('/api/v4/recommendations/home', 'GET', [
            'user_id' => 'attacker-controlled-user',
            'section' => 'top_picks_for_you',
            'page' => 2,
            'per_page' => 2,
            'age_restriction' => 'true',
        ]);
        $request->headers->set('X-Mode', 'kids');
        $request->merge(['auth_user_id' => 'trusted-user']);

        $response = (new HomeRecommendationController($service))->home($request);
        $payload = $response->getData(true);
        $section = $payload['data']['sections']['top_picks_for_you'];

        $this->assertTrue($payload['success']);
        $this->assertSame('trusted-user', $payload['data']['user']);
        $this->assertSame('kids', $payload['data']['content_mode']);
        $this->assertTrue($payload['data']['age_restriction']);
        $this->assertSame(['c', 'd'], array_column($section['items'], 'id'));
        $this->assertSame([
            'current_page' => 2,
            'per_page' => 2,
            'returned' => 2,
            'has_more' => true,
            'next_page' => 3,
            'previous_page' => 1,
        ], $section['pagination']);
    }
}
