<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\New\OfflineController as LegacyOfflineController;
use Illuminate\Http\Request;

class OfflineController extends Controller
{
    public function __construct(private readonly LegacyOfflineController $offline) {}

    public function requestAccess(Request $request)
    {
        $userId = (string) $request->input('auth_user_id');
        $request->merge(['user_id' => $userId]);
        $request->query->set('user_id', $userId);

        return $this->offline->requestOffline($request);
    }
}
