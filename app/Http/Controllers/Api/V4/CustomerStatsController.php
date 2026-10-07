<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\BandwidthLog;
use App\Models\WatchSession;
use App\Models\WatchHistoryModel;
use App\Models\MovieModel;
use App\Models\New\Episode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerStatsController extends Controller
{
    public function show(Request $request)
    {
        $userId = (string) $request->input('auth_user_id');
        return response()->json(['success' => true, 'data' => $this->dataForUser($userId)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function dataForUser(string $userId): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $sessions = Schema::hasTable('watch_sessions')
            ? WatchSession::where('user_id', $userId)->whereBetween('created_at', [$from, $to])
            : null;
        $bandwidth = Schema::hasTable('bandwidth_logs')
            ? BandwidthLog::where('user_id', $userId)->whereBetween('created_at', [$from, $to])
            : null;

        $mostWatched = collect();
        $viewedTitles = null;
        if ($sessions) {
            $groups = (clone $sessions)->selectRaw('movie_id, episode_id, SUM(seconds_watched) AS seconds_watched')
                ->groupBy('movie_id', 'episode_id')
                ->orderByDesc('seconds_watched')
                ->limit(5)
                ->get();
            $viewedTitles = DB::query()
                ->fromSub(
                    (clone $sessions)->select('movie_id', 'episode_id')->groupBy('movie_id', 'episode_id'),
                    'monthly_viewed_titles'
                )
                ->count();

            $movieIds = $groups->whereNull('episode_id')->pluck('movie_id')->filter()->unique();
            $episodeIds = $groups->whereNotNull('episode_id')->pluck('episode_id')->filter()->unique();
            $movies = MovieModel::whereIn('id', $movieIds)->get(['id', 'title', 'poster', 'cover_img'])->keyBy('id');
            $episodes = Episode::whereIn('id', $episodeIds)->get(['id', 'title', 'thumbnail'])->keyBy('id');
            $mostWatched = $groups->map(function ($item) use ($movies, $episodes) {
                $episode = $item->episode_id ? $episodes->get($item->episode_id) : null;
                $movie = $episode ? null : $movies->get($item->movie_id);
                return [
                    'title' => $episode?->title ?? $movie?->title ?? 'Zo Stream title',
                    'poster' => $episode?->thumbnail ?? $movie?->poster ?? $movie?->cover_img,
                    'seconds_watched' => (int) $item->seconds_watched,
                    'type' => $episode ? 'episode' : 'movie',
                ];
            })->values();
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
            }
        } catch (\Throwable) {
            $completed = null;
        }

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
            'recent_positions' => $recent,
        ];
    }
}
