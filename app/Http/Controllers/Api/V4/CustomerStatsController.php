<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\BandwidthLog;
use App\Models\WatchSession;
use App\Models\WatchHistoryModel;
use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerStatsController extends Controller
{
    public function show(Request $request)
    {
        $userId = (string) $request->input('auth_user_id');
        return V4Response::success($this->dataForUser(
            $userId,
            max(1, (int) $request->query('page', 1)),
            min(20, max(1, (int) $request->query('per_page', 10)))
        ))
            ->header('Cache-Control', 'private, no-store');
    }

    public function dataForUser(string $userId, int $page = 1, int $perPage = 10): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $sessions = Schema::hasTable('watch_sessions')
            ? WatchSession::where('user_id', $userId)->whereBetween('created_at', [$from, $to])
            : null;
        $bandwidth = Schema::hasTable('bandwidth_logs')
            ? BandwidthLog::where('user_id', $userId)->whereBetween('created_at', [$from, $to])
            : null;

        $contentUsage = collect();
        $viewedTitles = null;
        if ($sessions) {
            $groups = (clone $sessions)->selectRaw('movie_id, episode_id, SUM(seconds_watched) AS seconds_watched')
                ->groupBy('movie_id', 'episode_id')
                ->get();
            $viewedTitles = DB::query()
                ->fromSub(
                    (clone $sessions)->select('movie_id', 'episode_id')->groupBy('movie_id', 'episode_id'),
                    'monthly_viewed_titles'
                )
                ->count();

            foreach ($groups as $item) {
                $type = $item->episode_id ? 'episode' : 'movie';
                $id = (string) ($item->episode_id ?: $item->movie_id);
                $key = $type.':'.$id;
                $contentUsage->put($key, [
                    'type' => $type,
                    'content_id' => $id,
                    'seconds_watched' => (int) $item->seconds_watched,
                    'bandwidth_mb' => null,
                    'analytics_bandwidth_mb' => null,
                ]);
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

        $completed = null;
        $analyticsBandwidthMb = null;
        try {
            if (Schema::connection('analytics')->hasTable('playback_sessions')) {
                $analyticsSessions = DB::connection('analytics')->table('playback_sessions')
                    ->where('user_id', $userId)
                    ->whereBetween('ended_at', [$from->copy()->utc(), $to->copy()->utc()]);
                $completed = (clone $analyticsSessions)->where('completed', true)->count();
                $bytes = (clone $analyticsSessions)
                    ->selectRaw("SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.bytes_transferred')), '0') AS UNSIGNED)) AS bytes_transferred")
                    ->value('bytes_transferred');
                if ($bytes !== null) {
                    $analyticsBandwidthMb = (float) $bytes / 1_048_576;
                }

                $analyticContent = (clone $analyticsSessions)
                    ->selectRaw("content_id, content_type, SUM(watched_ms) AS watched_ms, SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metrics_json, '$.quality.bytes_transferred')), '0') AS UNSIGNED)) AS bytes_transferred")
                    ->groupBy('content_id', 'content_type')
                    ->get();
                foreach ($analyticContent as $item) {
                    $type = strtolower((string) $item->content_type);
                    if (!in_array($type, ['movie', 'episode'], true)) {
                        continue;
                    }
                    $id = (string) $item->content_id;
                    $key = $type.':'.$id;
                    $row = $contentUsage->get($key, [
                        'type' => $type,
                        'content_id' => $id,
                        'seconds_watched' => 0,
                        'bandwidth_mb' => null,
                        'analytics_bandwidth_mb' => null,
                    ]);
                    if ((int) $row['seconds_watched'] === 0) {
                        $row['seconds_watched'] = (int) floor((int) $item->watched_ms / 1000);
                    }
                    if ((int) $item->bytes_transferred > 0) {
                        $row['analytics_bandwidth_mb'] = (float) $item->bytes_transferred / 1_048_576;
                    }
                    $contentUsage->put($key, $row);
                }
            }
        } catch (\Throwable) {
            $completed = null;
        }

        $movieIds = $contentUsage->filter(fn ($item) => $item['type'] === 'movie')->pluck('content_id')->unique();
        $episodeIds = $contentUsage->filter(fn ($item) => $item['type'] === 'episode')->pluck('content_id')->unique();
        $movieRows = MovieModel::whereIn('id', $movieIds)->orWhereIn('num', $movieIds)->get(['id', 'num', 'title', 'poster', 'cover_img']);
        $movies = collect();
        foreach ($movieRows as $movie) {
            $movies->put((string) $movie->id, $movie);
            $movies->put((string) $movie->num, $movie);
        }
        $episodes = Episode::with('season')->whereIn('id', $episodeIds)->get()->keyBy(fn ($episode) => (string) $episode->id);
        $contentUsage = $contentUsage->map(function ($item) use ($movies, $episodes, $analyticsBandwidthMb) {
            $episode = $item['type'] === 'episode' ? $episodes->get((string) $item['content_id']) : null;
            $mainMovieId = $episode?->season?->movie_id;
            $movie = $episode ? $movies->get((string) $mainMovieId) : $movies->get((string) $item['content_id']);
            return [
                'type' => $item['type'],
                'title' => $movie?->title ?? $episode?->title ?? 'Zo Stream title',
                'subtitle' => $episode?->title,
                'poster' => $episode?->thumbnail ?? $movie?->poster ?? $movie?->cover_img,
                'seconds_watched' => (int) $item['seconds_watched'],
                'bandwidth_mb' => ($bytesUsed = $analyticsBandwidthMb > 0
                    ? ($item['analytics_bandwidth_mb'] ?? null)
                    : ($item['bandwidth_mb'] ?? null)) === null
                        ? null
                        : round((float) $bytesUsed, 1),
            ];
        })->filter(fn ($item) => $item['seconds_watched'] > 0 || ($item['bandwidth_mb'] ?? 0) > 0)
            ->sortByDesc(fn ($item) => [$item['bandwidth_mb'] ?? -1, $item['seconds_watched']])
            ->values();

        $totalContent = $contentUsage->count();
        $lastPage = max(1, (int) ceil($totalContent / $perPage));
        $page = min(max(1, $page), $lastPage);
        $mostWatched = $contentUsage->sortByDesc('seconds_watched')->take(5)->values();
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
            'period' => ['from' => $from->toDateString(), 'to' => now()->toDateString()],
            'watch_minutes' => $sessions ? (int) floor($sessions->sum('seconds_watched') / 60) : null,
            'playback_sessions' => $sessions?->count(),
            'viewed_titles' => $viewedTitles,
            'completed_sessions' => $completed,
            'bandwidth_mb' => $analyticsBandwidthMb > 0
                ? round($analyticsBandwidthMb, 1)
                : ($bandwidth ? round((float) $bandwidth->sum('mb_used'), 1) : null),
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
