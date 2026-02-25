<?php

namespace App\Http\Controllers\New;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MovieModel;
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
    public function getById($id)
    {
        try {
            $movie = MovieModel::select([
                'num', 'title', 'description', 'director', 'duration',
                'genre', 'poster', 'cover_img', 'title_img', 'release_on',
                'views', 'status', 'isPremium', 'isPayPerView', 'isChildMode',
                'isAgeRestricted', 'isCompleted', 'isSeason', 'create_date', 'trailer'
            ])->find($id);

            if (!$movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Movie not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $movie
            ]);
        } catch (Exception $e) {
            Log::error('Movie getById error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->errorResponse('Failed to fetch movie details', $e);
        }
    }

    /**
     * 🔗 Get only movie links (URLs)
     */
    public function getLink($id)
    {
        try {
            $movie = MovieModel::select(['num', 'title', 'url', 'dash_url', 'hls_url'])
                ->find($id);

            if (!$movie) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Movie not found'
                ], 404);
            }

            // Security: hide null or empty links
            $links = array_filter([
                'url' => $movie->url,
                'dash_url' => $movie->dash_url,
                'hls_url' => $movie->hls_url,
                'trailer' => $movie->trailer,
            ]);

            return response()->json([
                'status' => 'success',
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