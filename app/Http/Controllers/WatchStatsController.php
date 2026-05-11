<?php

namespace App\Http\Controllers;

use App\Models\EpisodeModel;
use App\Models\MovieModel;
use App\Models\WatchSession;
use App\Models\BandwidthLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class WatchStatsController extends Controller
{
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
        ]);

        return response()->json(['success' => true]);
    }

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
        ]);

        return response()->json(['success' => true]);
    }

    public function stats(Request $request, $user_id)
    {
        $type = $request->query('type', 'all'); 
        $year = $request->query('year', now()->year);
        $month = $request->query('month', now()->month);

        $watchQuery = WatchSession::where('user_id', $user_id);
        $bandQuery = BandwidthLog::where('user_id', $user_id);

        // ✅ Optimization: Range search instead of whereMonth
        if ($type !== 'all') {
            $start = ($type === 'month') 
                ? Carbon::createFromDate($year, $month, 1)->startOfMonth()
                : Carbon::createFromDate($year, 1, 1)->startOfYear();
            $end = ($type === 'month') ? $start->copy()->endOfMonth() : $start->copy()->endOfYear();

            $watchQuery->whereBetween('created_at', [$start, $end]);
            $bandQuery->whereBetween('created_at', [$start, $end]);
        }

        // Totals
        $seconds = $watchQuery->sum('seconds_watched');
        $mb = $bandQuery->sum('mb_used');

        // Grouped Results
        $sessionGroups = $watchQuery->selectRaw('movie_id, episode_id, SUM(seconds_watched) AS total_seconds')
            ->groupBy('movie_id', 'episode_id')->get();

        $bandGroups = $bandQuery->selectRaw('movie_id, episode_id, SUM(mb_used) AS total_mb')
            ->groupBy('movie_id', 'episode_id')->get()
            ->keyBy(fn($i) => ($i->movie_id ?? 'n').'-'.($i->episode_id ?? 'n'));

        // Bulk Fetching related models
        $movieIds = $sessionGroups->pluck('movie_id')->unique();
        $episodeIds = $sessionGroups->pluck('episode_id')->unique();
        $movies = MovieModel::whereIn('id', $movieIds)->select('id','title','poster')->get()->keyBy('id');
        $episodes = EpisodeModel::whereIn('id', $episodeIds)->select('id','title','movie_id')->get()->keyBy('id');

        $contents = $sessionGroups->map(function ($item) use ($bandGroups, $movies, $episodes) {
            $key = ($item->movie_id ?? 'n').'-'.($item->episode_id ?? 'n');
            $bandwidth = $bandGroups[$key]->total_mb ?? 0;
            $data = !empty($item->episode_id) ? $episodes->get($item->episode_id) : $movies->get($item->movie_id);

            return [
                "type" => !empty($item->episode_id) ? "episode" : "movie",
                "title" => $data->title ?? "Unknown",
                "poster" => $data->poster ?? null,
                "total_seconds" => (int) $item->total_seconds,
                "bandwidth_gb" => round($bandwidth / 1024, 2),
            ];
        });

        return response()->json([
            "total_hours" => round($seconds / 3600, 2),
            "total_gb" => round($mb / 1024, 2),
            "items" => $contents
        ]);
    }

    public function topStats()
    {
        // ✅ Optimization: Cache global results for 1 hour
        return Cache::remember('global_top_stats', 3600, function () {
            $topWatch = WatchSession::selectRaw('movie_id, episode_id, SUM(seconds_watched) AS total_seconds')
                ->groupBy('movie_id', 'episode_id')->orderByDesc('total_seconds')->limit(10)->get();

            $topBandwidth = BandwidthLog::selectRaw('movie_id, episode_id, SUM(mb_used) AS total_mb')
                ->groupBy('movie_id', 'episode_id')->orderByDesc('total_mb')->limit(10)->get();

            return response()->json([
                "top_watch" => $topWatch,
                "top_bandwidth" => $topBandwidth,
            ]);
        });
    }
}