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
        return $this->movies->publicCatalogIndex($request);
    }

    public function home(Request $request)
    {
        return $this->movies->getMovies($request);
    }

    public function search(Request $request)
    {
        // The public app endpoint must keep one response shape even when the
        // signed-in customer also has admin access. Admin catalogue searches
        // use the dedicated /api/v4/admin/catalog/items/search route.
        $request->query->set('is_enable', 'true');
        $response = app(\App\Http\Controllers\New\SearchController::class)->searchPublic($request);
        $items = $response->getData(true);

        if (is_array($items) && array_is_list($items)) {
            foreach ($items as &$item) {
                if (is_array($item) && $this->requiresEntitlement($item)) {
                    $this->removePlaybackLinks($item);
                }
            }
            unset($item);
            $response->setData($items);
        }

        return $response;
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
        $response = $this->movies->getById($request, $contentId);
        $payload = $response->getData(true);

        if ($response->isSuccessful() && is_array($payload)) {
            $type = strtolower((string) $request->query('type', 'movie'));
            $isPublished = ($payload['status'] ?? null) === 'Published';
            $isEnabled = $type === 'episode'
                ? filter_var($payload['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
                : filter_var($payload['isEnable'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $isPublished || ! $isEnabled) {
                return V4Response::error('CONTENT_NOT_FOUND', 'Content not found.', 404);
            }
        }

        if (is_array($payload) && $this->requiresEntitlement($payload)) {
            $this->removePlaybackLinks($payload);
            $response->setData($payload);
        }

        return $response;
    }

    public function details(Request $request, string $contentId)
    {
        $request->query->set('movie_id', $contentId);
        $request->query->set('user_id', $this->catalogUserId($request));

        $response = $this->details->getDetails($request);
        $payload = $response->getData(true);
        $movie = is_array($payload) ? ($payload['movie'] ?? null) : null;

        if (is_array($movie)) {
            $type = strtolower((string) $request->query('type', 'movie'));
            $isPublished = ($movie['status'] ?? null) === 'Published';
            $isEnabled = $type === 'episode'
                ? filter_var($movie['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
                : filter_var($movie['isEnable'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $isPublished || ! $isEnabled) {
                return V4Response::error('CONTENT_NOT_FOUND', 'Content not found.', 404);
            }
        }

        if (is_array($movie) && $this->requiresEntitlement($movie)) {
            $this->removePlaybackLinks($payload['movie']);
            $response->setData($payload);
        }

        return $response;
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
        $request->query->set('user_id', $this->recommendationUserId($request));

        return $this->recommendations->alsoLike($request);
    }

    public function ppv(Request $request)
    {
        // This catalog quote remains available to guests, but a plan discount
        // is only valid when the supplied subscription belongs to this token.
        $request->attributes->set(
            'v4_authenticated_user_id',
            $this->authenticatedUserIdFromToken($request)
        );

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
        return $this->movies->publicLatestUpdates($request);
    }

    public function season(string $id)
    {
        return $this->sanitizeNestedCatalogResponse(
            app(\App\Http\Controllers\New\SeasonController::class)->show($id)
        );
    }

    public function episode(string $id)
    {
        return $this->sanitizeNestedCatalogResponse(
            app(\App\Http\Controllers\New\EpisodeController::class)->show($id)
        );
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

        $headerUserId = trim((string) $request->header('X-User-Id', ''));

        if ($headerUserId !== '') {
            return $headerUserId;
        }

        $queryUserId = trim((string) $request->query('user_id', ''));

        return $queryUserId !== '' ? $queryUserId : 'guest';
    }

    private function authenticatedUserIdFromToken(Request $request): ?string
    {
        $authHeader = (string) $request->header('Authorization', '');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authHeader, 7));
        $record = $token !== '' ? SessionTokenModel::findByAccessToken($token) : null;

        if (! $record || Carbon::parse($record->access_expires_at)->isPast()) {
            return null;
        }

        return (string) $record->user_id;
    }

    private function requiresEntitlement(array $content): bool
    {
        foreach (['isPremium', 'isPayPerView', 'isPPV'] as $key) {
            if (filter_var($content[$key] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    private function removePlaybackLinks(array &$content): void
    {
        unset($content['url'], $content['dash_url'], $content['hls_url'], $content['token']);
    }

    private function sanitizeNestedCatalogResponse($response)
    {
        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return $response;
        }

        $this->redactPaidContentNodes($payload);
        $response->setData($payload);

        return $response;
    }

    private function redactPaidContentNodes(array &$node): void
    {
        if ($this->requiresEntitlement($node)) {
            $this->removePlaybackLinks($node);
        }

        foreach ($node as &$value) {
            if (is_array($value)) {
                $this->redactPaidContentNodes($value);
            }
        }
        unset($value);
    }

    private function recommendationUserId(Request $request): string
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

        $headerUserId = trim((string) $request->header('X-User-Id', ''));

        if ($headerUserId !== '') {
            return $headerUserId;
        }

        return trim((string) $request->query('user_id', ''));
    }
}
