<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use App\Models\MovieModel;
use App\Models\WatchSession;
use App\Models\BandwidthLog;
use Illuminate\Http\Request;

class WatchStatsController extends Controller
{
    /**
     * Log watch duration (called every X seconds).
     */
    public function logDuration(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|string',
            'movie_id' => 'required|string',
            'episode_id' => 'nullable|string',
            'seconds' => 'required|integer|min:1',
            'device_type' => 'nullable|string|max:20',
        ]);

        WatchSession::create([
            'user_id' => $validated['user_id'],
            'movie_id' => $validated['movie_id'],
            'episode_id' => $validated['episode_id'] ?? null,
            'seconds_watched' => $validated['seconds'],
            'device_type' => $validated['device_type'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Log bandwidth usage in MB.
     */
    public function logBandwidth(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|string',
            'movie_id' => 'required|string',
            'episode_id' => 'nullable|string',
            'mb_used' => 'required|numeric|min:0.01',
            'device_type' => 'nullable|string|max:20',
        ]);

        BandwidthLog::create([
            'user_id' => $validated['user_id'],
            'movie_id' => $validated['movie_id'],
            'episode_id' => $validated['episode_id'] ?? null,
            'mb_used' => $validated['mb_used'],
            'device_type' => $validated['device_type'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Total stats for a month (duration + bandwidth).
     */
    public function stats(Request $request, $user_id)
    {
        $type = $request->query('type', 'all'); // all / month / year
        $year = $request->query('year', now()->year);
        $month = $request->query('month', now()->month);

        // Base queries
        $watchQuery = WatchSession::where('user_id', $user_id);
        $bandQuery = BandwidthLog::where('user_id', $user_id);

        // Apply filters
        if ($type === 'month') {
            $watchQuery->whereYear('created_at', $year)->whereMonth('created_at', $month);
            $bandQuery->whereYear('created_at', $year)->whereMonth('created_at', $month);
        } elseif ($type === 'year') {
            $watchQuery->whereYear('created_at', $year);
            $bandQuery->whereYear('created_at', $year);
        } elseif ($type !== 'all') {
            return response()->json(["error" => "Invalid type. Allowed: month, year, all"], 400);
        }

        // Total usage
        $seconds = $watchQuery->sum('seconds_watched');
        $mb = $bandQuery->sum('mb_used');

        // Grouped per content (movie or episode)
        $sessionGroups = $watchQuery
            ->selectRaw('movie_id, episode_id, SUM(seconds_watched) AS total_seconds')
            ->groupBy('movie_id', 'episode_id')
            ->get();

        $bandGroups = $bandQuery
            ->selectRaw('movie_id, episode_id, SUM(mb_used) AS total_mb')
            ->groupBy('movie_id', 'episode_id')
            ->get()
            ->keyBy(function ($item) {
                return ($item->movie_id ?? 'null') . '-' . ($item->episode_id ?? 'null');
            });

        // Collect IDs for fetching related data
        $movieIds = $sessionGroups->pluck('movie_id')->filter()->unique();
        $episodeIds = $sessionGroups->pluck('episode_id')->filter()->unique();

        // Fetch movies and episodes in bulk
        $moviesData = MovieModel::whereIn('id', $movieIds)
            ->select('id', 'title', 'poster')
            ->get()
            ->keyBy('id');

        $episodesData = EpisodeModel::whereIn('id', $episodeIds)
            ->select('id', 'title', 'movie_id')
            ->get()
            ->keyBy('id');

        // Combine all into unified response
        $contents = $sessionGroups->map(function ($item) use ($bandGroups, $moviesData, $episodesData) {
            $key = ($item->movie_id ?? 'null') . '-' . ($item->episode_id ?? 'null');
            $bandwidth = $bandGroups[$key]->total_mb ?? 0;

            // If this is an episode
            if (!empty($item->episode_id)) {
                $ep = $episodesData[$item->episode_id] ?? null;
                return [
                    "type" => "episode",
                    "episode_id" => $item->episode_id,
                    "title" => $ep->title ?? "Unknown Episode",
                    "poster" => $ep->poster ?? null,
                    "movie_id" => $ep->movie_id ?? $item->movie_id,
                    "total_seconds" => (int) $item->total_seconds,
                    "minutes" => round($item->total_seconds / 60, 2),
                    "hours" => round($item->total_seconds / 3600, 2),
                    "bandwidth_mb" => round($bandwidth, 2),
                    "bandwidth_gb" => round($bandwidth / 1024, 2),
                ];
            }

            // Else, this is a movie
            $movie = $moviesData[$item->movie_id] ?? null;
            return [
                "type" => "movie",
                "movie_id" => $item->movie_id,
                "title" => $movie->title ?? "Unknown Movie",
                "poster" => $movie->poster ?? null,
                "total_seconds" => (int) $item->total_seconds,
                "minutes" => round($item->total_seconds / 60, 2),
                "hours" => round($item->total_seconds / 3600, 2),
                "bandwidth_mb" => round($bandwidth, 2),
                "bandwidth_gb" => round($bandwidth / 1024, 2),
            ];
        })->values();

        // Final response
        return response()->json([
            "type" => $type,
            "year" => $type !== "all" ? $year : null,
            "month" => $type === "month" ? $month : null,

            "total_seconds" => $seconds,
            "total_minutes" => round($seconds / 60, 2),
            "total_hours" => round($seconds / 3600, 2),

            "total_mb" => round($mb, 2),
            "total_gb" => round($mb / 1024, 2),

            "total_items_watched" => $sessionGroups->count(),
            "items" => $contents
        ]);
    }

    /**
     * Get top 10 movies/episodes by watch time and bandwidth.
     */
    public function topStats()
    {
        // Top watched (by seconds)
        $topWatch = WatchSession::selectRaw('movie_id, episode_id, SUM(seconds_watched) AS total_seconds')
            ->groupBy('movie_id', 'episode_id')
            ->orderByDesc('total_seconds')
            ->limit(10)
            ->get();

        // Top by bandwidth (by MB)
        $topBandwidth = BandwidthLog::selectRaw('movie_id, episode_id, SUM(mb_used) AS total_mb')
            ->groupBy('movie_id', 'episode_id')
            ->orderByDesc('total_mb')
            ->limit(10)
            ->get();

        // Merge all content IDs
        $movieIds = $topWatch->pluck('movie_id')
            ->merge($topBandwidth->pluck('movie_id'))
            ->filter()
            ->unique();

        $episodeIds = $topWatch->pluck('episode_id')
            ->merge($topBandwidth->pluck('episode_id'))
            ->filter()
            ->unique();

        // Fetch content info
        $movies = MovieModel::whereIn('id', $movieIds)
            ->select('id', 'title', 'poster')
            ->get()
            ->keyBy('id');

        $episodes = EpisodeModel::whereIn('id', $episodeIds)
            ->select('id', 'title', 'movie_id')
            ->get()
            ->keyBy('id');

        // Attach info
        $topWatch = $topWatch->map(function ($item) use ($movies, $episodes) {
            if (!empty($item->episode_id)) {
                $ep = $episodes[$item->episode_id] ?? null;
                return [
                    'type' => 'episode',
                    'episode_id' => $item->episode_id,
                    'title' => $ep->title ?? 'Unknown Episode',
                    'poster' => $ep->poster ?? null,
                    'movie_id' => $ep->movie_id ?? $item->movie_id,
                    'total_seconds' => (int) $item->total_seconds,
                    'total_minutes' => round($item->total_seconds / 60, 2),
                    'total_hours' => round($item->total_seconds / 3600, 2),
                ];
            }

            $movie = $movies[$item->movie_id] ?? null;
            return [
                'type' => 'movie',
                'movie_id' => $item->movie_id,
                'title' => $movie->title ?? 'Unknown Movie',
                'poster' => $movie->poster ?? null,
                'total_seconds' => (int) $item->total_seconds,
                'total_minutes' => round($item->total_seconds / 60, 2),
                'total_hours' => round($item->total_seconds / 3600, 2),
            ];
        });

        $topBandwidth = $topBandwidth->map(function ($item) use ($movies, $episodes) {
            if (!empty($item->episode_id)) {
                $ep = $episodes[$item->episode_id] ?? null;
                return [
                    'type' => 'episode',
                    'episode_id' => $item->episode_id,
                    'title' => $ep->title ?? 'Unknown Episode',
                    'poster' => $ep->poster ?? null,
                    'movie_id' => $ep->movie_id ?? $item->movie_id,
                    'total_mb' => round($item->total_mb, 2),
                    'total_gb' => round($item->total_mb / 1024, 2),
                ];
            }

            $movie = $movies[$item->movie_id] ?? null;
            return [
                'type' => 'movie',
                'movie_id' => $item->movie_id,
                'title' => $movie->title ?? 'Unknown Movie',
                'poster' => $movie->poster ?? null,
                'total_mb' => round($item->total_mb, 2),
                'total_gb' => round($item->total_mb / 1024, 2),
            ];
        });

        return response()->json([
            "top_watch_hours" => $topWatch,
            "top_bandwidth" => $topBandwidth,
        ]);
    }
}
