<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use App\Models\WistListModel;
use Illuminate\Http\Request;

class WistListController extends Controller
{

    public function store(Request $request)
    {

        $validated = $request->validate([
            'uid' => 'required|string|max:128',
            'movie_id' => 'required|string|max:64',
        ]);

        $uid = $validated['uid'];
        $movieId = $validated['movie_id'];

        $movie = MovieModel::where('id', $movieId)->first();
        if (!$movie) {
            return response()->json([
                'status' => 'error',
                'message' => 'Movie not found',
            ], 404);
        }

        $alreadyExists = WistListModel::where('uid', $uid)
            ->where('movie_id', $movieId)
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'status' => 'info',
                'message' => 'Movie already in wishlist',
            ]);
        }

        WistListModel::create([
            'uid' => $uid,
            'movie_id' => $movieId,
            'title' => $movie->title,
            'cover' => $movie->cover_img,
            'poster' => $movie->poster,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Wishlist updated',
        ], 201);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'uid' => 'required|string|max:128',
        ]);

        $list = WistListModel::where('uid', $validated['uid'])
            ->orderByDesc('created_at')
            ->get();

        $result = [];

        foreach ($list as $item) {
            $movie = MovieModel::where('id', $item->movie_id)->first();

            if ($movie) {
                $result[] = $movie; // actual movie row only
            }
        }

        return response()->json([
            'status' => 'success',
            'wist_list' => $result,
        ]);
    }

    public function check(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required_without:uid|string|max:128',
            'movie_id' => 'required_without:movieId|string|max:64',
        ]);

        $uid = $validated['user_id'];
        $movieId = $validated['movie_id'];

        $isWishlisted = WistListModel::where('uid', $uid)
            ->where('movie_id', $movieId)
            ->exists();

        return response()->json([
            'status' => 'success',
            'is_wishlisted' => $isWishlisted,
        ]);
    }

    public function destroy(Request $request)
    {

        $validated = $request->validate([
            'uid' => 'required_without:userId|string|max:128',
            'userId' => 'required_without:uid|string|max:128',
            'movie_id' => 'required_without:movieId|string|max:64',
            'movieId' => 'required_without:movie_id|string|max:64',
        ]);

        $uid = $validated['uid'] ?? $validated['userId'];
        $movieId = $validated['movie_id'] ?? $validated['movieId'];

        $deleted = WistListModel::where('uid', $uid)
            ->where('movie_id', $movieId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wishlist item not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Removed from wishlist',
        ]);
    }
}
