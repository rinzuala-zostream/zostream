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

        $session = WatchSession::where('user_id', $validated['user_id'])
            ->where('movie_id', $validated['movie_id'])
            ->where('episode_id', $validated['episode_id'] ?? null)
            ->first();

        if ($session) {
            $session->increment('seconds_watched', $validated['seconds']);
        } else {
            WatchSession::create([
                'user_id' => $validated['user_id'],
                'movie_id' => $validated['movie_id'],
                'episode_id' => $validated['episode_id'] ?? null,
                'seconds_watched' => $validated['seconds'],
                'device_type' => $validated['device_type'] ?? null,
            ]);
        }

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

        $log = BandwidthLog::where('user_id', $validated['user_id'])
            ->where('movie_id', $validated['movie_id'])
            ->where('episode_id', $validated['episode_id'] ?? null)
            ->first();

        if ($log) {
            $log->increment('mb_used', $validated['mb_used']);
        } else {
            BandwidthLog::create([
                'user_id' => $validated['user_id'],
                'movie_id' => $validated['movie_id'],
                'episode_id' => $validated['episode_id'] ?? null,
                'mb_used' => $validated['mb_used'],
                'device_type' => $validated['device_type'] ?? null,
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Total stats for a month (duration + bandwidth).
     */
    public function stats(Request $request, $user_id)
    {
        $type = $request->query('type', 'month');
        $year = $request->query('year', now()->year);
        $month = $request->query('month', now()->month);

        // Base queries
        $watchQuery = WatchSession::where('user_id', $user_id);
        $bandQuery = BandwidthLog::where('user_id', $user_id);

        // Apply year filter
        if (in_array($type, ['month', 'month_movies', 'year', 'year_movies'])) {
            $watchQuery->whereYear('created_at', $year);
            $bandQuery->whereYear('created_at', $year);
        }

        // Apply month filter (monthly only)
        if (in_array($type, ['month', 'month_movies'])) {
            $watchQuery->whereMonth('created_at', $month);
            $bandQuery->whereMonth('created_at', $month);
        }

        // ============ TOTAL STATS (month + year) ============
        if ($type === 'month' || $type === 'year') {

            $seconds = $watchQuery->sum('seconds_watched');
            $mb = $bandQuery->sum('mb_used');

            return response()->json([
                'type' => $type,
                'year' => $year,
                'month' => $type === 'month' ? $month : null,
                'total_seconds' => $seconds,
                'total_minutes' => round($seconds / 60, 2),
                'total_hours' => round($seconds / 3600, 2),
                'total_mb' => round($mb, 2),
                'total_gb' => round($mb / 1024, 2),
            ]);
        }

        // ============ PER-MOVIE STATS (month_movies + year_movies) ============
        if ($type === 'month_movies' || $type === 'year_movies') {

            $sessions = $watchQuery
                ->selectRaw('movie_id, SUM(seconds_watched) as total_seconds')
                ->groupBy('movie_id')
                ->get();

            $movies = $sessions->map(function ($item) use ($user_id, $year, $month, $type) {

                $bandQuery = BandwidthLog::where('user_id', $user_id)
                    ->where('movie_id', $item->movie_id)
                    ->whereYear('created_at', $year);

                if ($type === 'month_movies') {
                    $bandQuery->whereMonth('created_at', $month);
                }

                $mb = $bandQuery->sum('mb_used');

                return [
                    'movie_id' => $item->movie_id,
                    'total_seconds' => (int) $item->total_seconds,
                    'minutes' => round($item->total_seconds / 60, 2),
                    'hours' => round($item->total_seconds / 3600, 2),
                    'bandwidth_mb' => round($mb, 2),
                    'bandwidth_gb' => round($mb / 1024, 2),
                ];
            });

            return response()->json([
                'type' => $type,
                'year' => $year,
                'month' => $type === 'month_movies' ? $month : null,
                'movies' => $movies,
            ]);
        }

        // ============ INVALID TYPE ============
        return response()->json([
            'error' => 'Invalid type. Use month, year, month_movies, year_movies.'
        ], 400);
    }
}
