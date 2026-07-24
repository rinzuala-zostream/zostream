<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NewStreamController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybackController extends Controller
{
    public function __construct(private readonly NewStreamController $streams) {}

    public function start(Request $request)
    {
        if ($error = $this->prepare($request)) {
            return $error;
        }

        return $this->streams->start($request);
    }

    public function heartbeat(Request $request)
    {
        if ($error = $this->prepare($request)) {
            return $error;
        }

        return $this->streams->ping($request);
    }

    public function stop(Request $request)
    {
        if ($error = $this->prepare($request)) {
            return $error;
        }

        return $this->streams->stop($request);
    }

    private function prepare(Request $request): ?JsonResponse
    {
        $deviceToken = trim((string) $request->header('Device-Token', ''));
        if ($deviceToken === '') {
            return response()->json([
                'status' => 'error',
                'title' => 'Missing Device Identity',
                'message' => 'Device-Token is required for playback.',
            ], 422);
        }

        $authenticatedDevice = trim((string) $request->input('auth_device_id', ''));
        if (
            $authenticatedDevice !== ''
            && ! hash_equals($authenticatedDevice, $deviceToken)
        ) {
            return response()->json([
                'status' => 'error',
                'title' => 'Device Authentication Failed',
                'message' => 'This access token belongs to another device. Please sign in again.',
            ], 403);
        }

        $request->merge(['user_id' => (string) $request->input('auth_user_id')]);

        return null;
    }
}
