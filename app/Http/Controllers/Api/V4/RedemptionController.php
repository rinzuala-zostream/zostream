<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\New\Devices;
use App\Models\RedemptionCode;
use App\Services\RedemptionPpvCatalog;
use App\Services\RedemptionService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RedemptionController extends Controller
{
    public function __construct(private readonly RedemptionService $redemptions) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $code = RedemptionCode::where('token', $token)->firstOrFail();
        $this->redemptions->assertAvailable($code);
        $target = $this->redemptions->target($code);
        $ppv = $code->type === 'ppv' ? app(RedemptionPpvCatalog::class)->presentation($code, $target) : [];
        $devices = Devices::with('subscription.plan')->where('user_id', $request->input('auth_user_id'))
            ->where('status', '!=', 'blocked')->whereNotNull('device_token')
            ->when($code->type === 'subscription' && $target !== null, fn ($query) => $query->where('device_type', $target->device_type))
            ->orderByDesc('last_activity')->orderByDesc('id')
            ->get()->map(function (Devices $device) use ($code) {
                $subscription = $device->subscription && (string) $device->subscription->user_id === (string) $device->user_id
                    ? $device->subscription : null;
                $active = $subscription?->isActive() ?? false;
                $reason = null;
                if ($code->type === 'subscription') {
                    try {
                        $this->redemptions->planForDevice($code, $device, $subscription);
                    } catch (ValidationException $exception) {
                        $reason = $exception->errors()['device_id'][0] ?? 'This device cannot activate the subscription.';
                    }
                }

                return [
                    'id' => $device->id, 'device_type' => $device->device_type,
                    'eligible' => $reason === null,
                    'reason' => $reason,
                    'action' => $code->type === 'ppv' ? 'ppv' : ($active ? 'extend' : 'add_new'),
                    'subscription_expires_at' => $subscription?->end_at?->toIso8601String(),
                    '_last_activity' => $device->last_activity?->getTimestamp() ?? 0,
                ];
            })
            ->groupBy('device_type')
            ->map(function ($devicesOfType) {
                return $devicesOfType->sort(function (array $left, array $right) {
                    $eligible = ((int) $right['eligible']) <=> ((int) $left['eligible']);
                    if ($eligible !== 0) {
                        return $eligible;
                    }

                    // If several devices share a category, preserve an eligible
                    // subscription on one of them so the QR extends that access.
                    $extension = ((int) ($right['action'] === 'extend')) <=> ((int) ($left['action'] === 'extend'));
                    if ($extension !== 0) {
                        return $extension;
                    }

                    return ($right['_last_activity'] <=> $left['_last_activity']) ?: ($right['id'] <=> $left['id']);
                })->first();
            })
            ->map(function (array $device) {
                unset($device['_last_activity']);
                return $device;
            })->values();

        return V4Response::success([
            'type' => $code->type, 'label' => $code->label,
            'target' => ['id' => $code->movie_id ?? $code->plan_id, 'name' => $ppv['display_name'] ?? $target?->name ?? '30-day subscription'],
            ...$ppv,
            'rental_days' => $code->type === 'ppv' ? $code->rental_days : null,
            'new_subscription_days' => $code->type === 'subscription' ? ($target?->duration_days ?? 30) : null,
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
