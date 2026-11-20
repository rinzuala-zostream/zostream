<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\New\Devices;
use App\Models\RedemptionCode;
use App\Services\RedemptionService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedemptionController extends Controller
{
    public function __construct(private readonly RedemptionService $redemptions) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $code = RedemptionCode::where('token', $token)->firstOrFail();
        $this->redemptions->assertAvailable($code);
        $target = $this->redemptions->target($code);
        $devices = Devices::with('subscription')->where('user_id', $request->input('auth_user_id'))
            ->where('status', '!=', 'blocked')->whereNotNull('device_token')
            ->when($code->type === 'subscription', fn ($query) => $query->where('device_type', $target->device_type))
            ->get()->map(function (Devices $device) use ($code) {
                $active = $device->subscription?->isActive() ?? false;
                $eligible = $code->type !== 'subscription' || ! $active || (int) $device->subscription->plan_id === (int) $code->plan_id;

                return [
                    'id' => $device->id, 'name' => $device->device_name, 'device_type' => $device->device_type,
                    'eligible' => $eligible,
                    'reason' => $eligible ? null : 'An active subscription can only be extended with the same plan.',
                    'action' => $code->type === 'ppv' ? 'ppv' : ($active ? 'extend' : 'add_new'),
                    'subscription_expires_at' => $device->subscription?->end_at?->toIso8601String(),
                ];
            });

        return V4Response::success([
            'type' => $code->type, 'label' => $code->label,
            'target' => ['id' => $code->movie_id ?? $code->plan_id, 'name' => $target->title ?? $target->name],
            'rental_days' => $code->type === 'ppv' ? $code->rental_days : null,
            'new_subscription_days' => $code->type === 'subscription' ? $target->duration_days : null,
            'extension_days' => $code->type === 'subscription' ? 30 : null,
            'expires_at' => $code->expires_at?->toIso8601String(), 'devices' => $devices,
        ]);
    }

    public function redeem(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'integer', 'min:1']]);

        return V4Response::success($this->redemptions->redeem(
            $token, (string) $request->input('auth_user_id'), (int) $data['device_id']
        ), 'Activated successfully.');
    }
}
