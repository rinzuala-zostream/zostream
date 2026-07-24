<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Http\Controllers\New\DeviceController;
use App\Http\Controllers\New\SubscriptionController;
use App\Http\Controllers\New\UserController;
use App\Http\Controllers\New\WhatsAppPhoneController;
use App\Http\Controllers\OTPController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly UserController $users,
        private readonly DeviceController $devices,
        private readonly SubscriptionController $subscriptions,
        private readonly OTPController $otp,
        private readonly WhatsAppPhoneController $phones,
    ) {}

    public function show(Request $request)
    {
        return $this->users->show($this->userId($request));
    }

    public function update(Request $request)
    {
        return $this->users->update($request, $this->userId($request));
    }

    public function destroy(Request $request)
    {
        return $this->otp->deleteAccount($request);
    }

    public function devices(Request $request)
    {
        return $this->devices->getByUser($request, $this->userId($request));
    }

    public function storeDevice(Request $request)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->devices->store($request);
    }

    public function clearDevices(Request $request)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->devices->clear($request);
    }

    public function subscriptions(Request $request)
    {
        $response = $this->subscriptions->getByUser($request, $this->userId($request));

        if (
            $response instanceof JsonResponse
            && $response->getStatusCode() === 404
            && empty($request->query('device_type'))
        ) {
            $perPage = max(1, (int) $request->query('per_page', 15));

            return response()->json([
                'status' => true,
                'message' => 'No active subscriptions.',
                'current_date' => now()->toIso8601String(),
                'data' => [
                    'current_page' => 1,
                    'data' => [],
                    'first_page_url' => null,
                    'from' => null,
                    'last_page' => 1,
                    'last_page_url' => null,
                    'links' => [],
                    'next_page_url' => null,
                    'path' => $request->url(),
                    'per_page' => $perPage,
                    'prev_page_url' => null,
                    'to' => null,
                    'total' => 0,
                ],
            ]);
        }

        return $response;
    }

    public function phoneStatus(Request $request)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->phones->checkPhone($request);
    }

    public function updatePhone(Request $request)
    {
        $request->merge(['user_id' => $this->userId($request)]);

        return $this->phones->updatePhone($request);
    }

    private function userId(Request $request): string
    {
        return (string) $request->input('auth_user_id');
    }
}
