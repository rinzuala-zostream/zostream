<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Analytics\AnalyticsPresenceStore;
use App\Support\Api\V4Response;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnalyticsReportController extends Controller
{
    private const INSIGHT_DIMENSIONS = [
        'platform' => 'platform',
        'app_version' => 'app_version',
        'content_type' => 'content_type',
        'content_id' => 'content_id',
        'state' => 'state',
        'end_reason' => 'end_reason',
        'sdk_version' => 'sdk_version',
        'series_id' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.content.series_id')), 'null')",
        'season_id' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.content.season_id')), 'null')",
        'episode_id' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.content.episode_id')), 'null')",
        'downloaded' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.content.is_downloaded')), 'null')",
        'autoplay' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.content.autoplay')), 'null')",
        'initial_quality' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.initial')), 'null')",
        'final_quality' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.final')), 'null')",
        'video_codec' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.video_codec')), 'null')",
        'audio_codec' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.audio_codec')), 'null')",
        'stream_format' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.stream_format')), 'null')",
        'audio_language' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.tracks.audio_language')), 'null')",
        'subtitle_enabled' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.tracks.subtitle_enabled')), 'null')",
        'subtitle_language' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.tracks.subtitle_language')), 'null')",
        'playback_speed' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.tracks.playback_speed')), 'null')",
        'network_type' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.context.network_type')), 'null')",
        'build_number' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.context.build_number')), 'null')",
        'os_version' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.context.os_version')), 'null')",
        'device_model' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.context.device_model')), 'null')",
        'device_category' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.context.device_category')), 'null')",
        'locale' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.context.locale')), 'null')",
        'device_timezone' => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.context.timezone')), 'null')",
    ];

    private const INSIGHT_SUMS = [
        'max_position_ms' => 'timing.max_position_ms',
        'watched_ms' => 'timing.watched_ms',
        'unique_watched_ms' => 'timing.unique_watched_ms',
        'replayed_ms' => 'timing.replayed_ms',
        'foreground_watch_ms' => 'timing.foreground_watch_ms',
        'background_play_ms' => 'timing.background_play_ms',
        'play_count' => 'interaction.play_count',
        'pause_count' => 'interaction.pause_count',
        'resume_count' => 'interaction.resume_count',
        'seek_count' => 'interaction.seek_count',
        'seek_forward_ms' => 'interaction.seek_forward_ms',
        'seek_backward_ms' => 'interaction.seek_backward_ms',
        'fullscreen_count' => 'interaction.fullscreen_count',
        'pip_count' => 'interaction.pip_count',
        'cast_count' => 'interaction.cast_count',
        'buffer_count' => 'buffering.count',
        'buffer_ms' => 'buffering.total_ms',
        'quality_changes' => 'quality.change_count',
        'bytes_transferred' => 'quality.bytes_transferred',
        'dropped_frames' => 'quality.dropped_frames',
        'rendered_frames' => 'quality.rendered_frames',
    ];

    private const INSIGHT_AVERAGES = [
        'average_duration_ms' => 'timing.duration_ms',
        'average_watch_position_ms' => 'timing.watch_position_ms',
        'average_startup_ms' => 'timing.startup_ms',
        'average_bitrate_kbps' => 'quality.average_bitrate_kbps',
        'average_playback_speed' => 'tracks.playback_speed',
    ];

    public function presence(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'platform' => ['nullable', Rule::in(['ios', 'tvos', 'android', 'tv', 'tizen', 'webos', 'web'])],
            'app_version' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            return V4Response::success(app(AnalyticsPresenceStore::class)->snapshot($filters));
        } catch (\Throwable $error) {
            report($error);

            return V4Response::success([
                'available' => false,
                'online_users' => 0,
                'online_devices' => 0,
                'heartbeat_interval_seconds' => 60,
                'presence_ttl_seconds' => AnalyticsPresenceStore::TTL_SECONDS,
                'as_of' => now('UTC')->toIso8601String(),
                'platforms' => [],
                'devices' => [],
                'devices_truncated' => false,
            ]);
        }
    }

    /** Aggregate every SDK v1 measurement for the selected sessions and dimension. */
    public function insights(Request $request): JsonResponse
    {
        $dimension = (string) $request->query('dimension', 'platform');
        if (! array_key_exists($dimension, self::INSIGHT_DIMENSIONS)) {
            throw ValidationException::withMessages([
                'dimension' => ['Select a supported analytics dimension.'],
            ]);
        }

        [$query, $filters] = $this->playbackQuery($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $aggregates = $this->insightAggregateSql();
        $summary = (clone $query)->selectRaw($aggregates)->first();
        $expression = self::INSIGHT_DIMENSIONS[$dimension];
        $groups = (clone $query)
            ->selectRaw("{$expression} AS dimension_value, {$aggregates}")
            ->groupByRaw($expression)
            ->orderByDesc('sessions')
            ->orderBy('dimension_value')
            ->paginate($perPage);

        $groups = $groups->through(fn ($row) => [
            'dimension_value' => $row->dimension_value,
        ] + $this->insightNumbers($row));
        $groups->setCollection(collect($this->withInsightLabels(
            $groups->items(),
            $dimension,
            $filters['content_type']
        )));

        return V4Response::success([
            'dimension' => $dimension,
            'summary' => $this->insightNumbers($summary),
            'groups' => $groups,
        ], meta: ['filters' => $this->publicFilters($filters)]);
    }

    private function insightAggregateSql(): string
    {
        $parts = [
            'COUNT(*) AS sessions',
            'COUNT(DISTINCT user_id) AS unique_viewers',
            'COALESCE(SUM(CASE WHEN watched_ms >= 10000 THEN 1 ELSE 0 END), 0) AS valid_views',
            'COALESCE(SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END), 0) AS completed_sessions',
            'COALESCE(SUM(CASE WHEN error_count > 0 THEN 1 ELSE 0 END), 0) AS error_sessions',
            'COALESCE(SUM(error_count), 0) AS playback_error_count',
            'COALESCE(SUM(CASE WHEN state = \'checkpoint\' THEN 1 ELSE 0 END), 0) AS pending_sessions',
            'COALESCE(SUM(CASE WHEN (JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.content.is_downloaded\')) = \'true\' OR JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.content.is_downloaded\')) = 1) THEN 1 ELSE 0 END), 0) AS downloaded_sessions',
            'COALESCE(SUM(CASE WHEN (JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.content.autoplay\')) = \'true\' OR JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.content.autoplay\')) = 1) THEN 1 ELSE 0 END), 0) AS autoplay_sessions',
            'COALESCE(SUM(CASE WHEN (JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.tracks.subtitle_enabled\')) = \'true\' OR JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.tracks.subtitle_enabled\')) = 1) THEN 1 ELSE 0 END), 0) AS subtitle_sessions',
            'COALESCE(MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.buffering.longest_ms\')) AS UNSIGNED)), 0) AS longest_buffer_ms',
            'AVG(completion_percent) AS average_completion_percent',
        ];

        foreach (self::INSIGHT_SUMS as $alias => $path) {
            $parts[] = "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.{$path}')) AS UNSIGNED)), 0) AS {$alias}";
        }
        foreach (self::INSIGHT_AVERAGES as $alias => $path) {
            $parts[] = "AVG(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.{$path}')), 'null') AS DECIMAL(16, 3))) AS {$alias}";
        }
        foreach ([25, 50, 75, 90] as $milestone) {
            $parts[] = "COALESCE(SUM(CASE WHEN JSON_CONTAINS(JSON_EXTRACT(metrics_json, '$.result.milestones'), '{$milestone}') THEN 1 ELSE 0 END), 0) AS milestone_{$milestone}";
        }

        return implode(', ', $parts);
    }

    private function insightNumbers(object $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if ($key === 'dimension_value') {
                continue;
            }
            $result[$key] = $value === null ? null : (str_starts_with($key, 'average_')
                ? round((float) $value, 2)
                : (int) $value);
        }

        return $result;
    }

    public function overview(Request $request): JsonResponse
    {
        [$query, $filters] = $this->playbackQuery($request);
        $stats = (clone $query)->selectRaw(
            'COUNT(*) AS playback_starts,
             SUM(CASE WHEN watched_ms >= 10000 THEN 1 ELSE 0 END) AS valid_views,
             COUNT(DISTINCT user_id) AS unique_viewers,
             COALESCE(SUM(watched_ms), 0) AS watch_ms,
             COALESCE(AVG(watched_ms), 0) AS average_watch_ms,
             SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_views,
             SUM(CASE WHEN error_count > 0 THEN 1 ELSE 0 END) AS failed_sessions,
             AVG(startup_ms) AS average_startup_ms,
             COALESCE(SUM(buffer_ms), 0) AS buffer_ms'
        )->first();

        $starts = (int) ($stats->playback_starts ?? 0);
        $watchMs = (int) ($stats->watch_ms ?? 0);
        $bufferMs = (int) ($stats->buffer_ms ?? 0);
        $overview = [
            'playback_starts' => $starts,
            'valid_views' => (int) ($stats->valid_views ?? 0),
            'unique_viewers' => (int) ($stats->unique_viewers ?? 0),
            'watch_hours' => round($watchMs / 3_600_000, 2),
            'average_watch_minutes' => round(((float) ($stats->average_watch_ms ?? 0)) / 60_000, 2),
            'completion_rate' => $this->percentage((int) ($stats->completed_views ?? 0), $starts),
            'playback_error_rate' => $this->percentage((int) ($stats->failed_sessions ?? 0), $starts),
            'average_startup_ms' => round((float) ($stats->average_startup_ms ?? 0), 1),
            'rebuffer_ratio' => ($watchMs + $bufferMs) > 0
                ? round($bufferMs / ($watchMs + $bufferMs), 4)
                : 0.0,
            'app_sessions' => $this->appSessionCount($filters),
        ];

        $detailTotals = (clone $query)->selectRaw(
            "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.timing.unique_watched_ms')) AS UNSIGNED)), 0) AS unique_watch_ms,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.timing.replayed_ms')) AS UNSIGNED)), 0) AS replayed_ms,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.timing.foreground_watch_ms')) AS UNSIGNED)), 0) AS foreground_watch_ms,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.timing.background_play_ms')) AS UNSIGNED)), 0) AS background_play_ms,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.play_count')) AS UNSIGNED)), 0) AS play_count,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.pause_count')) AS UNSIGNED)), 0) AS pause_count,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.resume_count')) AS UNSIGNED)), 0) AS resume_count,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.seek_count')) AS UNSIGNED)), 0) AS seek_count,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.seek_forward_ms')) AS UNSIGNED)), 0) AS seek_forward_ms,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.seek_backward_ms')) AS UNSIGNED)), 0) AS seek_backward_ms,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.fullscreen_count')) AS UNSIGNED)), 0) AS fullscreen_count,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.pip_count')) AS UNSIGNED)), 0) AS pip_count,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.interaction.cast_count')) AS UNSIGNED)), 0) AS cast_count,
             COALESCE(MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.buffering.longest_ms')) AS UNSIGNED)), 0) AS longest_buffer_ms,
             AVG(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.average_bitrate_kbps')), 'null') AS DECIMAL(12,2))) AS average_bitrate_kbps,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.bytes_transferred')) AS UNSIGNED)), 0) AS bytes_transferred,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.dropped_frames')) AS UNSIGNED)), 0) AS dropped_frames,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.rendered_frames')) AS UNSIGNED)), 0) AS rendered_frames,
             AVG(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.tracks.playback_speed')), 'null') AS DECIMAL(5,2))) AS average_playback_speed,
             SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.tracks.subtitle_enabled')) = 'true' THEN 1 ELSE 0 END) AS subtitle_sessions"
        )->first();
        $engagement = [
            'unique_watch_hours' => round((int) $detailTotals->unique_watch_ms / 3_600_000, 2),
            'replayed_hours' => round((int) $detailTotals->replayed_ms / 3_600_000, 2),
            'foreground_watch_hours' => round((int) $detailTotals->foreground_watch_ms / 3_600_000, 2),
            'background_play_hours' => round((int) $detailTotals->background_play_ms / 3_600_000, 2),
            'play_count' => (int) $detailTotals->play_count,
            'pause_count' => (int) $detailTotals->pause_count,
            'resume_count' => (int) $detailTotals->resume_count,
            'seek_count' => (int) $detailTotals->seek_count,
            'seek_forward_hours' => round((int) $detailTotals->seek_forward_ms / 3_600_000, 2),
            'seek_backward_hours' => round((int) $detailTotals->seek_backward_ms / 3_600_000, 2),
            'fullscreen_count' => (int) $detailTotals->fullscreen_count,
            'pip_count' => (int) $detailTotals->pip_count,
            'cast_count' => (int) $detailTotals->cast_count,
            'longest_buffer_seconds' => round((int) $detailTotals->longest_buffer_ms / 1000, 1),
            'average_bitrate_kbps' => round((float) ($detailTotals->average_bitrate_kbps ?? 0), 1),
            'data_transferred_bytes' => (int) $detailTotals->bytes_transferred,
            'dropped_frames' => (int) $detailTotals->dropped_frames,
            'rendered_frames' => (int) $detailTotals->rendered_frames,
            'average_playback_speed' => round((float) ($detailTotals->average_playback_speed ?? 0), 2),
            'subtitle_sessions' => (int) $detailTotals->subtitle_sessions,
        ];

        $watchTrend = (clone $query)
            ->selectRaw(
                "DATE(CONVERT_TZ(started_at, '+00:00', ?)) AS date, COUNT(*) AS playback_starts,
                 COUNT(DISTINCT user_id) AS unique_viewers,
                 COALESCE(SUM(watched_ms), 0) AS watch_ms",
                [$filters['timezone']]
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'playback_starts' => (int) $row->playback_starts,
                'unique_viewers' => (int) $row->unique_viewers,
                'watch_hours' => round(((int) $row->watch_ms) / 3_600_000, 2),
            ])->all();

        $streamingHealth = (clone $query)
            ->selectRaw(
                "DATE(CONVERT_TZ(started_at, '+00:00', ?)) AS date, AVG(startup_ms) AS average_startup_ms,
                 COALESCE(SUM(buffer_ms), 0) AS buffer_ms,
                 COALESCE(SUM(watched_ms), 0) AS watch_ms,
                 SUM(CASE WHEN error_count = 0 THEN 1 ELSE 0 END) AS successful,
                 COUNT(*) AS sessions",
                [$filters['timezone']]
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                $watch = (int) $row->watch_ms;
                $buffer = (int) $row->buffer_ms;

                return [
                    'date' => $row->date,
                    'average_startup_ms' => round((float) ($row->average_startup_ms ?? 0), 1),
                    'rebuffer_rate' => ($watch + $buffer) > 0
                        ? round(($buffer / ($watch + $buffer)) * 100, 3)
                        : 0.0,
                    'playback_success_rate' => $this->percentage(
                        (int) $row->successful,
                        (int) $row->sessions
                    ),
                ];
            })->all();

        $platformRows = (clone $query)
            ->selectRaw('platform, COUNT(*) AS sessions, COUNT(DISTINCT user_id) AS viewers')
            ->groupBy('platform')
            ->orderByDesc('sessions')
            ->get();
        $platforms = $platformRows->map(fn ($row) => [
            'platform' => $row->platform,
            'sessions' => (int) $row->sessions,
            'viewers' => (int) $row->viewers,
            'percentage' => $this->percentage((int) $row->sessions, $starts),
        ])->all();

        $topContent = (clone $query)
            ->selectRaw(
                'content_id, content_type, COUNT(*) AS playback_starts,
                 SUM(CASE WHEN watched_ms >= 10000 THEN 1 ELSE 0 END) AS views,
                 COALESCE(SUM(watched_ms), 0) AS watch_ms,
                 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.quality.bytes_transferred\')) AS UNSIGNED)), 0) AS bytes_transferred,
                 SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_views'
            )
            ->groupBy('content_id', 'content_type')
            ->orderByDesc('views')
            ->orderByDesc('watch_ms')
            ->orderBy('content_type')
            ->orderBy('content_id')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'content_id' => $row->content_id,
                'title' => $row->content_id,
                'content_type' => $row->content_type,
                'views' => (int) $row->views,
                'watch_hours' => round(((int) $row->watch_ms) / 3_600_000, 2),
                'data_transferred_bytes' => (int) $row->bytes_transferred,
                'completion_rate' => $this->percentage(
                    (int) $row->completed_views,
                    (int) $row->playback_starts
                ),
            ])->all();

        return V4Response::success($overview + [
            'overview' => $overview,
            'engagement' => $engagement,
            'watch_trend' => $watchTrend,
            'streaming_health' => $streamingHealth,
            'platforms' => $platforms,
            'top_content' => $this->withContentTitles($topContent),
            'product_events' => $this->eventStats($filters),
        ], meta: ['filters' => $this->publicFilters($filters)]);
    }

    public function content(Request $request): JsonResponse
    {
        [$query, $filters] = $this->playbackQuery($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $rows = $query->selectRaw(
            'content_id, content_type, COUNT(*) AS playback_starts,
             SUM(CASE WHEN watched_ms >= 10000 THEN 1 ELSE 0 END) AS valid_views,
             COUNT(DISTINCT user_id) AS unique_viewers,
             COALESCE(SUM(watched_ms), 0) AS watch_ms,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.quality.bytes_transferred\')) AS UNSIGNED)), 0) AS bytes_transferred,
             SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_views'
        )
            ->groupBy('content_id', 'content_type')
            ->orderByDesc('valid_views')
            ->orderByDesc('watch_ms')
            ->orderBy('content_type')
            ->orderBy('content_id')
            ->paginate($perPage)
            ->through(fn ($row) => $this->contentRow($row));
        $rows->setCollection(collect($this->withContentTitles($rows->items())));

        return V4Response::success($rows, meta: ['filters' => $this->publicFilters($filters)]);
    }

    public function contentShow(Request $request, string $type, string $id): JsonResponse
    {
        $request->query->set('content_type', $type);
        [$query, $filters] = $this->playbackQuery($request);
        $row = $query->where('content_id', $id)
            ->selectRaw(
                'content_id, content_type, COUNT(*) AS playback_starts,
                 SUM(CASE WHEN watched_ms >= 10000 THEN 1 ELSE 0 END) AS valid_views,
                 COUNT(DISTINCT user_id) AS unique_viewers,
                 COALESCE(SUM(watched_ms), 0) AS watch_ms,
                 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.quality.bytes_transferred\')) AS UNSIGNED)), 0) AS bytes_transferred,
                 SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_views'
            )
            ->groupBy('content_id', 'content_type')
            ->first();

        if (! $row) {
            return V4Response::error('ANALYTICS_CONTENT_NOT_FOUND', 'No analytics data was found.', 404);
        }

        return V4Response::success(
            $this->withContentTitles([$this->contentRow($row)])[0],
            meta: ['filters' => $this->publicFilters($filters)]
        );
    }

    public function quality(Request $request): JsonResponse
    {
        [$query, $filters] = $this->playbackQuery($request);
        $rows = $query->selectRaw(
            'platform, app_version, COUNT(*) AS sessions,
             AVG(startup_ms) AS average_startup_ms,
             COALESCE(SUM(buffer_ms), 0) AS buffer_ms,
             COALESCE(SUM(watched_ms), 0) AS watch_ms,
             SUM(CASE WHEN error_count > 0 THEN 1 ELSE 0 END) AS failed_sessions,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, \'$.quality.bytes_transferred\')) AS UNSIGNED)), 0) AS bytes_transferred'
        )
            ->groupBy('platform', 'app_version')
            ->orderByDesc('sessions')
            ->get()
            ->map(function ($row) {
                $watch = (int) $row->watch_ms;
                $buffer = (int) $row->buffer_ms;

                return [
                    'platform' => $row->platform,
                    'app_version' => $row->app_version,
                    'sessions' => (int) $row->sessions,
                    'average_startup_ms' => round((float) ($row->average_startup_ms ?? 0), 1),
                    'rebuffer_ratio' => ($watch + $buffer) > 0
                        ? round($buffer / ($watch + $buffer), 4)
                        : 0.0,
                    'failure_rate' => $this->percentage(
                        (int) $row->failed_sessions,
                        (int) $row->sessions
                    ),
                    'data_transferred_bytes' => (int) $row->bytes_transferred,
                ];
            });

        return V4Response::success($rows, meta: ['filters' => $this->publicFilters($filters)]);
    }

    public function errors(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $query = DB::connection('analytics')->table('playback_errors')
            ->whereBetween('occurred_at', [$filters['from_utc'], $filters['to_utc']]);
        $this->applyErrorDimensions($query, $filters);
        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }
        $rows = $query->selectRaw(
            'category, stage, code, COUNT(*) AS occurrences,
             COUNT(DISTINCT session_id) AS affected_sessions,
             SUM(CASE WHEN is_fatal = 1 THEN 1 ELSE 0 END) AS fatal_count,
             MAX(occurred_at) AS last_seen_at'
        )
            ->groupBy('category', 'stage', 'code')
            ->orderByDesc('occurrences')
            ->limit(200)
            ->get();

        return V4Response::success($rows, meta: ['filters' => $this->publicFilters($filters)]);
    }

    public function errorEvents(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $query = DB::connection('analytics')->table('playback_errors')
            ->whereBetween('occurred_at', [$filters['from_utc'], $filters['to_utc']]);
        $this->applyErrorDimensions($query, $filters);
        if ($request->filled('category')) {
            $query->where('category', (string) $request->query('category'));
        }

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage, [
            'event_id', 'session_id', 'user_id', 'device_id', 'category', 'stage',
            'code', 'http_status', 'position_ms', 'is_fatal', 'is_retryable',
            'retry_count', 'network_type', 'sanitized_message', 'occurred_at',
        ]);

        return V4Response::success($rows, meta: ['filters' => $this->publicFilters($filters)]);
    }

    public function events(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $query = DB::connection('analytics')->table('analytics_events')
            ->whereBetween('occurred_at', [$filters['from_utc'], $filters['to_utc']]);
        if ($filters['platform']) {
            $query->where('platform', $filters['platform']);
        }
        if ($filters['app_version']) {
            $query->where('app_version', $filters['app_version']);
        }
        if ($request->filled('name')) {
            $query->where('name', (string) $request->query('name'));
        }
        if ($filters['user_id']) {
            $query->where('user_id', $filters['user_id']);
        }

        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage, [
            'event_id', 'user_id', 'device_id', 'app_session_id', 'name',
            'occurred_at', 'platform', 'app_version', 'properties_json',
        ])->through(fn ($row) => [
            'event_id' => $row->event_id,
            'user_id' => $row->user_id,
            'device_id' => $row->device_id,
            'app_session_id' => $row->app_session_id,
            'name' => $row->name,
            'occurred_at' => $row->occurred_at,
            'platform' => $row->platform,
            'app_version' => $row->app_version,
            'properties' => json_decode((string) $row->properties_json, true) ?: [],
        ]);

        return V4Response::success($rows, meta: ['filters' => $this->publicFilters($filters)]);
    }

    public function sessions(Request $request): JsonResponse
    {
        [$query, $filters] = $this->playbackQuery($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        if ($request->filled('content_id')) {
            $query->where('content_id', (string) $request->query('content_id'));
        }
        if ($request->filled('end_reason')) {
            $query->where('end_reason', (string) $request->query('end_reason'));
        }
        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('q')).'%';
            $query->where(function ($search) use ($term) {
                $search->where('session_id', 'like', $term)
                    ->orWhere('content_id', 'like', $term)
                    ->orWhere('user_id', 'like', $term);
            });
        }

        $rows = $query->orderByDesc('started_at')->orderByDesc('id')->paginate($perPage, [
            'session_id', 'user_id', 'device_id', 'content_id', 'content_type',
            'revision', 'state', 'started_at', 'ended_at', 'watch_position_ms',
            'duration_ms', 'watched_ms', 'unique_watched_ms', 'startup_ms',
            'buffer_count', 'buffer_ms', 'error_count', 'completion_percent',
            'completed', 'end_reason', 'platform', 'app_version', 'sdk_version',
            'metrics_json',
        ])->through(function ($row) {
            $metrics = json_decode((string) $row->metrics_json, true) ?: [];

            return [
                'session_id' => $row->session_id,
                'user_id' => $row->user_id,
                'device_id' => $row->device_id,
                'content_id' => $row->content_id,
                'content_type' => $row->content_type,
                'revision' => (int) $row->revision,
                'state' => $row->state,
                'started_at' => $row->started_at,
                'ended_at' => $row->ended_at,
                'watch_position_ms' => (int) $row->watch_position_ms,
                'duration_ms' => (int) $row->duration_ms,
                'watched_ms' => (int) $row->watched_ms,
                'unique_watched_ms' => (int) $row->unique_watched_ms,
                'startup_ms' => $row->startup_ms === null ? null : (int) $row->startup_ms,
                'buffer_count' => (int) $row->buffer_count,
                'buffer_ms' => (int) $row->buffer_ms,
                'error_count' => (int) $row->error_count,
                'completion_percent' => (float) $row->completion_percent,
                'completed' => (bool) $row->completed,
                'end_reason' => $row->end_reason,
                'platform' => $row->platform,
                'app_version' => $row->app_version,
                'sdk_version' => $row->sdk_version,
                'data_transferred_bytes' => (int) data_get($metrics, 'quality.bytes_transferred', 0),
                'metrics' => $metrics,
            ];
        });
        $rows->setCollection(collect($this->withContentTitles($rows->items())));

        return V4Response::success($rows, meta: ['filters' => $this->publicFilters($filters)]);
    }

    public function userSessions(Request $request, string $userId): JsonResponse
    {
        [$query, $filters] = $this->playbackQuery($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $rows = $query->where('user_id', $userId)
            ->orderByDesc('started_at')
            ->paginate($perPage, [
                'session_id', 'device_id', 'content_id', 'content_type', 'state',
                'started_at', 'ended_at', 'watched_ms', 'duration_ms',
                'completion_percent', 'completed', 'end_reason', 'platform',
                'app_version', 'error_count',
            ]);

        return V4Response::success($rows, meta: ['filters' => $this->publicFilters($filters)]);
    }

    private function playbackQuery(Request $request): array
    {
        $filters = $this->filters($request);
        $query = DB::connection('analytics')->table('playback_sessions')
            ->whereBetween('started_at', [$filters['from_utc'], $filters['to_utc']]);
        if ($filters['platform']) {
            $query->where('platform', $filters['platform']);
        }
        if ($filters['app_version']) {
            $query->where('app_version', $filters['app_version']);
        }
        if ($filters['content_type']) {
            $query->where('content_type', $filters['content_type']);
        }
        if ($filters['user_id']) {
            $query->where('user_id', $filters['user_id']);
        }

        return [$query, $filters];
    }

    private function applyErrorDimensions($query, array $filters): void
    {
        if ($filters['platform']) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('playback_sessions')
                    ->whereColumn('playback_sessions.session_id', 'playback_errors.session_id')
                    ->whereColumn('playback_sessions.user_id', 'playback_errors.user_id')
                    ->whereColumn('playback_sessions.device_id', 'playback_errors.device_id')
                    ->where('playback_sessions.platform', $filters['platform']);
            });
        }
        if ($filters['app_version']) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('playback_sessions')
                    ->whereColumn('playback_sessions.session_id', 'playback_errors.session_id')
                    ->whereColumn('playback_sessions.user_id', 'playback_errors.user_id')
                    ->whereColumn('playback_sessions.device_id', 'playback_errors.device_id')
                    ->where('playback_sessions.app_version', $filters['app_version']);
            });
        }
        if ($filters['content_type']) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('playback_sessions')
                    ->whereColumn('playback_sessions.session_id', 'playback_errors.session_id')
                    ->whereColumn('playback_sessions.user_id', 'playback_errors.user_id')
                    ->whereColumn('playback_sessions.device_id', 'playback_errors.device_id')
                    ->where('playback_sessions.content_type', $filters['content_type']);
            });
        }
        if ($filters['user_id']) {
            $query->where('user_id', $filters['user_id']);
        }
    }

    private function filters(Request $request): array
    {
        $data = validator($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone'],
            'platform' => ['nullable', Rule::in(['ios', 'tvos', 'android', 'tv', 'tizen', 'webos', 'web'])],
            'app_version' => ['nullable', 'string', 'max:64'],
            'content_type' => ['nullable', Rule::in(['movie', 'episode', 'live'])],
            'user_id' => ['nullable', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ])->validate();
        $timezone = $data['timezone'] ?? 'Asia/Kolkata';
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to'] ?? now($timezone)->toDateString(), $timezone)
            ->endOfDay();
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from'] ?? $to->subDays(6)->toDateString(), $timezone)
            ->startOfDay();
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'from' => ['The from date must be before or equal to the to date.'],
            ]);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => $timezone,
            'from_utc' => $from->utc(),
            'to_utc' => $to->utc(),
            'platform' => $data['platform'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'content_type' => $data['content_type'] ?? null,
            'user_id' => $data['user_id'] ?? null,
        ];
    }

    private function eventStats(array $filters): array
    {
        $query = DB::connection('analytics')->table('analytics_events')
            ->whereBetween('occurred_at', [$filters['from_utc'], $filters['to_utc']]);
        if ($filters['platform']) {
            $query->where('platform', $filters['platform']);
        }
        if ($filters['app_version']) {
            $query->where('app_version', $filters['app_version']);
        }
        if ($filters['user_id']) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->selectRaw('name, COUNT(*) AS count, COUNT(DISTINCT user_id) AS unique_users')
            ->groupBy('name')
            ->orderByDesc('count')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->count,
                'unique_users' => (int) $row->unique_users,
            ])->all();
    }

    private function appSessionCount(array $filters): int
    {
        $query = DB::connection('analytics')->table('analytics_events')
            ->where('name', 'app_opened')
            ->whereBetween('occurred_at', [$filters['from_utc'], $filters['to_utc']]);
        if ($filters['platform']) {
            $query->where('platform', $filters['platform']);
        }
        if ($filters['app_version']) {
            $query->where('app_version', $filters['app_version']);
        }
        if ($filters['user_id']) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->distinct()->count('app_session_id');
    }

    /** Resolve catalog names in batches across the separate catalog connection. */
    private function withContentTitles(array $rows): array
    {
        $labels = [];
        foreach (['movie', 'episode', 'live'] as $type) {
            $ids = array_values(array_unique(array_map(
                'strval',
                array_column(array_filter(
                    $rows,
                    fn ($row) => ($row['content_type'] ?? null) === $type
                ), 'content_id')
            )));
            $labels[$type] = $this->contentLabels($ids, $type);
        }

        return array_map(function ($row) use ($labels) {
            $type = (string) ($row['content_type'] ?? '');
            $id = (string) ($row['content_id'] ?? '');
            $label = $labels[$type][$id] ?? null;
            $row['title'] = $label['title'] ?? $id;
            $row['parent_title'] = $label['parent_title'] ?? null;
            $row['display_title'] = $label['display_title'] ?? $row['title'];

            return $row;
        }, $rows);
    }

    /** Add human-readable names to catalog-backed SDK insight dimensions. */
    private function withInsightLabels(array $rows, string $dimension, ?string $contentType): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($row) => isset($row['dimension_value']) ? (string) $row['dimension_value'] : null,
            $rows
        ))));
        if ($ids === []) {
            return $rows;
        }

        $labels = match ($dimension) {
            'content_id' => $this->contentLabels($ids, $contentType),
            'episode_id' => $this->contentLabels($ids, 'episode'),
            'series_id' => $this->contentLabels($ids, 'movie'),
            'season_id' => $this->seasonLabels($ids),
            default => [],
        };

        return array_map(function ($row) use ($labels) {
            $id = isset($row['dimension_value']) ? (string) $row['dimension_value'] : '';
            if (isset($labels[$id])) {
                $row['dimension_label'] = $labels[$id]['display_title'];
            }

            return $row;
        }, $rows);
    }

    /** @return array<string, array{title: string, parent_title: ?string, display_title: string}> */
    private function contentLabels(array $ids, ?string $type = null): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === []) {
            return [];
        }

        $labels = [];
        $types = $type ? [$type] : ['movie', 'episode', 'live'];
        foreach ($types as $candidate) {
            $resolved = match ($candidate) {
                'movie' => $this->movieLabels($ids),
                'episode' => $this->episodeLabels($ids),
                'live' => $this->simpleTableLabels('channel_contents', $ids),
                default => [],
            };
            foreach ($resolved as $id => $label) {
                $labels[$id] ??= $label;
            }
        }

        return $labels;
    }

    private function movieLabels(array $ids): array
    {
        return $this->simpleTableLabels('movie', $ids);
    }

    private function episodeLabels(array $ids): array
    {
        $records = $this->catalogRecords('episodes', $ids);
        $sources = array_fill_keys(array_keys($records), 'episodes');
        $unresolved = array_values(array_diff($ids, array_keys($records)));
        if ($unresolved !== []) {
            $legacy = $this->catalogRecords('episode', $unresolved);
            $records += $legacy;
            $sources += array_fill_keys(array_keys($legacy), 'episode');
        }

        $seasonIds = [];
        $movieIds = [];
        foreach ($records as $id => $record) {
            if ($sources[$id] === 'episodes' && isset($record->season_id)) {
                $seasonIds[] = (string) $record->season_id;
            } elseif (isset($record->movie_id)) {
                $movieIds[] = (string) $record->movie_id;
            }
        }
        $seasons = $this->catalogRecords('seasons', $seasonIds);
        foreach ($seasons as $season) {
            if (isset($season->movie_id)) {
                $movieIds[] = (string) $season->movie_id;
            }
        }
        $movies = $this->movieLabels($movieIds);

        $labels = [];
        foreach ($records as $id => $record) {
            $title = trim((string) ($record->title ?? '')) ?: $id;
            $movieId = null;
            if ($sources[$id] === 'episodes' && isset($record->season_id)) {
                $season = $seasons[(string) $record->season_id] ?? null;
                $movieId = isset($season?->movie_id) ? (string) $season->movie_id : null;
            } elseif (isset($record->movie_id)) {
                $movieId = (string) $record->movie_id;
            }
            $parent = $movieId ? ($movies[$movieId]['title'] ?? null) : null;
            $labels[$id] = [
                'title' => $title,
                'parent_title' => $parent,
                'display_title' => $parent ? "{$parent} — {$title}" : $title,
            ];
        }

        return $labels;
    }

    private function seasonLabels(array $ids): array
    {
        $seasons = $this->catalogRecords('seasons', $ids);
        $movieIds = array_values(array_unique(array_filter(array_map(
            fn ($season) => isset($season->movie_id) ? (string) $season->movie_id : null,
            $seasons
        ))));
        $movies = $this->movieLabels($movieIds);
        $labels = [];
        foreach ($seasons as $id => $season) {
            $title = trim((string) ($season->title ?? '')) ?: $id;
            $movieId = isset($season->movie_id) ? (string) $season->movie_id : null;
            $parent = $movieId ? ($movies[$movieId]['title'] ?? null) : null;
            $labels[$id] = [
                'title' => $title,
                'parent_title' => $parent,
                'display_title' => $parent ? "{$parent} — {$title}" : $title,
            ];
        }

        return $labels;
    }

    private function simpleTableLabels(string $table, array $ids): array
    {
        $labels = [];
        foreach ($this->catalogRecords($table, $ids) as $id => $record) {
            $title = trim((string) ($record->title ?? '')) ?: (string) $id;
            $labels[$id] = [
                'title' => $title,
                'parent_title' => null,
                'display_title' => $title,
            ];
        }

        return $labels;
    }

    /** Find catalog records by public id and, for older clients, numeric num/id. */
    private function catalogRecords(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === []) {
            return [];
        }

        try {
            $schema = DB::connection()->getSchemaBuilder();
            if (! $schema->hasTable($table)) {
                return [];
            }
            $columns = array_values(array_filter(
                ['id', 'num'],
                fn ($column) => $schema->hasColumn($table, $column)
            ));
            if ($columns === []) {
                return [];
            }
            $records = collect();
            foreach ($columns as $column) {
                $records = $records->merge(DB::table($table)->whereIn($column, $ids)->get());
            }

            return $this->indexCatalogRecords($records, $ids);
        } catch (\Throwable $error) {
            // Historical analytics must remain readable when catalog lookup is unavailable.
            report($error);

            return [];
        }
    }

    private function indexCatalogRecords(Collection $records, array $requestedIds): array
    {
        $requested = array_fill_keys($requestedIds, true);
        $indexed = [];
        foreach ($records as $record) {
            foreach (['id', 'num'] as $column) {
                if (isset($record->{$column})) {
                    $key = (string) $record->{$column};
                    if (isset($requested[$key])) {
                        $indexed[$key] ??= $record;
                    }
                }
            }
        }

        return $indexed;
    }

    private function contentRow(object $row): array
    {
        return [
            'content_id' => $row->content_id,
            'content_type' => $row->content_type,
            'playback_starts' => (int) $row->playback_starts,
            'valid_views' => (int) $row->valid_views,
            'unique_viewers' => (int) $row->unique_viewers,
            'watch_hours' => round(((int) $row->watch_ms) / 3_600_000, 2),
            'data_transferred_bytes' => (int) ($row->bytes_transferred ?? 0),
            'completion_rate' => $this->percentage(
                (int) $row->completed_views,
                (int) $row->playback_starts
            ),
        ];
    }

    private function percentage(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 2) : 0.0;
    }

    private function publicFilters(array $filters): array
    {
        return collect($filters)->only([
            'from', 'to', 'timezone', 'platform', 'app_version', 'content_type', 'user_id',
        ])->all();
    }
}
