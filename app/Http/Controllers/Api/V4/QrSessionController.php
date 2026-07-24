<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\New\QRSessionController as LegacyQrSessionController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QrSessionController extends Controller
{
    public function __construct(private readonly LegacyQrSessionController $sessions) {}

    public function create(Request $request)
    {
        $response = $this->sessions->create($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $payload = $response->getData(true);
            if (is_array($payload) && is_string($payload['token'] ?? null)) {
                $payload['qr_url'] = url('/api/v4/qr-sessions/'.$payload['token'].'/status');
                $response->setData($payload);
            }
        }

        return $response;
    }

    public function createAdmin(Request $request)
    {
        $request->merge([
            'type' => 'admin_login',
            'device_type' => $request->input('device_type', 'browser'),
        ]);

        return $this->create($request);
    }

    public function status(string $token)
    {
        return $this->sessions->status($token);
    }

    public function inspect(Request $request, string $token)
    {
        return $this->sessions->inspect($request, $token);
    }

    public function verify(Request $request, string $token)
    {
        $request->merge([
            'token' => $token,
            'user_id' => (string) $request->input('auth_user_id'),
        ]);

        return $this->sessions->verify($request);
    }

    public function updateSelection(Request $request, string $token)
    {
        $request->merge(['user_id' => (string) $request->input('auth_user_id')]);

        return $this->sessions->updateSelection($request, $token);
    }

    public function startSubscriptionPayment(Request $request, string $token)
    {
        $request->merge(['token' => $token]);

        return $this->sessions->startSubscriptionPayment($request);
    }

    public function completeSubscriptionPayment(Request $request, string $token)
    {
        $request->merge(['token' => $token]);

        return $this->sessions->completeSubscriptionPayment($request);
    }
}
