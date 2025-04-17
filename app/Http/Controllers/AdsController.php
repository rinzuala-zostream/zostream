<?php

namespace App\Http\Controllers;

use App\Models\AdsModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Optional filtering by video_url
        $query = AdsModel::query();

        if ($request->has('video_url')) {
            $query->where('video_url', $request->query('video_url'));
        }

        $ads = $query->get();

        // Filter by active period
        $filtered = $ads->filter(function ($ad) {
            $createDate = Carbon::parse($ad->create_date);
            $endDate = $createDate->copy()->addDays($ad->period);
            $now = Carbon::now();

            return $now->between($createDate, $endDate);
        });

        return response()->json(array_values($filtered->toArray()));
    }
}
