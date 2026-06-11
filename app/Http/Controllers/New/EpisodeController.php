<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Episode;
use App\Models\New\Season;
use App\Models\New\VideoUrl;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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

            if (!$thumbnail && !empty($videoUrl)) {
                $thumbnail = $this->extractThumbnailFromMpd($videoUrl, $episodeId);
            }

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
            unset(
                $episodeData['url'],
                $episodeData['dash_url'],
                $episodeData['quality'],
                $episodeData['type']
            );

            // Normalize thumbnail from request
            $requestThumbnail = array_key_exists('thumbnail', $episodeData)
                ? trim((string) $episodeData['thumbnail'])
                : null;

            // Check current episode thumbnail
            $currentThumbnail = trim((string) ($episode->thumbnail ?? ''));

            // If request thumbnail is null/empty AND current thumbnail is null/empty,
            // then extract thumbnail from video URL.
            $shouldExtractThumbnail =
                !empty($videoUrl) &&
                empty($requestThumbnail) &&
                empty($currentThumbnail);

            if ($shouldExtractThumbnail) {
                $thumbnail = $this->extractThumbnailFromMpd($videoUrl, $episode->id);

                if ($thumbnail) {
                    $episodeData['thumbnail'] = $thumbnail;
                } else {
                    // Do not overwrite existing thumbnail with empty value
                    unset($episodeData['thumbnail']);
                }
            } else {
                // If thumbnail was sent as empty string, don't update DB with empty string
                if (array_key_exists('thumbnail', $episodeData) && empty($requestThumbnail)) {
                    unset($episodeData['thumbnail']);
                }
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

            return response()->json([
                'status' => 'success',
                'data' => $episode->fresh()
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

        $thumbnail = $this->extractThumbnailFromMpd($url, $episode->id);

        if ($thumbnail) {
            $episode->update(['thumbnail' => $thumbnail]);
        }
    }

    private function extractThumbnailFromMpd(string $rawUrl, string $episodeId): ?string
    {
        $outputPath = null;

        try {
            $mpdUrl = $this->resolveMpdUrl($rawUrl);
            $outputDir = storage_path('app/temp/episode-thumbnails');

            if (!is_dir($outputDir) && !@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                throw new \RuntimeException("Unable to create thumbnail directory: {$outputDir}");
            }

            $fileName = $episodeId . '.jpg';
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;
            $process = new Process([
                'ffmpeg',
                '-y',
                '-ss',
                $this->randomThumbnailSeekTime($mpdUrl),
                '-i',
                str_replace(' ', '%20', $mpdUrl),
                '-frames:v',
                '1',
                '-q:v',
                '2',
                $outputPath,
            ]);

            $process->setTimeout(6);
            $process->run();

            if (!$process->isSuccessful() || !is_file($outputPath)) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'ffmpeg thumbnail extraction failed');
            }

            return $this->uploadEpisodeThumbnailToR2($outputPath);
        } catch (\Throwable $e) {
            Log::warning('Episode thumbnail extraction failed', [
                'episode_id' => $episodeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if ($outputPath && is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private function uploadEpisodeThumbnailToR2(string $localPath): string
    {
        $r2Path = $this->makeEpisodeThumbnailR2Path();
        $stream = fopen($localPath, 'r');

        if ($stream === false) {
            throw new \RuntimeException("Unable to read thumbnail file: {$localPath}");
        }

        try {
            Storage::disk('r2')->put($r2Path, $stream, [
                'visibility' => 'public',
                'ContentType' => 'image/jpeg',
            ]);
        } finally {
            fclose($stream);
        }

        return rtrim(config('filesystems.disks.r2.url'), '/') . '/' . $this->encodeR2Path($r2Path);
    }

    private function makeEpisodeThumbnailR2Path(): string
    {
        return 'thumbnail/episode/' . Str::uuid() . '.jpg';
    }

    private function randomThumbnailSeekTime(string $mpdUrl): string
    {
        $duration = $this->getMpdDurationSeconds($mpdUrl);

        if ($duration <= 0) {
            return '00:00:03';
        }

        $start = max(1, (int) floor($duration * 0.45));
        $end = max($start, (int) ceil($duration * 0.55));

        if ($duration > 20) {
            $end = min($end, (int) $duration - 5);
        }

        return $this->formatSecondsAsTimestamp(random_int($start, max($start, $end)));
    }

    private function getMpdDurationSeconds(string $mpdUrl): float
    {
        try {
            $response = Http::connectTimeout(1)
                ->timeout(2)
                ->get(str_replace(' ', '%20', $mpdUrl));

            if (!$response->successful()) {
                return 0;
            }

            $xml = @simplexml_load_string($response->body());

            if (!$xml || empty($xml['mediaPresentationDuration'])) {
                return 0;
            }

            return $this->parseIso8601Duration((string) $xml['mediaPresentationDuration']);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function parseIso8601Duration(string $duration): float
    {
        if (!preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $duration, $matches)) {
            return 0;
        }

        $days = (int) ($matches[3] ?? 0);
        $hours = (int) ($matches[4] ?? 0);
        $minutes = (int) ($matches[5] ?? 0);
        $seconds = (float) ($matches[6] ?? 0);

        return ($days * 86400) + ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function formatSecondsAsTimestamp(int $seconds): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    private function encodeR2Path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function resolveMpdUrl(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^https?:\/\//i', $raw) && stripos($raw, '.mpd') !== false) {
            return $raw;
        }

        $rawParam = str_replace(' ', '+', $raw);
        $b64 = strtr($rawParam, '-_', '+/');
        $pad = strlen($b64) % 4;

        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $data = base64_decode($b64, true);

        if ($data === false || strlen($data) < 17) {
            throw new \InvalidArgumentException('Invalid MPD URL or encrypted payload.');
        }

        $iv = substr($data, 0, 16);
        $cipherText = substr($data, 16);
        $decryptionKey = hash(
            'sha256',
            'd4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a',
            true
        );

        $decryptedMessage = openssl_decrypt(
            $cipherText,
            'aes-256-cbc',
            $decryptionKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decryptedMessage === false) {
            throw new \InvalidArgumentException('Failed to decrypt MPD URL.');
        }

        $mpdUrl = trim(str_replace(["\r", "\n"], '', $decryptedMessage));
        $mpdUrl = filter_var($mpdUrl, FILTER_VALIDATE_URL) ? $mpdUrl : urldecode($mpdUrl);

        if (!filter_var($mpdUrl, FILTER_VALIDATE_URL) || stripos($mpdUrl, '.mpd') === false) {
            throw new \InvalidArgumentException('Decrypted URL is not an MPD manifest.');
        }

        return $mpdUrl;
    }
}
