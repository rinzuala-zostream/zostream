<?php

namespace Tests\Unit;

use App\Services\NexframeSocialAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NexframeSocialAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nexframe_dashboard.business_cache_store', 'array');
        Storage::fake('local');
    }

    public function test_instagram_profile_and_posts_are_normalized_for_the_dashboard(): void
    {
        config()->set('services.nexframe_dashboard.instagram', [
            'access_token' => 'test-token',
            'account_id' => 'me',
            'base_url' => 'https://graph.instagram.com',
        ]);
        config()->set('services.nexframe_dashboard.facebook', []);

        Http::fake([
            'https://graph.instagram.com/me/insights*' => Http::response(['data' => [
                ['name' => 'reach', 'total_value' => ['value' => 5000]],
                ['name' => 'views', 'total_value' => ['value' => 7200]],
                ['name' => 'total_interactions', 'total_value' => ['value' => 40]],
                ['name' => 'saves', 'total_value' => ['value' => 9]],
                ['name' => 'shares', 'total_value' => ['value' => 4]],
            ]]),
            'https://graph.instagram.com/me/media*' => Http::response(['data' => [[
                'id' => 'post-1',
                'caption' => 'New Zo Stream release',
                'media_type' => 'IMAGE',
                'timestamp' => '2026-10-10T10:00:00+0000',
                'like_count' => 25,
                'comments_count' => 5,
            ]]]),
            'https://graph.instagram.com/post-1/insights*' => Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 800]]],
                ['name' => 'views', 'values' => [['value' => 1100]]],
                ['name' => 'total_interactions', 'values' => [['value' => 36]]],
                ['name' => 'saved', 'values' => [['value' => 4]]],
                ['name' => 'shares', 'values' => [['value' => 2]]],
            ]]),
            'https://graph.instagram.com/me*' => Http::response([
                'id' => 'ig-1',
                'username' => 'zostream',
                'account_type' => 'BUSINESS',
                'followers_count' => 1200,
                'media_count' => 75,
            ]),
        ]);

        $report = app(NexframeSocialAnalytics::class)->report(
            Carbon::parse('2026-10-01')->startOfDay(),
            Carbon::parse('2026-10-14')->endOfDay(),
            true
        );

        $this->assertTrue($report['instagram']['healthy']);
        $this->assertSame(1200, $report['followers']);
        $this->assertSame(36, $report['engagement']);
        $this->assertSame(5000, $report['reach']);
        $this->assertSame(7200, $report['views']);
        $this->assertSame(9, $report['saves']);
        $this->assertSame(4, $report['shares']);
        $this->assertSame('instagram', $report['posts'][0]['platform']);
        $this->assertSame(800, $report['posts'][0]['reach']);
        $this->assertSame([], $report['daily_engagement']);
        $this->assertSame(36, $report['engagement_by_publish_date']['2026-10-10']);
    }

    public function test_post_pagination_keeps_all_posts_and_daily_activity_uses_insight_dates(): void
    {
        config()->set('services.nexframe_dashboard.instagram', ['access_token' => 'test-token', 'account_id' => 'me']);
        config()->set('services.nexframe_dashboard.facebook', []);
        Http::fake([
            'https://graph.instagram.com/me/media*' => function ($request) {
                $after = $request['after'] ?? null;
                $rows = collect(range($after ? 26 : 1, $after ? 30 : 25))->map(fn ($id) => [
                    'id' => (string) $id, 'timestamp' => '2026-10-10T12:00:00+0000', 'like_count' => 1,
                ])->all();

                return Http::response(['data' => $rows, ...($after ? [] : ['paging' => ['next' => 'https://untrusted.example/never-fetch', 'cursors' => ['after' => 'page2']]])]);
            },
            'https://graph.instagram.com/me/insights*' => function ($request) {
                return Http::response(['data' => ($request['metric_type'] ?? '') === 'time_series'
                    ? [['name' => 'total_interactions', 'values' => [['value' => 9, 'end_time' => '2026-10-12T00:00:00+0000']]]]
                    : []]);
            },
            'https://graph.instagram.com/me*' => Http::response(['id' => 'ig-test', 'username' => 'zostream']),
            'https://graph.instagram.com/*/insights*' => Http::response(['data' => []]),
        ]);

        $report = app(NexframeSocialAnalytics::class)->report(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-14')->endOfDay(), true);
        $this->assertCount(30, $report['posts']);
        $this->assertTrue($report['posts_complete']);
        $this->assertSame(['2026-10-11' => 9], $report['daily_engagement']);
        $this->assertSame(30, $report['engagement_by_publish_date']['2026-10-10']);
        $this->assertNull($report['instagram']['insights']['reach']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'untrusted.example'));
    }

    public function test_failed_insights_preserve_basic_posts_and_report_permission_status(): void
    {
        config()->set('services.nexframe_dashboard.instagram', ['access_token' => 'test-token', 'account_id' => 'me']);
        config()->set('services.nexframe_dashboard.facebook', []);
        Http::fake([
            'https://graph.instagram.com/me/media*' => Http::response(['data' => [['id' => 'p', 'timestamp' => '2026-10-10T12:00:00+0000', 'like_count' => 4]]]),
            'https://graph.instagram.com/*/insights*' => Http::response(['error' => ['code' => 200]], 403),
            'https://graph.instagram.com/me*' => Http::response(['id' => 'ig-test']),
        ]);

        $report = app(NexframeSocialAnalytics::class)->report(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-14')->endOfDay(), true);
        $this->assertTrue($report['instagram']['healthy']);
        $this->assertCount(1, $report['posts']);
        $this->assertNull($report['reach']);
        $this->assertSame([], $report['daily_engagement']);
        $this->assertSame('Permission or token required', $report['instagram']['insight_issues']['reach']);
    }

    public function test_facebook_fetches_post_metrics_independently_and_normalizes_supported_insights(): void
    {
        config()->set('services.nexframe_dashboard.instagram', []);
        config()->set('services.nexframe_dashboard.facebook', [
            'access_token' => 'page-token',
            'page_id' => 'page-1',
            'api_version' => 'v26.0',
            'base_url' => 'https://graph.facebook.com',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/page-1/posts')) {
                $fields = (string) ($request['fields'] ?? '');
                $post = ['id' => 'page-1_post-1'];
                if (str_contains($fields, 'likes.')) {
                    $post['likes'] = ['summary' => ['total_count' => 7]];
                } elseif (str_contains($fields, 'comments.')) {
                    $post['comments'] = ['summary' => ['total_count' => 3]];
                } elseif (str_contains($fields, 'shares')) {
                    $post['shares'] = ['count' => 2];
                } else {
                    $post += [
                        'message' => 'Zo Stream release',
                        'created_time' => '2026-10-15T12:00:00+0000',
                        'permalink_url' => 'https://www.facebook.com/post-1',
                        'full_picture' => 'https://example.test/post.jpg',
                    ];
                }

                return Http::response(['data' => [$post]]);
            }
            if (str_contains($request->url(), '/page-1_post-1/insights')) {
                return Http::response(['data' => [[
                    'name' => 'post_media_view',
                    'values' => [['value' => 90]],
                ]]]);
            }
            if (str_contains($request->url(), '/page-1/insights')) {
                $metric = (string) ($request['metric'] ?? '');
                $values = [
                    'page_media_view' => 120,
                    'page_video_views' => 90,
                    'page_views_total' => 10,
                    'page_daily_follows' => 4,
                    'page_daily_unfollows_unique' => 1,
                    'page_post_engagements' => 20,
                    'page_total_actions' => 5,
                ];

                return Http::response(['data' => isset($values[$metric]) ? [[
                    'name' => $metric,
                    'values' => [['value' => $values[$metric], 'end_time' => '2026-10-16T00:00:00+0000']],
                ]] : []]);
            }
            if (str_contains($request->url(), '/page-1')) {
                return Http::response(['id' => 'page-1', 'name' => 'Zo Stream', 'username' => 'zostream', 'followers_count' => 1166]);
            }

            return Http::response([], 404);
        });

        $report = app(NexframeSocialAnalytics::class)->report(
            Carbon::parse('2026-10-14')->startOfDay(),
            Carbon::parse('2026-10-19')->endOfDay(),
            true,
        );
        $facebook = $report['facebook'];
        $post = $facebook['posts'][0];

        $this->assertTrue($facebook['healthy']);
        $this->assertSame('partial', $facebook['insights_status']);
        $this->assertSame(1166, $facebook['followers']);
        $this->assertSame(7, $post['likes']);
        $this->assertSame(3, $post['comments']);
        $this->assertSame(2, $post['shares']);
        $this->assertSame(12, $post['engagement']);
        $this->assertSame(90, $post['views']);
        $this->assertSame(120, $facebook['insights']['views']);
        $this->assertSame(20, $facebook['insights']['interactions']);
        $this->assertSame(7, $facebook['insights']['likes']);
        $this->assertSame(3, $facebook['insights']['comments']);
        $this->assertSame(2, $facebook['insights']['shares']);
        $this->assertSame(['2026-10-15' => 20], $facebook['daily_engagement']);
        $this->assertNull($facebook['insights']['reach']);
    }

    public function test_facebook_permission_failures_do_not_turn_missing_post_metrics_into_zero(): void
    {
        config()->set('services.nexframe_dashboard.instagram', []);
        config()->set('services.nexframe_dashboard.facebook', [
            'access_token' => 'limited-page-token',
            'page_id' => 'page-1',
            'api_version' => 'v26.0',
            'base_url' => 'https://graph.facebook.com',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/page-1/posts')) {
                $fields = (string) ($request['fields'] ?? '');
                if (str_contains($fields, 'likes.') || str_contains($fields, 'comments.')) {
                    return Http::response(['error' => ['code' => 10]], 403);
                }
                if (str_contains($fields, 'shares')) {
                    return Http::response(['data' => [['id' => 'page-1_post-1']]]);
                }

                return Http::response(['data' => [[
                    'id' => 'page-1_post-1',
                    'message' => 'Basic post remains available',
                    'created_time' => '2026-10-15T12:00:00+0000',
                ]]]);
            }
            if (str_contains($request->url(), '/insights')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($request->url(), '/page-1')) {
                return Http::response(['id' => 'page-1', 'name' => 'Zo Stream']);
            }

            return Http::response([], 404);
        });

        $facebook = app(NexframeSocialAnalytics::class)->report(
            Carbon::parse('2026-10-14')->startOfDay(),
            Carbon::parse('2026-10-19')->endOfDay(),
            true,
        )['facebook'];
        $post = $facebook['posts'][0];

        $this->assertTrue($facebook['healthy']);
        $this->assertSame('partial', $facebook['insights_status']);
        $this->assertNull($post['likes']);
        $this->assertNull($post['comments']);
        $this->assertSame(0, $post['shares']);
        $this->assertNull($post['engagement']);
        $this->assertSame('Permission required: pages_read_engagement', $facebook['insight_issues']['post_likes']);
        $this->assertSame('Permission required: pages_read_user_content', $facebook['insight_issues']['post_comments']);
    }
}
