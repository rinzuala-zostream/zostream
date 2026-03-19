<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use App\Models\New\Reel;
use App\Models\New\ReelComment;
use App\Models\New\ReelLike;
use App\Models\New\ReelView;
use Http;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Storage;
use Str;

class ReelController extends Controller
{
    /**
     * ➕ Create Reel
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'nullable|string|max:255',

                'video' => 'nullable|file|mimes:mp4,mov,mkv,webm|max:20480',
                'video_url' => 'nullable|url|max:2048',

                'thumbnail' => 'nullable|image|max:10240',
                'thumbnail_url' => 'nullable|url|max:2048',

                'encode' => 'nullable|in:true,false,1,0',

                'caption' => 'nullable|string',
                'duration_ms' => 'nullable|integer|min:0',
                'category' => 'nullable|string|max:255',
                'language' => 'nullable|string|max:100',
                'status' => 'nullable|string|in:active,inactive,draft',
            ]);

            $userId = $request->input('user_id', $request->header('X-USER-ID'));

            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'user_id is required'
                ], 422);
            }

            $hasVideoFile = $request->hasFile('video') && $request->file('video')->isValid();
            $hasVideoUrl = !empty($request->input('video_url'));

            if (!$hasVideoFile && !$hasVideoUrl) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'video file or video_url is required'
                ], 422);
            }

            $encode = filter_var($request->input('encode', true), FILTER_VALIDATE_BOOLEAN);

            $videoUrl = $request->input('video_url');
            $thumbnailUrl = $request->input('thumbnail_url');
            $uuid = (string) Str::uuid();

            // =========================
            // 🎬 VIDEO HANDLING
            // =========================
            if ($request->hasFile('video')) {

                if ($encode) {
                    $result = $this->processVideo($request->file('video'), $uuid);
                } else {
                    $result = $this->uploadOriginalVideo($request->file('video'), $uuid);
                }

                $videoUrl = $result['video_url'];
                $thumbnailUrl = $result['thumbnail_url'];
                $durationMs = $result['duration_ms'] ?? null;
            }

            // =========================
            // 🖼 OVERRIDE THUMBNAIL
            // =========================
            if ($request->hasFile('thumbnail')) {

                $thumb = $request->file('thumbnail');
                $thumbName = Str::uuid() . '.' . $thumb->getClientOriginalExtension();

                $thumbPath = Storage::disk('r2')->putFileAs(
                    'reels/thumbs',
                    $thumb,
                    $thumbName,
                    [
                        'visibility' => 'public',
                        'ContentType' => $thumb->getMimeType()
                    ]
                );

                $thumbnailUrl = rtrim(config('filesystems.disks.r2.url'), '/') . '/' . $thumbPath;
            }

            // =========================
            // 💾 SAVE
            // =========================
            $reel = Reel::create([
                'user_id' => $userId,
                'uuid' => $uuid,
                'video_url' => $videoUrl,
                'thumbnail_url' => $thumbnailUrl,
                'caption' => $request->caption,
                'duration_ms' => $durationMs, // ✅ FIXED
                'category' => $request->category,
                'language' => $request->language,
                'status' => $request->input('status', 'active'),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $reel
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================
    // 🎬 HLS ENCODING
    // =========================
    private function processVideo($videoFile, $uuid): array
    {
        $tempPath = storage_path("app/temp/{$uuid}.mp4");
        $outputDir = storage_path("app/hls/{$uuid}");

        if (!file_exists(dirname($tempPath)))
            mkdir(dirname($tempPath), 0777, true);
        if (!file_exists($outputDir))
            mkdir($outputDir, 0777, true);

        $videoFile->move(dirname($tempPath), basename($tempPath));

        $playlist = "{$outputDir}/master.m3u8";
        $segment = "{$outputDir}/segment_%03d.ts";
        $thumb = "{$outputDir}/thumb.jpg";

        // HLS
        exec(sprintf(
            'ffmpeg -y -i %s -preset veryfast -g 48 -sc_threshold 0 -map 0:v:0 -map 0:a? -c:v libx264 -c:a aac -f hls -hls_time 4 -hls_segment_filename %s %s',
            escapeshellarg($tempPath),
            escapeshellarg($segment),
            escapeshellarg($playlist)
        ));

        // Thumbnail
        exec(sprintf(
            'ffmpeg -y -i %s -ss 00:00:01 -vframes 1 %s',
            escapeshellarg($tempPath),
            escapeshellarg($thumb)
        ));

        // Upload
        foreach (scandir($outputDir) as $file) {
            if (in_array($file, ['.', '..']))
                continue;

            Storage::disk('r2')->put(
                "reels/{$uuid}/{$file}",
                fopen("{$outputDir}/{$file}", 'r'),
                ['visibility' => 'public']
            );
        }

        $base = config('filesystems.disks.r2.url') . "/reels/{$uuid}";

        $this->cleanup($tempPath, $outputDir);

        // BEFORE moving file
        $duration = $this->getVideoDuration($tempPath);

        return [
            'video_url' => "{$base}/master.m3u8",
            'thumbnail_url' => "{$base}/thumb.jpg",
            'duration_ms' => (int) ($duration * 1000),
        ];
    }

    // =========================
    // ⚡ DIRECT UPLOAD
    // =========================
    private function uploadOriginalVideo($videoFile, $uuid): array
    {
        $ext = $videoFile->getClientOriginalExtension();

        $videoPath = "reels/{$uuid}.{$ext}";
        $thumbLocal = storage_path("app/temp/{$uuid}.jpg");

        if (!file_exists(dirname($thumbLocal)))
            mkdir(dirname($thumbLocal), 0777, true);

        // 🔥 GET DURATION
        $duration = $this->getVideoDuration($videoFile->getRealPath());

        // Upload video
        Storage::disk('r2')->putFileAs(
            'reels',
            $videoFile,
            "{$uuid}.{$ext}",
            ['visibility' => 'public']
        );

        // Thumbnail
        exec(sprintf(
            'ffmpeg -y -i %s -ss 00:00:01 -vframes 1 %s',
            escapeshellarg($videoFile->getRealPath()),
            escapeshellarg($thumbLocal)
        ));

        Storage::disk('r2')->put(
            "reels/thumbs/{$uuid}.jpg",
            fopen($thumbLocal, 'r'),
            ['visibility' => 'public']
        );

        unlink($thumbLocal);

        $base = config('filesystems.disks.r2.url');

        return [
            'video_url' => "{$base}/{$videoPath}",
            'thumbnail_url' => "{$base}/reels/thumbs/{$uuid}.jpg",
            'duration_ms' => (int) ($duration * 1000), // 🔥 convert to ms
        ];
    }

    private function cleanup($temp, $dir)
    {
        @unlink($temp);

        foreach (glob($dir . '/*') as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    private function getVideoDuration($filePath)
    {
        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            escapeshellarg($filePath)
        );

        $duration = shell_exec($command);

        return $duration ? (float) $duration : 0;
    }

    /**
     * 🎬 Get Feed
     */
    public function feed(Request $request)
    {
        $limit = (int) $request->get('limit', 10);
        $cursor = (int) $request->get('cursor', 0);

        $query = Reel::query()
            ->where('status', 'active')
            ->withCount([
                'likes as like_count',
                'comments as comment_count',
                'views as view_count',
            ])
            ->orderByDesc('id')
            ->limit($limit);

        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }

        $final = $query->get();

        return response()->json([
            'data' => $final,
            'next_cursor' => $final->isNotEmpty() ? $final->last()->id : null
        ]);
    }
    /**
     * ❤️ Like Reel
     */
    public function like(Request $request, $id)
    {
        $userId = $request->header('X-USER-ID');

        $reel = Reel::find($id);

        if (!$reel) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reel not found'
            ], 404);
        }

        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'user_id is required'
            ], 422);
        }

        ReelLike::firstOrCreate([
            'reel_id' => $id,
            'user_id' => $userId
        ], [
            'created_at' => now()
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * 💬 List Reel Comments
     */
    public function comments(Request $request, $id)
    {
        $limit = (int) $request->get('limit', 20);

        $reel = Reel::find($id);

        if (!$reel) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reel not found'
            ], 404);
        }

        $comments = ReelComment::where('reel_id', $id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $comments
        ]);
    }

    /**
     * ➕ Add Reel Comment
     */
    public function comment(Request $request, $id)
    {
        try {
            $request->validate([
                'user_id' => 'nullable|string|max:255',
                'comment' => 'required|string|max:2000',
            ]);

            $reel = Reel::find($id);

            if (!$reel) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Reel not found'
                ], 404);
            }

            $userId = $request->input('user_id', $request->header('X-USER-ID'));

            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'user_id is required'
                ], 422);
            }

            $comment = ReelComment::create([
                'reel_id' => $id,
                'user_id' => $userId,
                'comment' => $request->comment,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Comment added successfully',
                'data' => $comment
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add comment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 👁️ Track Watch
     */
    public function watch(Request $request, $id)
    {
        $userId = $request->header('X-USER-ID');
        $reel = Reel::find($id);

        if (!$reel) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reel not found'
            ], 404);
        }

        if (!$userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'user_id is required'
            ], 422);
        }

        $watchTime = $request->watch_time_ms;
        $duration = $request->duration_ms;

        $completionRate = $duration > 0 ? $watchTime / $duration : 0;

        ReelView::create([
            'reel_id' => $id,
            'user_id' => $userId,
            'watch_time_ms' => $watchTime,
            'duration_ms' => $duration,
            'completion_rate' => $completionRate,
            'completed' => $completionRate >= 0.9,
            'skipped' => $completionRate < 0.3,
            'created_at' => now()
        ]);

        // Send to AI
        Http::post('http://127.0.0.1:8001/event', [
            'user_id' => $userId,
            'reel_id' => $id,
            'watch_time' => $watchTime,
            'completion_rate' => $completionRate
        ]);

        return response()->json(['success' => true]);
    }

    public function generateFeed(Request $request)
    {
        $userId = $request->header('X-USER-ID');

        if (!$userId) {
            return response()->json(['error' => 'Missing user id'], 400);
        }

        // ✅ STEP 1: Get candidates
        $candidates = Reel::where('status', 'active')
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('id')
            ->toArray();

        if (empty($candidates)) {
            return response()->json(['data' => []]);
        }

        // ✅ STEP 2: Call AI
        $ai = \Http::timeout(3)->post('http://127.0.0.1:8001/recommend', [
            'user_id' => $userId,
            'reel_ids' => $candidates
        ]);

        if (!$ai->successful()) {
            return response()->json(['error' => 'AI failed'], 500);
        }

        $sorted = $ai->json()['sorted'] ?? [];

        if (empty($sorted)) {
            return response()->json(['data' => []]);
        }

        // 🔥 Limit results
        $sorted = array_slice($sorted, 0, 30);

        // ✅ STEP 3: Save to DB
        \DB::transaction(function () use ($userId, $sorted) {

            \DB::table('user_feeds')->where('user_id', $userId)->delete();

            $data = [];
            $position = 1;

            foreach ($sorted as $item) {
                $data[] = [
                    'user_id' => $userId,
                    'reel_id' => $item['id'],
                    'score' => $item['score'],
                    'position' => $position++,
                    'created_at' => now()
                ];
            }

            \DB::table('user_feeds')->insert($data);
        });

        // ✅ STEP 4: Return reels
        $ids = array_column($sorted, 'id');

        $reels = Reel::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $final = [];
        foreach ($ids as $id) {
            if (isset($reels[$id])) {
                $final[] = $reels[$id];
            }
        }

        return response()->json([
            'source' => 'manual_ai',
            'data' => $final
        ]);
    }

    private function storeFeed($userId, $sorted)
    {
        \DB::table('user_feeds')->where('user_id', $userId)->delete();

        $data = [];
        $position = 1;

        foreach ($sorted as $item) {
            $data[] = [
                'user_id' => $userId,
                'reel_id' => $item['id'],
                'score' => $item['score'],
                'position' => $position++,
                'created_at' => now()
            ];
        }

        \DB::table('user_feeds')->insert($data);
    }
}
