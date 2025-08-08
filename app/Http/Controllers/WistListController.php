<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WistListModel;

class WistListController extends Controller
{

    private $validApiKey;

    public function __construct()
    {
        $this->validApiKey = config('app.api_key');
    }

public function getWistList(Request $request)
{
    $apiKey = $request->header('X-Api-Key');

    if ($apiKey !== $this->validApiKey) {
        return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
    }

    $request->validate([
        'uid' => 'required|string',
    ]);

    try {
        $uid = $request->query('uid');

        $wistItems = WistListModel::where('uid', $uid)
            ->orderByDesc('create_date')
            ->get();

        return response()->json([
            'status' => 'success',
            'count' => $wistItems->count(),
            'wist_list' => $wistItems,
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 500);
    }
}
    public function addToWistList(Request $request)
{
    $apiKey = $request->header('X-Api-Key');

    if ($apiKey !== $this->validApiKey) {
        return response()->json(['status' => 'error', 'message' => 'Invalid API key']);
    }

    $request->validate([
        'uid' => 'required|string',
        'movie_id' => 'required|string',
        'poster' => 'nullable|string',
        'title' => 'required|string',
    ]);

    try {
        $data = [
            'uid' => $request->input('uid'),
            'movie_id' => $request->input('movie_id'),
            'poster' => $request->input('poster', ''),
            'title' => $request->input('title'),
            'create_date' => now(),
        ];

        // Optional: avoid duplicates
        $exists = WistListModel::where('uid', $data['uid'])
            ->where('movie_id', $data['movie_id'])
            ->exists();

        if ($exists) {
            return response()->json(['status' => 'info', 'message' => 'Already in wist list']);
        }

        WistListModel::create($data);

        return response()->json(['status' => 'success', 'message' => 'Added to wist list']);

    } catch (\Exception $e) {
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}
}
