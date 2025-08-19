<?php

namespace App\Http\Controllers;

use App\Models\AdsModel;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Exception;
use Str;

class AdsController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

    /**
     * Standardized success response
     */
    private function success($message, $data = null, $statusCode = 200)
    {
        return response()->json([
            "status" => "success",
            "message" => $message,
            "data" => $data
        ], $statusCode);
    }

    /**
     * Standardized error response
     */
    private function error($message, $statusCode = 400, $errors = null)
    {
        return response()->json([
            "status" => "error",
            "message" => $message,
            "errors" => $errors
        ], $statusCode);
    }

    public function getAds(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }
        $query = AdsModel::query();
        if ($request->has('video_url')) {
            $query->where('video_url', $request->query('video_url'));
        }
        $ads = $query->get();
        $filtered = $ads->filter(function ($ad) {
            $createDate = Carbon::parse($ad->create_date);
            $endDate = $createDate->copy()->addDays($ad->period);
            $now = Carbon::now();
            return $now->between($createDate, $endDate);
        });
        return response()->json(array_values($filtered->toArray()));
    }

    public function store(Request $request)
    {
        if ($request->header('X-Api-Key') !== $this->validApiKey) {
            return $this->error("Invalid API key", 401);
        }

        try {
            $validated = $request->validate([
                'ads_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'period' => 'required|integer|min:0',
                'type' => 'required|string|in:website,video,image',
                'video_url' => 'nullable|url',
                // keep for backward compat; will be ignored & overwritten
                'ads_url' => 'nullable|url',
                'feature_img' => 'nullable|url',
                'img1' => 'nullable|url',
                'img2' => 'nullable|url',
                'img3' => 'nullable|url',
                'img4' => 'nullable|url',
                'create_date' => 'nullable|string',
                'images' => 'nullable|array|max:4',
                'images.*' => 'nullable|url',
            ]);

            if (($validated['type'] ?? null) === 'video' && empty($validated['video_url'])) {
                return $this->error("video_url is required when type is 'video'", 422);
            }

            $createDate = $validated['create_date'] ?? Carbon::now()->format('F j, Y');

            // If client sends images[], spread into img1..img4
            if (!empty($validated['images'])) {
                $pad = array_pad($validated['images'], 4, null);
                [$validated['img1'], $validated['img2'], $validated['img3'], $validated['img4']] = $pad;
            }

            // Create without trusting client ads_url
            $ad = AdsModel::create([
                'ads_name' => $validated['ads_name'],
                'create_date' => $createDate,
                'description' => $validated['description'] ?? null,
                'period' => $validated['period'],
                'type' => $validated['type'],
                'video_url' => $validated['video_url'] ?? null,
                'ads_url' => null, // set after permalink generation
                'feature_img' => $validated['feature_img'] ?? null,
                'img1' => $validated['img1'] ?? null,
                'img2' => $validated['img2'] ?? null,
                'img3' => $validated['img3'] ?? null,
                'img4' => $validated['img4'] ?? null,
            ]);

            // Generate permalink like /ads/12-i1-thrift-store
            $pk = $ad->getKey(); // ✅ works regardless of PK name (num/id/uuid)
            $slug = Str::slug($ad->ads_name);
            $permalink = route('ads.show', ['ad' => "{$pk}-{$slug}"]);
            $ad->ads_url = $permalink;
            $ad->save();

            return $this->success("Ad created successfully", $ad->fresh(), 201);
        } catch (\Throwable $e) {
            return $this->error("Failed to create ad", 500, $e->getMessage());
        }
    }

    // in AdsController
    public function show(string $ad)
    {
        // supports /ads/10 or /ads/10-i1-thrift-store
        $numPart = (int) \Illuminate\Support\Str::before($ad, '-');
        if ($numPart <= 0) {
            abort(404); // no numeric id/num in URL
        }
        $adModel = AdsModel::findOrFail($numPart);
        return view('ads.show', ['ad' => $adModel]);
    }


    public function update(Request $request, $num)
    {
        // API key check
        if ($request->header('X-Api-Key') !== $this->validApiKey) {
            return $this->error("Invalid API key", 401);
        }

        // Find the ad (404 if not found)
        $ad = AdsModel::find($num);
        if (!$ad) {
            return $this->error("Ad not found", 404);
        }

        // Validate only provided fields (PATCH-friendly)
        $validated = $request->validate([
            'ads_name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'period' => 'sometimes|required|integer|min:0',
            'type' => 'sometimes|required|string', // e.g., "image" | "video" | ...
            'video_url' => 'sometimes|nullable|url',
            'ads_url' => 'sometimes|nullable|url',
            'feature_img' => 'sometimes|nullable|url',
            'img1' => 'sometimes|nullable|url',
            'img2' => 'sometimes|nullable|url',
            'img3' => 'sometimes|nullable|url',
            'img4' => 'sometimes|nullable|url',
            'create_date' => 'sometimes|nullable|string', // keeping your existing format

        ]);

        // Business rule: if resulting type is "video", ensure we end up with a video_url
        $resultingType = $request->has('type') ? $validated['type'] : $ad->type;
        $resultingVideoUrl = $request->has('video_url') ? ($validated['video_url'] ?? null) : $ad->video_url;

        if ($resultingType === 'video' && empty($resultingVideoUrl)) {
            return $this->error("video_url is required when type is 'video'", 422);
        }

        // Build update payload: only mutate fields explicitly provided (including nulls)
        $fillable = [
            'ads_name',
            'description',
            'period',
            'type',
            'video_url',
            'ads_url',
            'feature_img',
            'img1',
            'img2',
            'img3',
            'img4',
            'create_date'
        ];

        $updates = [];
        foreach ($fillable as $field) {
            if ($request->has($field)) {
                // Respect explicit nulls to allow clearing a field
                $updates[$field] = $validated[$field] ?? null;
            }
        }

        // Perform update
        $ad->fill($updates);
        $ad->save();

        return $this->success("Ad updated successfully", $ad);
    }
}
