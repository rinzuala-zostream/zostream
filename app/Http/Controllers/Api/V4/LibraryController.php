<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\WatchPositionController;
use App\Http\Controllers\WistListController;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    public function __construct(
        private readonly WistListController $wishlist,
        private readonly WatchPositionController $history,
    ) {}

    public function wishlist(Request $request)
    {
        $request->merge(['uid' => $this->userId($request)]);

        return $this->wishlist->index($request);
    }

    public function addToWishlist(Request $request)
    {
        $request->merge(['uid' => $this->userId($request)]);

        return $this->wishlist->store($request);
    }

    public function removeFromWishlist(Request $request, string $contentId)
    {
        $request->merge([
            'uid' => $this->userId($request),
            'movie_id' => $contentId,
        ]);

        return $this->wishlist->destroy($request);
    }

    public function wishlistStatus(Request $request, string $contentId)
    {
        $request->merge([
            'user_id' => $this->userId($request),
            'movie_id' => $contentId,
        ]);

        return $this->wishlist->check($request);
    }

    public function history(Request $request)
    {
        $request->query->set('userId', $this->userId($request));

        return $this->history->getWatchContinue($request);
    }

    public function saveProgress(Request $request)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->history->save($request);
    }

    private function userId(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }
}
