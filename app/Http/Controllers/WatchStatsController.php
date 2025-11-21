<?php

namespace App\Http\Controllers;

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
        $type = $request->query('type');      // month / year / null
        $year = $request->query('year', now()->year);
        $month = $request->query('month', now()->month);

        // ========== DEFAULT CASE (null or empty) ==========
        // If no type provided → return ALL DATA + ALL MOVIES
        if (!$type || $type === '') {
            $type = 'all';
        }

        // Base queries
        $watchQuery = WatchSession::where('user_id', $user_id);
        $bandQuery = BandwidthLog::where('user_id', $user_id);

        // ========== APPLY FILTERS ==========
        if ($type === 'month') {
            $watchQuery->whereYear('created_at', $year)
                ->whereMonth('created_at', $month);

            $bandQuery->whereYear('created_at', $year)
                ->whereMonth('created_at', $month);
        } else if ($type === 'year') {
            $watchQuery->whereYear('created_at', $year);
            $bandQuery->whereYear('created_at', $year);
        } else if ($type === 'all') {
            // No date filter → all-time usage
        } else {
            return response()->json([
                "error" => "Invalid type. Allowed: month, year, all"
            ], 400);
        }

        // ========== TOTAL USAGE ==========
        $seconds = $watchQuery->sum('seconds_watched');
        $mb = $bandQuery->sum('mb_used');

        // ========== PER-MOVIE USAGE ==========
        $sessionGroups = $watchQuery
            ->selectRaw('movie_id, SUM(seconds_watched) AS total_seconds')
            ->groupBy('movie_id')
            ->get();

        $movies = $sessionGroups->map(function ($item) use ($user_id, $type, $year, $month) {

            $band = BandwidthLog::where('user_id', $user_id)
                ->where('movie_id', $item->movie_id);

            if ($type === 'month') {
                $band->whereYear('created_at', $year)
                    ->whereMonth('created_at', $month);

            } elseif ($type === 'year') {
                $band->whereYear('created_at', $year);
            }

            $movieMb = $band->sum('mb_used');

            return [
                "movie_id" => $item->movie_id,
                "total_seconds" => (int) $item->total_seconds,
                "minutes" => round($item->total_seconds / 60, 2),
                "hours" => round($item->total_seconds / 3600, 2),
                "bandwidth_mb" => round($movieMb, 2),
                "bandwidth_gb" => round($movieMb / 1024, 2),
            ];
        });

        // ========== FINAL RESPONSE ==========
        return response()->json([
            "type" => $type, // month / year / all
            "year" => $type !== "all" ? $year : null,
            "month" => $type === "month" ? $month : null,

            "total_seconds" => $seconds,
            "total_minutes" => round($seconds / 60, 2),
            "total_hours" => round($seconds / 3600, 2),

            "total_mb" => round($mb, 2),
            "total_gb" => round($mb / 1024, 2),

            "movies" => $movies
        ]);
    }

    public function topStats()
    {
        // 1) TOP 10 MOST WATCHED MOVIES (BY TOTAL SECONDS)
        $topWatch = WatchSession::selectRaw(
            'movie_id, SUM(seconds_watched) AS total_seconds'
        )
            ->groupBy('movie_id')
            ->orderByDesc('total_seconds')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'movie_id' => $item->movie_id,
                    'total_seconds' => (int) $item->total_seconds,
                    'total_minutes' => round($item->total_seconds / 60, 2),
                    'total_hours' => round($item->total_seconds / 3600, 2),
                ];
            });

        // 2) TOP 10 HIGHEST BANDWIDTH USAGE (BY MB)
        $topBandwidth = BandwidthLog::selectRaw(
            'movie_id, SUM(mb_used) AS total_mb'
        )
            ->groupBy('movie_id')
            ->orderByDesc('total_mb')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'movie_id' => $item->movie_id,
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
