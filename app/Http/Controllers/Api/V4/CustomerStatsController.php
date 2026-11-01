<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\BandwidthLog;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\WatchHistoryModel;
use App\Models\WatchSession;
use App\Support\Api\V4Response;
use App\Support\CustomerStatsPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerStatsController extends Controller
{
    public function show(Request $request)
    {
        $filters = CustomerStatsPeriod::filters($request);
        $userId = (string) $request->input('auth_user_id');

        return V4Response::success($this->dataForUser(
            $userId,
            max(1, (int) $request->query('page', 1)),
            min(20, max(1, (int) $request->query('per_page', 10))),
            $filters
        ))
            ->header('Cache-Control', 'private, no-store');
    }

    public function dataForUser(string $userId, int $page = 1, int $perPage = 10, array $filters = []): array
    {
        [$from, $to] = CustomerStatsPeriod::range($filters);
        $daily = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $daily[$day->toDateString()] = 0;
        }

        $sessions = Schema::hasTable('watch_sessions')
            ? WatchSession::where('user_id', $userId)->whereBetween('created_at', [$from, $to])
            : null;
        $bandwidth = Schema::hasTable('bandwidth_logs')
            ? BandwidthLog::where('user_id', $userId)->whereBetween('created_at', [$from, $to])
            : null;

        $contentUsage = collect();
        $viewedTitles = null;
        if ($sessions) {
            $groups = (clone $sessions)->selectRaw('movie_id, episode_id, SUM(seconds_watched) AS seconds_watched, MAX(created_at) AS last_watched_at')
                ->groupBy('movie_id', 'episode_id')
                ->get();
            foreach ($groups as $item) {
                $type = $item->episode_id ? 'episode' : 'movie';
                $id = (string) ($item->episode_id ?: $item->movie_id);
                $key = $type.':'.$id;
                $contentUsage->put($key, [
                    'type' => $type,
                    'content_id' => $id,
                    'seconds_watched' => (int) $item->seconds_watched,
                    'last_watched_at' => Carbon::parse($item->last_watched_at)->toIso8601String(),
                    'bandwidth_mb' => null,
                    'analytics_bandwidth_mb' => null,
                ]);
            }
        }

        if ($sessions) {
            foreach ((clone $sessions)->selectRaw('DATE(created_at) AS day, SUM(seconds_watched) AS seconds')->groupByRaw('DATE(created_at)')->get() as $day) {
                $daily[$day->day] = (int) $day->seconds;
            }
        }

        if ($bandwidth) {
            $bandGroups = (clone $bandwidth)->selectRaw('movie_id, episode_id, SUM(mb_used) AS bandwidth_mb')
                ->groupBy('movie_id', 'episode_id')->get();
            foreach ($bandGroups as $item) {
                $type = $item->episode_id ? 'episode' : 'movie';
                $id = (string) ($item->episode_id ?: $item->movie_id);
                $key = $type.':'.$id;
                $row = $contentUsage->get($key, [
                    'type' => $type,
                    'content_id' => $id,
                    'seconds_watched' => 0,
                    'bandwidth_mb' => null,
                    'analytics_bandwidth_mb' => null,
                ]);
                $row['bandwidth_mb'] = (float) $item->bandwidth_mb;
                $contentUsage->put($key, $row);
            }
        }

        $legacyDaily = $daily;
        $completed = null;
        $analyticsBandwidthMb = null;
        $analyticsAvailable = false;
        try {
            if (Schema::connection('analytics')->hasTable('playback_sessions')) {
                $analyticsAvailable = true;
                $completed = 0;
                $analyticContent = [];
                $analyticsRows = DB::connection('analytics')->table('playback_sessions')
                    ->where('user_id', $userId)
                    ->whereBetween('ended_at', [$from->copy()->utc(), $to->copy()->utc()])
                    ->select(['content_id', 'content_type', 'watched_ms', 'metrics_json', 'completed', 'ended_at'])->cursor();
                foreach ($analyticsRows as $item) {
                    $type = strtolower((string) $item->content_type);
                    if (! in_array($type, ['movie', 'episode'], true)) {
                        continue;
                    }
                    $completed += (int) (bool) $item->completed;
                    $key = $type.':'.$item->content_id;
                    $seconds = max(0, (int) floor((int) $item->watched_ms / 1000));
                    $watchedAt = Carbon::parse($item->ended_at, 'UTC')->setTimezone(config('app.timezone'));
                    $metrics = json_decode((string) $item->metrics_json, true) ?? [];
                    $bytes = data_get($metrics, 'quality.bytes_transferred');
                    $mb = is_numeric($bytes) && $bytes >= 0 ? (float) $bytes / 1_048_576 : null;
                    if ($mb !== null) {
                        $analyticsBandwidthMb = ($analyticsBandwidthMb ?? 0) + $mb;
                    }
                    $analyticContent[$key] ??= ['type' => $type, 'content_id' => (string) $item->content_id,
                        'seconds_watched' => 0, 'bandwidth_mb' => null, 'analytics_bandwidth_mb' => null, 'last_watched_at' => null];
                    $analyticContent[$key]['seconds_watched'] += $seconds;
                    if ($mb !== null) {
                        $analyticContent[$key]['analytics_bandwidth_mb'] = ($analyticContent[$key]['analytics_bandwidth_mb'] ?? 0) + $mb;
                    }
                    $analyticContent[$key]['last_watched_at'] = max($analyticContent[$key]['last_watched_at'] ?? '', $watchedAt->toIso8601String());
                    if (($contentUsage->get($key)['seconds_watched'] ?? 0) === 0) {
                        $date = $watchedAt->toDateString();
                        $daily[$date] = ($daily[$date] ?? 0) + $seconds;
                    }
                }
                foreach ($analyticContent as $key => $analytic) {
                    $row = $contentUsage->get($key, $analytic);
                    if ($row['seconds_watched'] === 0) {
                        $row['seconds_watched'] = $analytic['seconds_watched'];
                    }
                    $row['analytics_bandwidth_mb'] = $analytic['analytics_bandwidth_mb'];
                    $row['last_watched_at'] = max($row['last_watched_at'] ?? '', $analytic['last_watched_at'] ?? '');
                    $contentUsage->put($key, $row);
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
            $completed = null;
            $analyticsAvailable = false;
            $analyticsBandwidthMb = null;
            $daily = $legacyDaily;
        }

        $movieIds = $contentUsage->filter(fn ($item) => $item['type'] === 'movie')->pluck('content_id')->unique();
        $episodeIds = $contentUsage->filter(fn ($item) => $item['type'] === 'episode')->pluck('content_id')->unique();
        $episodes = Schema::hasTable('episodes') && $episodeIds->isNotEmpty()
            ? Episode::with('season')->whereIn('id', $episodeIds)->get()->keyBy(fn ($episode) => (string) $episode->id)
            : collect();
        $movieIds = $movieIds->merge($episodes->pluck('season.movie_id'))->filter()->unique();
        $movieRows = Schema::hasTable('movie') && $movieIds->isNotEmpty()
            ? MovieModel::whereIn('id', $movieIds)->orWhereIn('num', $movieIds)->get(['id', 'num', 'title', 'poster', 'cover_img'])
            : collect();
        $movies = collect();
        foreach ($movieRows as $movie) {
            $movies->put((string) $movie->id, $movie);
            $movies->put((string) $movie->num, $movie);
        }
        $contentUsage = $contentUsage->map(function ($item) use ($movies, $episodes) {
            $episode = $item['type'] === 'episode' ? $episodes->get((string) $item['content_id']) : null;
            $mainMovieId = $episode?->season?->movie_id;
            $movie = $episode ? $movies->get((string) $mainMovieId) : $movies->get((string) $item['content_id']);

            return [
                'type' => $item['type'],
                'content_id' => $item['content_id'],
                'last_watched_at' => $item['last_watched_at'] ?? null,
                'watch_url' => $movie ? 'https://app.zostream.in/watch/'.rawurlencode((string) $movie->id) : null,
                'title' => $movie?->title ?? $episode?->title ?? 'Zo Stream title',
                'subtitle' => $episode?->title,
                'poster' => $episode?->thumbnail ?? $movie?->poster ?? $movie?->cover_img,
                'seconds_watched' => (int) $item['seconds_watched'],
                'bandwidth_mb' => ($bytesUsed = $item['analytics_bandwidth_mb'] ?? $item['bandwidth_mb'] ?? null) === null
                        ? null
                        : round((float) $bytesUsed, 1),
            ];
        })->filter(fn ($item) => $item['seconds_watched'] > 0 || ($item['bandwidth_mb'] ?? 0) > 0)
            ->sortByDesc(fn ($item) => [$item['bandwidth_mb'] ?? -1, $item['seconds_watched']])
            ->values();

        $watchSeconds = $contentUsage->sum('seconds_watched');
        $totalBandwidth = $bandwidth || $analyticsBandwidthMb !== null ? round($contentUsage->sum('bandwidth_mb'), 1) : null;
        $viewedTitles = $sessions || $analyticsAvailable ? $contentUsage->where('seconds_watched', '>', 0)->count() : null;
        $mostWatched = $contentUsage->sortByDesc('seconds_watched')->take(5)->values();
        $contentUsage = $contentUsage->filter(function ($item) use ($filters) {
            $type = $filters['type'] ?? 'all';
            $q = trim((string) ($filters['q'] ?? ''));

            return ($type === 'all' || $type === $item['type'])
                && ($q === '' || mb_stripos($item['title'].' '.($item['subtitle'] ?? ''), $q) !== false);
        });
        $contentUsage = match ($filters['sort'] ?? 'latest') {
            'watch_time' => $contentUsage->sortByDesc('seconds_watched'),
            'data' => $contentUsage->sortByDesc(fn ($item) => $item['bandwidth_mb'] ?? -1),
            default => $contentUsage->sortByDesc(fn ($item) => $item['last_watched_at'] ?? ''),
        };
        $contentUsage = $contentUsage->values();
        $totalContent = $contentUsage->count();
        $lastPage = max(1, (int) ceil($totalContent / $perPage));
        $page = min(max(1, $page), $lastPage);
        $pagedContentUsage = $contentUsage->forPage($page, $perPage)->values();

        $recent = collect();
        if (Schema::hasTable('watch_position')) {
            $orderBy = Schema::hasColumn('watch_position', 'updated_at')
                ? 'updated_at'
                : (Schema::hasColumn('watch_position', 'created_at') ? 'created_at' : 'num');
            $recent = WatchHistoryModel::where('user_id', $userId)
                ->where('position', '>', 0)
                ->orderByDesc($orderBy)
                ->limit(8)
                ->get(['num', 'movie_id', 'movie_type', 'position', 'duration']);
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'watch_minutes' => $sessions || $analyticsAvailable ? (int) floor($watchSeconds / 60) : null,
            'watch_seconds' => $sessions || $analyticsAvailable ? $watchSeconds : null,
            'activity' => collect($daily)->map(fn ($seconds, $date) => ['date' => $date, 'seconds_watched' => $seconds])->values(),
            'playback_sessions' => $sessions?->count(),
            'viewed_titles' => $viewedTitles,
            'completed_sessions' => $completed,
            'bandwidth_mb' => $totalBandwidth,
            'most_watched' => $mostWatched,
            'content_usage' => $pagedContentUsage,
            'content_pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalContent,
                'last_page' => $lastPage,
            ],
            'recent_positions' => $recent,
        ];
    }
}
