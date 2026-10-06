<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnalyticsReportController extends Controller
{
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
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.buffering.longest_ms')) AS UNSIGNED)), 0) AS longest_buffer_ms,
             AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.average_bitrate_kbps')) AS DECIMAL(12,2))) AS average_bitrate_kbps,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.dropped_frames')) AS UNSIGNED)), 0) AS dropped_frames,
             COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.rendered_frames')) AS UNSIGNED)), 0) AS rendered_frames,
             AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.tracks.playback_speed')) AS DECIMAL(5,2))) AS average_playback_speed,
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
                'content_id, content_type, COUNT(*) AS views,
                 COALESCE(SUM(watched_ms), 0) AS watch_ms,
                 SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_views'
            )
            ->groupBy('content_id', 'content_type')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'content_id' => $row->content_id,
                'title' => $row->content_id,
                'content_type' => $row->content_type,
                'views' => (int) $row->views,
                'watch_hours' => round(((int) $row->watch_ms) / 3_600_000, 2),
                'completion_rate' => $this->percentage(
                    (int) $row->completed_views,
                    (int) $row->views
                ),
            ])->all();

        return V4Response::success($overview + [
            'overview' => $overview,
            'engagement' => $engagement,
            'watch_trend' => $watchTrend,
            'streaming_health' => $streamingHealth,
            'platforms' => $platforms,
            'top_content' => $topContent,
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
             SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_views'
        )
            ->groupBy('content_id', 'content_type')
            ->orderByDesc('valid_views')
            ->paginate($perPage)
            ->through(fn ($row) => $this->contentRow($row));

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
                 SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_views'
            )
            ->groupBy('content_id', 'content_type')
            ->first();

        if (! $row) {
            return V4Response::error('ANALYTICS_CONTENT_NOT_FOUND', 'No analytics data was found.', 404);
        }

        return V4Response::success(
            $this->contentRow($row),
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
             SUM(CASE WHEN error_count > 0 THEN 1 ELSE 0 END) AS failed_sessions'
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

        $rows = $query->orderByDesc('occurred_at')->paginate($perPage, [
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

        $rows = $query->orderByDesc('occurred_at')->paginate($perPage, [
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

        $rows = $query->orderByDesc('started_at')->paginate($perPage, [
            'session_id', 'user_id', 'device_id', 'content_id', 'content_type',
            'revision', 'state', 'started_at', 'ended_at', 'watch_position_ms',
            'duration_ms', 'watched_ms', 'unique_watched_ms', 'startup_ms',
            'buffer_count', 'buffer_ms', 'error_count', 'completion_percent',
            'completed', 'end_reason', 'platform', 'app_version', 'sdk_version',
            'metrics_json',
        ])->through(fn ($row) => [
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
            'metrics' => json_decode((string) $row->metrics_json, true) ?: [],
        ]);

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
                    ->where('playback_sessions.platform', $filters['platform']);
            });
        }
        if ($filters['app_version']) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('playback_sessions')
                    ->whereColumn('playback_sessions.session_id', 'playback_errors.session_id')
                    ->where('playback_sessions.app_version', $filters['app_version']);
            });
        }
        if ($filters['content_type']) {
            $query->whereExists(function ($subquery) use ($filters) {
                $subquery->selectRaw('1')
                    ->from('playback_sessions')
                    ->whereColumn('playback_sessions.session_id', 'playback_errors.session_id')
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
            'platform' => ['nullable', Rule::in(['ios', 'tvos', 'android', 'tv'])],
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

    private function contentRow(object $row): array
    {
        return [
            'content_id' => $row->content_id,
            'content_type' => $row->content_type,
            'playback_starts' => (int) $row->playback_starts,
            'valid_views' => (int) $row->valid_views,
            'unique_viewers' => (int) $row->unique_viewers,
            'watch_hours' => round(((int) $row->watch_ms) / 3_600_000, 2),
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
