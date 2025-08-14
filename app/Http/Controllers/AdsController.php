<?php

namespace App\Http\Controllers;

use App\Models\AdsModel;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AdsController extends Controller
{
    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
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

    /**
     * Create / Add a new Ad
     */
    public function store(Request $request)
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== $this->validApiKey) {
            return response()->json(["status" => "error", "message" => "Invalid API key"], 401);
        }

        // Basic validation. Adjust "type" options if you use different ones.
        $validated = $request->validate([
            'ads_name'     => 'required|string|max:255',
            'description'  => 'nullable|string',
            'period'       => 'required|integer|min:0',           // days active
            'type'         => 'required|string',
            'video_url'    => 'nullable|url',
            'ads_url'      => 'nullable|url',
            'feature_img'  => 'nullable|url',
            'img1'         => 'nullable|url',
            'img2'         => 'nullable|url',
            'img3'         => 'nullable|url',
            'img4'         => 'nullable|url',
            'create_date'  => 'nullable|string',

            // Optional convenience: auto-fill img1..img4 from an array
            'images'       => 'nullable|array|max:4',
            'images.*'     => 'nullable|url',
        ]);

        // If type=video, enforce a video_url; otherwise optional.
        if (($validated['type'] ?? null) === 'video' && empty($validated['video_url'])) {
            return response()->json([
                "status"  => "error",
                "message" => "video_url is required when type is 'video'."
            ], 422);
        }

        // Default create_date to now() if not provided
        $createDate = $validated['create_date'] ?? Carbon::now()->format('F j, Y');

        // Map images[] -> img1..img4 if provided
        if (!empty($validated['images'])) {
            $pad = array_pad($validated['images'], 4, null);
            [$validated['img1'], $validated['img2'], $validated['img3'], $validated['img4']] = $pad;
        }

        // Create the ad
        $ad = AdsModel::create([
            'ads_name'    => $validated['ads_name'],
            'create_date' => $createDate,
            'description' => $validated['description'] ?? null,
            'period'      => $validated['period'],
            'type'        => $validated['type'],
            'video_url'   => $validated['video_url'] ?? null,
            'ads_url'     => $validated['ads_url'] ?? null,
            'feature_img' => $validated['feature_img'] ?? null,
            'img1'        => $validated['img1'] ?? null,
            'img2'        => $validated['img2'] ?? null,
            'img3'        => $validated['img3'] ?? null,
            'img4'        => $validated['img4'] ?? null,
        ]);

        return response()->json([
            "status"  => "success",
            "message" => "Ad created successfully.",
            "data"    => $ad
        ], 201);
    }
}
