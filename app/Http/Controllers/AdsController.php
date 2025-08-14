<?php

namespace App\Http\Controllers;

use App\Models\AdsModel;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Exception;

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
            return $now->between($createDate, $endDate); });
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
                'type' => 'required|string',
                'video_url' => 'nullable|url',
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

            if (!empty($validated['images'])) {
                $pad = array_pad($validated['images'], 4, null);
                [$validated['img1'], $validated['img2'], $validated['img3'], $validated['img4']] = $pad;
            }

            $ad = AdsModel::create([
                'ads_name' => $validated['ads_name'],
                'create_date' => $createDate,
                'description' => $validated['description'] ?? null,
                'period' => $validated['period'],
                'type' => $validated['type'],
                'video_url' => $validated['video_url'] ?? null,
                'ads_url' => $validated['ads_url'] ?? null,
                'feature_img' => $validated['feature_img'] ?? null,
                'img1' => $validated['img1'] ?? null,
                'img2' => $validated['img2'] ?? null,
                'img3' => $validated['img3'] ?? null,
                'img4' => $validated['img4'] ?? null,
            ]);

            return $this->success("Ad created successfully", $ad, 201);
        } catch (Exception $e) {
            return $this->error("Failed to create ad", 500, $e->getMessage());
        }
    }
}
