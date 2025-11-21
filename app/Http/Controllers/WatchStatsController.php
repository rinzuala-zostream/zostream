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
    public function statsMonth(Request $request, $user_id)
    {
        $year = $request->year ?? now()->year;
        $month = $request->month ?? now()->month;

        $seconds = WatchSession::where('user_id', $user_id)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->sum('seconds_watched');

        $mb = BandwidthLog::where('user_id', $user_id)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->sum('mb_used');

        return response()->json([
            'year' => $year,
            'month' => $month,
            'total_seconds' => $seconds,
            'total_minutes' => round($seconds / 60, 2),
            'total_hours' => round($seconds / 3600, 2),
            'total_mb' => round($mb, 2),
            'total_gb' => round($mb / 1024, 2),
        ]);
    }

    /**
     * Total stats for a year.
     */
    public function statsYear(Request $request, $user_id)
    {
        $year = $request->year ?? now()->year;

        $seconds = WatchSession::where('user_id', $user_id)
            ->whereYear('created_at', $year)
            ->sum('seconds_watched');

        $mb = BandwidthLog::where('user_id', $user_id)
            ->whereYear('created_at', $year)
            ->sum('mb_used');

        return response()->json([
            'year' => $year,
            'total_seconds' => $seconds,
            'total_minutes' => round($seconds / 60, 2),
            'total_hours' => round($seconds / 3600, 2),
            'total_mb' => round($mb, 2),
            'total_gb' => round($mb / 1024, 2),
        ]);
    }

    /**
     * Per-movie breakdown for a month (with duration + bandwidth).
     */
    public function monthMovies(Request $request, $user_id)
    {
        $year = $request->year ?? now()->year;
        $month = $request->month ?? now()->month;

        $sessions = WatchSession::selectRaw('movie_id, SUM(seconds_watched) as total_seconds')
            ->where('user_id', $user_id)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->groupBy('movie_id')
            ->get();

        $data = $sessions->map(function ($item) use ($user_id, $year, $month) {

            $mb = BandwidthLog::where('user_id', $user_id)
                ->where('movie_id', $item->movie_id)
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->sum('mb_used');

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
            'year' => $year,
            'month' => $month,
            'movies' => $data,
        ]);
    }

    /**
     * Per-movie breakdown for a year.
     */
    public function yearMovies(Request $request, $user_id)
    {
        $year = $request->year ?? now()->year;

        $sessions = WatchSession::selectRaw('movie_id, SUM(seconds_watched) as total_seconds')
            ->where('user_id', $user_id)
            ->whereYear('created_at', $year)
            ->groupBy('movie_id')
            ->get();

        $data = $sessions->map(function ($item) use ($user_id, $year) {

            $mb = BandwidthLog::where('user_id', $user_id)
                ->where('movie_id', $item->movie_id)
                ->whereYear('created_at', $year)
                ->sum('mb_used');

            return [
                'movie_id' => $item->movie_id,
                'total_seconds' => (int) $item->total_seconds,
                'hours' => round($item->total_seconds / 3600, 2),
                'bandwidth_mb' => round($mb, 2),
                'bandwidth_gb' => round($mb / 1024, 2),
            ];
        });

        return response()->json([
            'year' => $year,
            'movies' => $data,
        ]);
    }
}
