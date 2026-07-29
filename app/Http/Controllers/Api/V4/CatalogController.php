<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\New\AlsoLikeController;
use App\Http\Controllers\New\DetailsController;
use App\Http\Controllers\New\MovieController;
use App\Models\MovieModel;
use App\Models\SessionTokenModel;
use App\Support\Api\V4Response;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(
        private readonly MovieController $movies,
        private readonly DetailsController $details,
        private readonly AlsoLikeController $recommendations,
    ) {}

    public function index(Request $request)
    {
        return $this->movies->index($request);
    }

    public function home(Request $request)
    {
        return $this->movies->getMovies($request);
    }

    public function search(Request $request)
    {
        return app(\App\Http\Controllers\New\SearchController::class)->search($request);
    }

    public function filter(Request $request)
    {
        return $this->movies->filter($request);
    }

    public function genres(Request $request)
    {
        return $this->movies->genre($request);
    }

    public function show(Request $request, string $contentId)
    {
        return $this->movies->getById($request, $contentId);
    }

    public function details(Request $request, string $contentId)
    {
        $request->query->set('movie_id', $contentId);
        $request->query->set('user_id', $this->catalogUserId($request));

        return $this->details->getDetails($request);
    }

    public function recommendations(Request $request, string $contentId)
    {
        $movie = MovieModel::query()
            ->where('id', $contentId)
            ->when(
                ctype_digit($contentId),
                fn ($query) => $query->orWhere('num', (int) $contentId)
            )
            ->first();

        if (! $movie) {
            return V4Response::error('CONTENT_NOT_FOUND', 'Content not found.', 404);
        }

        $request->query->set('movie_title', (string) $movie->title);
        $request->query->set('user_id', $this->catalogUserId($request));

        return $this->recommendations->alsoLike($request);
    }

    public function ppv(Request $request)
    {
        return $this->movies->getPayPerViewContent($request);
    }

    public function ppvStatus(Request $request, string $contentId)
    {
        $request->query->set('content_id', $contentId);
        $request->query->set('user_id', $this->catalogUserId($request));

        return $this->movies->checkPayPerViewRental($request);
    }

    public function latest(Request $request)
    {
        return $this->movies->latestUpdates($request);
    }

    private function catalogUserId(Request $request): string
    {
        $authUserId = trim((string) $request->input('auth_user_id', ''));

        if ($authUserId !== '') {
            return $authUserId;
        }

        $authHeader = (string) $request->header('Authorization', '');

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            $record = $token !== '' ? SessionTokenModel::findByAccessToken($token) : null;

            if ($record && ! Carbon::parse($record->access_expires_at)->isPast()) {
                return (string) $record->user_id;
            }
        }

        $queryUserId = trim((string) $request->query('user_id', ''));

        return $queryUserId !== '' ? $queryUserId : 'guest';
    }
}
