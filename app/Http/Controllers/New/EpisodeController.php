<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Episode;
use App\Models\New\Season;
use App\Models\New\VideoUrl;
use App\Jobs\ExtractEpisodeThumbnail;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EpisodeController extends Controller
{
    // Get all episodes of a season
    public function index($seasonId)
    {
        try {

            $season = Season::where('id', $seasonId)->first();

            if (!$season) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Season not found'
                ], 404);
            }

            $episodes = Episode::where('season_id', $seasonId)
                ->orderBy('episode_number')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $episodes
            ]);

        } catch (\Exception $e) {

            Log::error('Episode index error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch episodes: ' . $e->getMessage()
            ], 500);
        }
    }

    // Create episode
    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'season_id' => 'required|string|exists:seasons,id',
                'episode_number' => 'required|integer',
                'isPayPerView' => 'nullable|boolean',
                'amount' => 'nullable|numeric|min:0',
                'isPremium' => 'nullable|boolean',
                'title' => 'nullable|string',
                'description' => 'nullable|string',
                'thumbnail' => 'nullable|string',
                'release_date' => 'nullable|date',
                'is_active' => 'nullable|boolean',
                'status' => 'nullable|in:Draft,Published,Scheduled',
                'url' => 'nullable|string',
                'dash_url' => 'nullable|string',
                'quality' => 'nullable|string',
                'type' => 'nullable|string'
            ]);

            $episodeId = (string) Str::uuid();
            $thumbnail = $validated['thumbnail'] ?? null;
            $videoUrl = $validated['url'] ?? $validated['dash_url'] ?? null;

            $episode = Episode::create([
                'id' => $episodeId,
                'amount' => $validated['amount'] ?? 0,
                'isPayPerView' => $validated['isPayPerView'] ?? false,
                'isPremium' => $validated['isPremium'] ?? false,
                'season_id' => $validated['season_id'],
                'episode_number' => $validated['episode_number'],
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'thumbnail' => $thumbnail,
                'release_date' => $validated['release_date'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'status' => $validated['status'] ?? 'Draft'
            ]);

            if (!empty($videoUrl)) {
                $season = Season::where('id', $validated['season_id'])->first();

                VideoUrl::create([
                    'id' => (string) Str::uuid(),
                    'movie_id' => $season->movie_id,
                    'episode_id' => $episode->id,
                    'quality' => $validated['quality'] ?? 'HD',
                    'type' => $validated['type'] ?? 'DASH',
                    'url' => $videoUrl,
                ]);

                if (empty($thumbnail)) {
                    $this->runEpisodeThumbnailExtraction($episode->id, $videoUrl);
                    $episode = $episode->fresh();
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $episode
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            Log::error('Episode create error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create episode: ' . $e->getMessage()
            ], 500);
        }
    }

    // Show single episode
    public function show($id)
    {
        try {

            $episode = Episode::with('season.movie')->where('id', $id)->first();

            if (!$episode) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Episode not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $episode
            ]);

        } catch (\Exception $e) {

            Log::error('Episode show error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch episode: ' . $e->getMessage()
            ], 500);
        }
    }

    // Update episode
    public function update(Request $request, $id)
    {
        try {

            $episode = Episode::where('id', $id)->first();

            if (!$episode) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Episode not found'
                ], 404);
            }

            $validated = $request->validate([
                'season_id' => 'nullable|string|exists:seasons,id',
                'isPayPerView' => 'nullable|boolean',
                'amount' => 'nullable|numeric|min:0',
                'episode_number' => 'nullable|integer',
                'title' => 'nullable|string',
                'description' => 'nullable|string',
                'thumbnail' => 'nullable|string',
                'release_date' => 'nullable|date',
                'is_active' => 'nullable|boolean',
                'status' => 'nullable|in:Draft,Published,Scheduled',
                'isPremium' => 'nullable|boolean',
                'url' => 'nullable|string',
                'dash_url' => 'nullable|string',
                'quality' => 'nullable|string',
                'type' => 'nullable|string'
            ]);

            $videoUrl = $validated['url'] ?? $validated['dash_url'] ?? null;
            $episodeData = $validated;
            unset($episodeData['url'], $episodeData['dash_url'], $episodeData['quality'], $episodeData['type']);

            $hasRequestThumbnail = array_key_exists('thumbnail', $episodeData) && !empty($episodeData['thumbnail']);

            if (array_key_exists('thumbnail', $episodeData) && $episodeData['thumbnail'] === '') {
                $episodeData['thumbnail'] = null;
            }

            $episode->update($episodeData);

            if (!empty($videoUrl)) {
                $freshEpisode = $episode->fresh();
                $season = Season::where('id', $freshEpisode->season_id)->first();
                $video = VideoUrl::where('episode_id', $freshEpisode->id)->first();

                if ($season) {
                    $videoData = [
                        'movie_id' => $season->movie_id,
                        'episode_id' => $freshEpisode->id,
                        'quality' => $validated['quality'] ?? $video?->quality ?? 'HD',
                        'type' => $validated['type'] ?? $video?->type ?? 'DASH',
                        'url' => $videoUrl,
                    ];

                    if ($video) {
                        $video->update($videoData);
                    } else {
                        VideoUrl::create(array_merge([
                            'id' => (string) Str::uuid(),
                        ], $videoData));
                    }
                }
            }

            $freshEpisode = $episode->fresh();

            if (!empty($videoUrl) && !$hasRequestThumbnail) {
                $this->runEpisodeThumbnailExtraction($freshEpisode->id, $videoUrl, true);
                $freshEpisode = $episode->fresh();
            }

            return response()->json([
                'status' => 'success',
                'data' => $freshEpisode
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            Log::error('Episode update error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update episode: ' . $e->getMessage()
            ], 500);
        }
    }

    // Delete episode
    public function destroy($id)
    {
        try {

            $episode = Episode::where('id', $id)->first();

            if (!$episode) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Episode not found'
                ], 404);
            }

            $episode->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Episode deleted'
            ]);

        } catch (\Exception $e) {

            Log::error('Episode delete error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete episode: ' . $e->getMessage()
            ], 500);
        }
    }

    public function addUrl(Request $request)
    {
        try {

            $validated = $request->validate([
                'movie_id' => 'required|integer|exists:movie,num',
                'episode_id' => 'nullable|string|exists:episodes,id',
                'quality' => 'nullable|string',
                'type' => 'nullable|string',
                'url' => 'required|string'
            ]);

            $video = VideoUrl::create([
                'id' => (string) Str::uuid(),
                'movie_id' => $validated['movie_id'],
                'episode_id' => $validated['episode_id'] ?? null,
                'quality' => $validated['quality'] ?? 'HD',
                'type' => $validated['type'] ?? 'DASH',
                'url' => $validated['url'],
            ]);

            if (!empty($validated['episode_id'])) {
                $this->setEpisodeThumbnailFromMpd($validated['episode_id'], $validated['url']);
            }

            return response()->json([
                'status' => 'success',
                'data' => $video
            ]);

        } catch (ValidationException $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            \Log::error('Add video url error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add video url'
            ], 500);
        }
    }

    public function updateUrl(Request $request, $id)
    {
        try {

            $video = VideoUrl::where('id', $id)->first();

            if (!$video) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Video not found'
                ], 404);
            }

            $validated = $request->validate([
                'quality' => 'nullable|string',
                'type' => 'nullable|string',
                'url' => 'nullable|string'
            ]);

            $video->update($validated);

            if (!empty($validated['url']) && !empty($video->episode_id)) {
                $this->setEpisodeThumbnailFromMpd($video->episode_id, $validated['url']);
            }

            return response()->json([
                'status' => 'success',
                'data' => $video->fresh()
            ]);

        } catch (\Exception $e) {

            \Log::error('Update video url error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update video url'
            ], 500);
        }
    }

    public function deleteUrl($id)
    {
        try {

            $video = VideoUrl::where('id', $id)->first();

            if (!$video) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Video not found'
                ], 404);
            }

            $video->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Video url deleted'
            ]);

        } catch (\Exception $e) {

            \Log::error('Delete video url error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete video url'
            ], 500);
        }
    }

    private function setEpisodeThumbnailFromMpd(string $episodeId, string $url): void
    {
        $episode = Episode::where('id', $episodeId)->first();

        if (!$episode || !empty($episode->thumbnail)) {
            return;
        }

        $this->runEpisodeThumbnailExtraction($episode->id, $url);
    }

    private function runEpisodeThumbnailExtraction(string $episodeId, string $url, bool $replaceExisting = false): void
    {
        try {
            (new ExtractEpisodeThumbnail($episodeId, $url, $replaceExisting))->handle();
        } catch (\Throwable $e) {
            Log::warning('Episode thumbnail extraction failed', [
                'episode_id' => $episodeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
