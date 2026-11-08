<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class NexframeSocialAnalytics
{
    private array $insightIssues = [];

    private array $insightLimitations = [];

    private array $queries = [];

    public function report(Carbon $from, Carbon $to, bool $refresh = false): array
    {
        $cache = Cache::store(config('services.nexframe_dashboard.business_cache_store', 'file'));
        $key = 'nexframe:social:v4:'.sha1($from->toDateString().'|'.$to->toDateString().'|'.json_encode(config('services.nexframe_dashboard')));

        if ($refresh) {
            $cache->forget($key);
        }

        return $cache->remember($key, now()->addMinutes(5), function () use ($from, $to, $cache) {
            $instagram = $this->instagram($from, $to);
            $facebook = $this->facebook($from, $to);
            foreach (['instagram', 'facebook'] as $platform) {
                $account = &${$platform};
                $identity = hash('sha256', (string) config('services.nexframe_dashboard.'.$platform.'.access_token'));
                $successKey = 'nexframe:last-success:'.$platform.':'.$identity;
                if ($account['healthy']) {
                    $cache->forever($successKey, $account['last_successful_sync']);
                } else {
                    $account['last_successful_sync'] = $cache->get($successKey);
                }
                unset($account);
            }
            $posts = collect([...$instagram['posts'], ...$facebook['posts']])
                ->sortByDesc('published_at')
                ->values();
            $published = $posts->groupBy(fn (array $post) => substr($post['published_at'] ?? '', 0, 10))
                ->map(fn ($rows) => $this->nullableCollectionSum($rows->pluck('engagement')));

            foreach (['instagram', 'facebook'] as $platform) {
                $account = &${$platform};
                $account['follower_history'] = app(NexframeSocialHistory::class)->observe($platform, $account, $from, $to);
                $account['daily_engagement'] = array_replace($account['follower_history']['daily_engagement'], $account['daily_engagement']);
                unset($account);
            }
            $dailySources = collect(['instagram' => $instagram, 'facebook' => $facebook])
                ->map(fn ($account) => $account['daily_engagement'] ?? [])
                ->filter(fn ($rows) => ! empty($rows));
            $dailyDates = $dailySources->flatMap(fn ($rows) => array_keys($rows))->unique()->sort();
            $daily = $dailyDates->mapWithKeys(fn ($date) => [
                $date => (int) $dailySources->sum(fn ($rows) => $rows[$date] ?? 0),
            ])->all();
            $dailyAvailability = $dailyDates->mapWithKeys(fn ($date) => [
                $date => $dailySources->filter(fn ($rows) => array_key_exists($date, $rows))->keys()->all(),
            ])->all();

            return [
                'instagram' => $instagram,
                'facebook' => $facebook,
                'connected_platforms' => collect([$instagram, $facebook])->where('connected', true)->count(),
                'followers' => $this->nullableSum([$instagram['followers'], $facebook['followers']]),
                'engagement' => $this->nullableSum([$instagram['engagement'], $facebook['engagement']]),
                'interactions' => $this->nullableSum([$instagram['insights']['interactions'] ?? null, $facebook['insights']['interactions'] ?? null]),
                'reach' => $this->nullableSum([$instagram['insights']['reach'] ?? null, $facebook['insights']['reach'] ?? null]),
                'views' => $this->nullableSum([$instagram['insights']['views'] ?? null, $facebook['insights']['views'] ?? null]),
                'impressions' => $this->nullableSum([$instagram['insights']['impressions'] ?? null, $facebook['insights']['impressions'] ?? null]),
                'saves' => $this->nullableSum([$instagram['insights']['saves'] ?? null, $facebook['insights']['saves'] ?? null]),
                'shares' => $this->nullableSum([$instagram['insights']['shares'] ?? null, $facebook['insights']['shares'] ?? null]),
                'posts_count' => $posts->count(),
                'posts' => $posts->all(),
                'content_types' => $posts->groupBy('type')->map(fn ($rows, $type) => [
                    'type' => $type,
                    'posts' => $rows->count(),
                    'engagement' => (int) $rows->sum('engagement'),
                    'reach' => $this->nullableCollectionSum($rows->pluck('reach')),
                    'views' => $this->nullableCollectionSum($rows->pluck('views')),
                ])->values()->all(),
                'daily_engagement' => $daily,
                'daily_engagement_availability' => $dailyAvailability,
                'engagement_by_publish_date' => $published->all(),
                'daily_engagement_platforms' => collect(['instagram' => $instagram, 'facebook' => $facebook])->filter(fn ($account) => ! empty($account['daily_engagement']))->keys()->all(),
                'posts_complete' => ($instagram['posts_complete'] ?? true) && ($facebook['posts_complete'] ?? true),
                'synced_at' => now()->toIso8601String(),
            ];
        });
    }

    private function instagram(Carbon $from, Carbon $to): array
    {
        $config = config('services.nexframe_dashboard.instagram', []);
        $token = trim((string) ($config['access_token'] ?? ''));
        $accountId = trim((string) ($config['account_id'] ?? 'me')) ?: 'me';
        $empty = $this->emptyPlatform('instagram', 'Instagram Business', filled($token));

        if (! $token) {
            return $empty;
        }

        try {
            $base = rtrim((string) ($config['base_url'] ?? 'https://graph.instagram.com'), '/');
            $this->insightIssues = [];
            $this->insightLimitations = [];
            try {
                $profile = $this->get($base.'/'.$accountId, [
                    'fields' => 'id,username,account_type,media_count,followers_count,follows_count,profile_picture_url',
                    'access_token' => $token,
                ]);
            } catch (Throwable) {
                $profile = $this->get($base.'/'.$accountId, [
                    'fields' => 'id,username,account_type',
                    'access_token' => $token,
                ]);
            }
            try {
                $media = $this->get($base.'/'.$accountId.'/media', [
                    'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
                    'limit' => 100,
                    'since' => $from->timestamp,
                    'until' => $to->timestamp,
                    'access_token' => $token,
                ]);
            } catch (Throwable) {
                $media = $this->get($base.'/'.$accountId.'/media', [
                    'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
                    'limit' => 100,
                    'access_token' => $token,
                ]);
            }
            $media = $this->paginate($base.'/'.$accountId.'/media', $media, $token, $from);
            $posts = collect($media['data'] ?? [])->map(function (array $item) {
                $likes = isset($item['like_count']) ? (int) $item['like_count'] : null;
                $comments = isset($item['comments_count']) ? (int) $item['comments_count'] : null;
                $insights = $this->insightValues(data_get($item, 'insights.data', []));
                $shares = $this->firstMetric($insights, ['shares']);
                $saved = $this->firstMetric($insights, ['saved', 'saves']);
                $totalInteractions = $this->firstMetric($insights, ['total_interactions']);
                $engagement = $totalInteractions ?? $this->nullableSum([$likes, $comments, $shares, $saved]);

                return [
                    'id' => (string) ($item['id'] ?? ''),
                    'platform' => 'instagram',
                    'type' => strtolower((string) ($item['media_type'] ?? 'post')),
                    'caption' => trim((string) ($item['caption'] ?? 'Instagram post')),
                    'image' => $item['thumbnail_url'] ?? $item['media_url'] ?? null,
                    'permalink' => $item['permalink'] ?? null,
                    'published_at' => $item['timestamp'] ?? null,
                    'likes' => $likes,
                    'comments' => $comments,
                    'shares' => $shares,
                    'saves' => $saved,
                    'reach' => $this->firstMetric($insights, ['reach']),
                    'views' => $this->firstMetric($insights, ['views', 'plays', 'video_views']),
                    'impressions' => $this->firstMetric($insights, ['impressions']),
                    'engagement' => $engagement,
                ];
            })->filter(fn (array $post) => filled($post['id']))
                ->filter(fn (array $post) => $this->withinRange($post['published_at'], $from, $to))
                ->values();
            $posts = $this->enrichMediaInsights($posts, $base, $token, 'instagram');

            $insights = $this->groupedInsights($base.'/'.$accountId.'/insights', [
                'reach,views,accounts_engaged,total_interactions',
                'likes,comments,saves,shares,reposts',
                'profile_links_taps',
            ], [
                'metric_type' => 'total_value',
                'period' => 'day',
                'since' => $from->timestamp,
                'until' => $to->timestamp,
                'access_token' => $token,
            ]);

            $daily = $this->dailyInsights($base.'/'.$accountId.'/insights', 'total_interactions', $token, $from, $to, true);
            $normalizedInsights = $this->socialInsights($insights, $posts);

            return [
                ...$empty,
                'connected' => true,
                'healthy' => true,
                'account_id' => $profile['id'] ?? $accountId,
                'username' => $profile['username'] ?? null,
                'account_type' => $profile['account_type'] ?? null,
                'followers' => isset($profile['followers_count']) ? (int) $profile['followers_count'] : null,
                'following' => isset($profile['follows_count']) ? (int) $profile['follows_count'] : null,
                'media_count' => isset($profile['media_count']) ? (int) $profile['media_count'] : null,
                'profile_picture' => $profile['profile_picture_url'] ?? null,
                'engagement' => $posts->isEmpty() ? 0 : $this->nullableCollectionSum($posts->pluck('engagement')),
                'insights' => $normalizedInsights,
                'insight_issues' => $this->insightIssues,
                'insight_limitations' => $this->insightLimitations,
                'insights_status' => $this->insightsStatus($normalizedInsights, $posts),
                'daily_engagement' => $daily,
                'posts_complete' => $media['complete'],
                'last_successful_sync' => now()->toIso8601String(),
                'posts' => $posts->all(),
                'message' => null,
            ];
        } catch (Throwable) {
            return [...$empty, 'connected' => false, 'message' => 'Instagram sync failed. Check the token, account ID and granted permissions.'];
        }
    }

    private function facebook(Carbon $from, Carbon $to): array
    {
        $config = config('services.nexframe_dashboard.facebook', []);
        $token = trim((string) ($config['access_token'] ?? ''));
        $pageId = trim((string) ($config['page_id'] ?? ''));
        $version = trim((string) ($config['api_version'] ?? ''));
        $configured = filled($token) && filled($pageId) && filled($version);
        $empty = $this->emptyPlatform('facebook', 'Facebook Page', $configured);

        if (! $configured) {
            return $empty;
        }

        try {
            $base = rtrim((string) ($config['base_url'] ?? 'https://graph.facebook.com'), '/').'/'.$version;
            $this->insightIssues = [];
            $this->insightLimitations = [];
            try {
                $profile = $this->get($base.'/'.$pageId, [
                    'fields' => 'id,name,username,fan_count,followers_count,picture.type(large)',
                    'access_token' => $token,
                ]);
            } catch (Throwable) {
                $profile = $this->get($base.'/'.$pageId, [
                    'fields' => 'id,name',
                    'access_token' => $token,
                ]);
            }
            $feedUrl = $base.'/'.$pageId.'/posts';
            $feedQuery = [
                'fields' => 'id,message,created_time,permalink_url,full_picture',
                'limit' => 100,
                'since' => $from->timestamp,
                'until' => $to->timestamp,
                'access_token' => $token,
            ];
            $feed = $this->paginate($feedUrl, $this->get($feedUrl, $feedQuery), $token, $from);
            $postMetrics = $this->facebookPostMetrics($feedUrl, $feedQuery, $token, $from);
            $posts = collect($feed['data'] ?? [])->map(function (array $item) use ($postMetrics) {
                $metrics = $postMetrics[(string) ($item['id'] ?? '')] ?? [];
                $likes = $metrics['likes'] ?? null;
                $comments = $metrics['comments'] ?? null;
                $shares = $metrics['shares'] ?? null;
                $insights = $this->insightValues(data_get($item, 'insights.data', []));
                $views = $this->firstMetric($insights, ['post_media_view', 'post_video_views']);
                $engagement = collect([$likes, $comments, $shares])->contains(fn ($value) => $value === null)
                    ? null
                    : (int) collect([$likes, $comments, $shares])->sum();

                return [
                    'id' => (string) ($item['id'] ?? ''),
                    'platform' => 'facebook',
                    'type' => str_contains((string) ($item['permalink_url'] ?? ''), '/reel/') ? 'reel' : 'post',
                    'caption' => trim((string) ($item['message'] ?? 'Facebook post')),
                    'image' => $item['full_picture'] ?? null,
                    'permalink' => $item['permalink_url'] ?? null,
                    'published_at' => $item['created_time'] ?? null,
                    'likes' => $likes,
                    'comments' => $comments,
                    'shares' => $shares,
                    'saves' => null,
                    'reach' => $this->firstMetric($insights, ['post_impressions_unique']),
                    'views' => $views,
                    'impressions' => $this->firstMetric($insights, ['post_impressions']),
                    'engagement' => $engagement,
                ];
            })->filter(fn (array $post) => filled($post['id']))
                ->filter(fn (array $post) => $this->withinRange($post['published_at'], $from, $to))
                ->values();
            $posts = $this->enrichMediaInsights($posts, $base, $token, 'facebook');

            $insights = $this->groupedInsights($base.'/'.$pageId.'/insights', [
                'page_media_view,page_video_views,page_views_total',
                'page_daily_follows,page_daily_unfollows_unique',
                'page_post_engagements,page_total_actions',
            ], [
                'period' => 'day',
                'since' => $from->toDateString(),
                'until' => $to->copy()->addDay()->toDateString(),
                'access_token' => $token,
            ]);

            $daily = $this->dailyInsights($base.'/'.$pageId.'/insights', 'page_post_engagements', $token, $from, $to);

            // Reach and impression metrics used by older Graph versions are no
            // longer valid Page/Post Insights metrics in the configured API.
            // Keep them unavailable instead of presenting a fabricated zero.
            $this->insightLimitations['reach'] ??= 'Unavailable from Facebook Page Insights for this API version';
            $this->insightLimitations['impressions'] ??= 'Unavailable from Facebook Page Insights for this API version';
            $normalizedInsights = $this->socialInsights($insights, $posts, [
                'views' => ['page_media_view', 'page_video_views'],
                'profile_views' => ['page_views_total'],
                'interactions' => ['page_post_engagements'],
                'link_clicks' => ['page_total_actions'],
                'follows' => ['page_daily_follows'],
                'unfollows' => ['page_daily_unfollows_unique'],
            ]);
            foreach (['likes', 'comments', 'shares'] as $metric) {
                $normalizedInsights[$metric] = $this->nullableCollectionSum($posts->pluck($metric));
            }

            return [
                ...$empty,
                'connected' => true,
                'healthy' => true,
                'account_id' => $profile['id'] ?? $pageId,
                'username' => $profile['username'] ?? null,
                'name' => $profile['name'] ?? null,
                'followers' => isset($profile['followers_count']) ? (int) $profile['followers_count'] : (isset($profile['fan_count']) ? (int) $profile['fan_count'] : null),
                'profile_picture' => data_get($profile, 'picture.data.url'),
                'engagement' => $posts->isEmpty() ? 0 : $this->nullableCollectionSum($posts->pluck('engagement')),
                'insights' => $normalizedInsights,
                'insight_issues' => $this->insightIssues,
                'insight_limitations' => $this->insightLimitations,
                'insights_status' => $this->insightsStatus($normalizedInsights, $posts),
                'daily_engagement' => $daily,
                'posts_complete' => $feed['complete'],
                'last_successful_sync' => now()->toIso8601String(),
                'posts' => $posts->all(),
                'message' => null,
            ];
        } catch (Throwable) {
            return [...$empty, 'connected' => false, 'message' => 'Facebook sync failed. Check the Page token, Page ID, API version and granted permissions.'];
        }
    }

    private function get(string $url, array $query): array
    {
        $this->queries[$url] = $query;

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 250)
            ->get($url, $query)
            ->throw()
            ->json();
    }

    private function facebookPostMetrics(string $url, array $baseQuery, string $token, Carbon $from): array
    {
        $definitions = [
            'likes' => ['field' => 'likes.limit(0).summary(true)', 'path' => 'likes.summary.total_count', 'permission' => 'pages_read_engagement'],
            'comments' => ['field' => 'comments.limit(0).summary(true)', 'path' => 'comments.summary.total_count', 'permission' => 'pages_read_user_content'],
            'shares' => ['field' => 'shares', 'path' => 'shares.count', 'permission' => null],
        ];
        $metrics = [];

        foreach ($definitions as $name => $definition) {
            try {
                $query = [...$baseQuery, 'fields' => 'id,'.$definition['field'], 'access_token' => $token];
                $response = $this->paginate($url, $this->get($url, $query), $token, $from);
                foreach ($response['data'] ?? [] as $row) {
                    $id = (string) ($row['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $value = data_get($row, $definition['path']);
                    // Graph omits the shares object when the count is zero.
                    if ($name === 'shares' && $value === null) {
                        $value = 0;
                    }
                    if (is_numeric($value)) {
                        $metrics[$id][$name] = (int) $value;
                    }
                }
            } catch (Throwable $exception) {
                $reason = $this->issueReason($exception);
                if ($definition['permission'] && str_starts_with($reason, 'Permission')) {
                    $reason = 'Permission required: '.$definition['permission'];
                }
                $this->insightIssues['post_'.$name] = $reason;
            }
        }

        return $metrics;
    }

    private function paginate(string $url, array $first, string $token, Carbon $from): array
    {
        $page = $first;
        $rows = $first['data'] ?? [];
        $query = $this->queries[$url] ?? ['access_token' => $token, 'limit' => 100];
        $visited = [];
        $complete = true;

        while (! empty($page['paging']['next'])) {
            $dates = collect($page['data'] ?? [])->map(fn ($row) => $row['timestamp'] ?? $row['created_time'] ?? null)->filter();
            if ($dates->isNotEmpty() && $dates->every(fn ($date) => Carbon::parse($date)->lt($from))) {
                break;
            }
            $after = data_get($page, 'paging.cursors.after');
            if (! $after) {
                parse_str((string) parse_url($page['paging']['next'], PHP_URL_QUERY), $nextQuery);
                $after = $nextQuery['after'] ?? null;
            }
            if (! $after || isset($visited[$after]) || count($visited) >= 100) {
                $complete = false;
                break;
            }
            $visited[$after] = true;
            try {
                // Reuse our validated endpoint and token; never request a URL
                // supplied in paging.next or forward credentials to its host.
                $page = $this->get($url, [...$query, 'after' => $after]);
                $rows = [...$rows, ...($page['data'] ?? [])];
            } catch (Throwable) {
                $complete = false;
                break;
            }
        }

        return ['data' => collect($rows)->unique('id')->values()->all(), 'complete' => $complete];
    }

    private function dailyInsights(string $url, string $metric, string $token, Carbon $from, Carbon $to, bool $instagram = false): array
    {
        $query = [
            'metric' => $metric,
            'period' => 'day',
            'since' => $from->timestamp,
            'until' => $to->copy()->addSecond()->timestamp,
            'access_token' => $token,
        ];
        if ($instagram) {
            $query['metric_type'] = 'time_series';
        }
        $response = $this->optionalGet($url, $query);
        $daily = [];
        foreach ($response['data'] ?? [] as $row) {
            if (($row['name'] ?? '') !== $metric) {
                continue;
            }
            foreach ($row['values'] ?? [] as $point) {
                if (! isset($point['end_time']) || ! is_numeric($point['value'] ?? null)) {
                    continue;
                }
                $date = Carbon::parse($point['end_time'])->subDay()->toDateString();
                if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                    $daily[$date] = (int) $point['value'];
                }
            }
        }

        return $daily;
    }

    private function optionalGet(string $url, array $query): array
    {
        try {
            return $this->get($url, $query);
        } catch (Throwable $exception) {
            $reason = $this->issueReason($exception);
            $this->insightIssues[(string) ($query['metric'] ?? 'insights')] = $reason;

            return [];
        }
    }

    private function groupedInsights(string $url, array $metricGroups, array $query): array
    {
        $insights = [];
        $metrics = collect($metricGroups)->flatMap(fn ($group) => explode(',', $group))->unique();
        try {
            $responses = Http::pool(fn (Pool $pool) => $metrics->map(fn ($metric) => $pool->as($metric)
                ->acceptJson()->connectTimeout(5)->timeout(15)->get($url, [...$query, 'metric' => $metric]))->all());
            foreach ($metrics as $metric) {
                $response = $responses[$metric] ?? null;
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    $values = $this->insightValues($response->json('data') ?? []);
                    $insights = array_replace($insights, $values);
                    if (! array_key_exists($metric, $values)) {
                        $this->insightLimitations[$metric] = 'No data returned for these dates';
                    }
                } else {
                    $code = $response instanceof \Illuminate\Http\Client\Response ? (int) $response->json('error.code') : 0;
                    $this->insightIssues[$metric] = match ($code) {
                        10, 190, 200 => 'Permission or token required',
                        100 => 'Metric unsupported for this account or API version',
                        4, 17, 32, 613 => 'Rate limited; retry later',
                        default => 'Metric temporarily unavailable',
                    };
                }
            }
        } catch (Throwable) {
            foreach ($metrics as $metric) {
                $this->insightIssues[$metric] = 'Metric temporarily unavailable';
            }
        }

        return $insights;
    }

    private function enrichMediaInsights($posts, string $base, string $token, string $platform)
    {
        if ($posts->isEmpty()) {
            return $posts;
        }

        $metric = $platform === 'instagram'
            ? 'reach,views,total_interactions,saved,shares'
            : 'post_media_view';

        try {
            $responses = [];
            foreach ($posts->chunk(20) as $batch) {
                $responses = array_replace($responses, Http::pool(fn (Pool $pool) => $batch->mapWithKeys(function (array $post) use ($pool, $base, $token, $metric) {
                    return [$post['id'] => $pool->as($post['id'])
                        ->acceptJson()
                        ->connectTimeout(5)
                        ->timeout(15)
                        ->get($base.'/'.$post['id'].'/insights', [
                            'metric' => $metric,
                            'period' => 'lifetime',
                            'access_token' => $token,
                        ])];
                })->all()));
            }
        } catch (Throwable) {
            $this->insightIssues['post_views'] = 'Metric temporarily unavailable';

            return $posts;
        }

        if ($platform === 'facebook') {
            $usable = collect($responses)->contains(function ($response) {
                return $response instanceof \Illuminate\Http\Client\Response
                    && $response->successful()
                    && ! empty($response->json('data'));
            });
            if (! $usable) {
                $failed = collect($responses)->first(fn ($response) => $response instanceof \Illuminate\Http\Client\Response && ! $response->successful());
                if ($failed instanceof \Illuminate\Http\Client\Response) {
                    $this->insightIssues['post_views'] = $this->responseIssue($failed);
                } else {
                    $this->insightLimitations['post_views'] = 'No post-level view data returned for this content';
                }
            }
        }

        return $posts->map(function (array $post) use ($responses, $platform) {
            $response = $responses[$post['id']] ?? null;
            if ($response instanceof Throwable || ! $response?->successful()) {
                return $post;
            }

            $insights = $this->insightValues($response->json('data') ?? []);
            if ($platform === 'facebook') {
                $post['views'] = $this->firstMetric($insights, ['post_media_view']) ?? $post['views'];

                return $post;
            }

            $post['reach'] = $this->firstMetric($insights, ['reach']) ?? $post['reach'];
            $post['views'] = $this->firstMetric($insights, ['views', 'plays', 'video_views']) ?? $post['views'];
            $post['shares'] = $this->firstMetric($insights, ['shares']) ?? $post['shares'];
            $post['saves'] = $this->firstMetric($insights, ['saved', 'saves']) ?? $post['saves'];
            $post['engagement'] = $this->firstMetric($insights, ['total_interactions']) ?? $this->nullableSum([$post['likes'], $post['comments'], $post['shares'], $post['saves']]);

            return $post;
        });
    }

    private function emptyPlatform(string $platform, string $label, bool $configured): array
    {
        return [
            'platform' => $platform,
            'label' => $label,
            'configured' => $configured,
            'connected' => false,
            'healthy' => false,
            'followers' => null,
            'engagement' => null,
            'insights' => $this->emptyInsights(),
            'posts' => [],
            'posts_complete' => true,
            'daily_engagement' => [],
            'insight_issues' => [],
            'insight_limitations' => [],
            'insights_status' => 'unavailable',
            'last_successful_sync' => null,
            'message' => $configured ? 'Connection has not been verified.' : 'Credentials are not configured.',
        ];
    }

    private function issueReason(Throwable $exception): string
    {
        if (! $exception instanceof \Illuminate\Http\Client\RequestException) {
            return 'Metric temporarily unavailable';
        }

        return $this->responseIssue($exception->response);
    }

    private function responseIssue(\Illuminate\Http\Client\Response $response): string
    {
        return match ((int) $response->json('error.code')) {
            10, 190, 200 => 'Permission or token required',
            100 => 'Metric unsupported for this account or API version',
            4, 17, 32, 613 => 'Rate limited; retry later',
            default => 'Metric temporarily unavailable',
        };
    }

    private function insightsStatus(array $insights, $posts): string
    {
        $accountDataAvailable = collect($insights)
            ->except(['engagement_rate'])
            ->contains(fn ($value) => $value !== null);
        $postDataAvailable = collect($posts)->contains(fn (array $post) => collect([
            $post['likes'] ?? null,
            $post['comments'] ?? null,
            $post['shares'] ?? null,
            $post['reach'] ?? null,
            $post['views'] ?? null,
        ])->contains(fn ($value) => $value !== null));

        if (! $accountDataAvailable && ! $postDataAvailable) {
            return 'unavailable';
        }

        return $this->insightIssues ? 'partial' : 'healthy';
    }

    private function nullableSum(array $values): ?int
    {
        $available = collect($values)->filter(fn ($value) => $value !== null);

        return $available->isEmpty() ? null : (int) $available->sum();
    }

    private function nullableCollectionSum($values): ?int
    {
        $available = collect($values)->filter(fn ($value) => $value !== null);

        return $available->isEmpty() ? null : (int) $available->sum();
    }

    private function insightValues(array $rows): array
    {
        return collect($rows)->mapWithKeys(function (array $row) {
            $value = data_get($row, 'total_value.value');
            if ($value === null && array_key_exists('value', $row)) {
                $value = $row['value'];
            }
            if ($value === null && isset($row['values'])) {
                $value = collect($row['values'])->sum(fn (array $point) => (float) ($point['value'] ?? 0));
            }

            return isset($row['name']) && is_numeric($value)
                ? [(string) $row['name'] => (int) round((float) $value)]
                : [];
        })->all();
    }

    private function firstMetric(array $metrics, array $names): ?int
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $metrics) && $metrics[$name] !== null) {
                return (int) $metrics[$name];
            }
        }

        return null;
    }

    private function socialInsights(array $metrics, $posts, array $map = []): array
    {
        $map = array_merge([
            'reach' => ['reach'],
            'views' => ['views'],
            'impressions' => ['impressions'],
            'accounts_engaged' => ['accounts_engaged'],
            'interactions' => ['total_interactions'],
            'likes' => ['likes'],
            'comments' => ['comments'],
            'saves' => ['saves', 'saved'],
            'shares' => ['shares'],
            'reposts' => ['reposts'],
            'profile_views' => ['profile_views', 'profile_visits'],
            'link_clicks' => ['profile_links_taps'],
            'follows' => ['follows'],
            'unfollows' => ['unfollows'],
        ], $map);

        $insights = [];
        foreach ($map as $key => $names) {
            $insights[$key] = $this->firstMetric($metrics, $names);
        }

        // Account insights stay null when unavailable. Summed lifetime post
        // metrics cannot substitute for account metrics in the selected dates.
        $insights['engagement_rate'] = ($insights['reach'] ?? 0) > 0
            ? round(($insights['interactions'] / $insights['reach']) * 100, 2)
            : null;

        return $insights;
    }

    private function emptyInsights(): array
    {
        return array_fill_keys([
            'reach', 'views', 'impressions', 'accounts_engaged', 'interactions',
            'likes', 'comments', 'saves', 'shares', 'reposts', 'profile_views',
            'link_clicks', 'follows', 'unfollows', 'engagement_rate',
        ], null);
    }

    private function withinRange(?string $timestamp, Carbon $from, Carbon $to): bool
    {
        if (! $timestamp) {
            return false;
        }

        try {
            return Carbon::parse($timestamp)->betweenIncluded($from, $to);
        } catch (Throwable) {
            return false;
        }
    }
}
