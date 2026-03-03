<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MovieModel;
use App\Models\EpisodeModel;
use Illuminate\Support\Facades\Log;
use Exception;

class MovieController extends Controller
{
    /**
     * 📋 List all movies with pagination
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $movies = MovieModel::orderBy('create_date', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $movies
            ]);
        } catch (Exception $e) {
            Log::error('Movie index error', ['error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch movies', $e);
        }
    }

    /**
     * 🎞️ Get movie by ID (without URLs)
     */
    public function getById(Request $request, $id)
    {
        try {
            $type = strtolower($request->query('type', 'movie'));
            if (!in_array($type, ['movie', 'episode'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid type. Allowed: movie, episode'
                ], 422);
            }

            if ($type === 'episode') {
                $movie = EpisodeModel::select([
                    'num',
                    'id',
                    'movie_id',
                    'title',
                    'desc',
                    'img',
                    'views',
                    'status',
                    'isPremium',
                    'isPPV',
                    'isProtected',
                    'isEnable',
                    'create_date'
                ])->where('id', $id)->first();
            } else {
                $movie = MovieModel::select([
                    'num',
                    'title',
                    'description',
                    'director',
                    'duration',
                    'genre',
                    'poster',
                    'cover_img',
                    'title_img',
                    'release_on',
                    'views',
                    'status',
                    'isPremium',
                    'isPayPerView',
                    'isChildMode',
                    'isAgeRestricted',
                    'isCompleted',
                    'isSeason',
                    'create_date',
                    'trailer'
                ])->where('id', $id)->first();
            }

            if (!$movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => ucfirst($type) . ' not found'
                ], 404);
            }

            return response()->json(
                $movie
            );
        } catch (Exception $e) {
            Log::error('Movie getById error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch movie details', $e);
        }
    }

    /**
     * 🔗 Get only movie links (URLs)
     */
    public function getLink(Request $request, $id)
    {
        try {
            $type = strtolower($request->query('type', 'movie'));
            if (!in_array($type, ['movie', 'episode'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid type. Allowed: movie, episode'
                ], 422);
            }

            if ($type === 'episode') {
                $movie = EpisodeModel::select(['num', 'title', 'url', 'dash_url', 'hls_url'])
                    ->where('id', $id)
                    ->first();
            } else {
                $movie = MovieModel::select(['num', 'title', 'url', 'dash_url', 'hls_url', 'trailer'])
                    ->where('id', $id)
                    ->first();
            }

            if (!$movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => ucfirst($type) . ' not found'
                ], 404);
            }

            // Security: hide null or empty links
            $links = array_filter([
                'url' => $movie->url,
                'dash_url' => $movie->dash_url,
                'hls_url' => $movie->hls_url,
                'trailer' => $movie->trailer ?? null,
            ], fn($value) => $value !== null && $value !== '');

            return response()->json([
                'status' => 'success',
                'type' => $type,
                'movie_id' => $movie->num,
                'title' => $movie->title,
                'links' => $links
            ]);
        } catch (Exception $e) {
            Log::error('Movie getLink error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch movie links', $e);
        }
    }

    /**
     * 🧩 Common JSON error handler
     */
    private function errorResponse(string $message, Exception $e, int $code = 500)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error' => $e->getMessage(),
        ], $code);
    }
}
