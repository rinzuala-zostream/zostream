<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\New\Devices;
use App\Models\New\Subscription;
use App\Models\RedemptionCode;
use App\Services\RedemptionPpvCatalog;
use App\Services\RedemptionService;
use App\Support\Api\V4Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $registeredDevices = Devices::with('subscription.plan')->where('user_id', $request->input('auth_user_id'))
            ->where('status', '!=', 'blocked')->whereNotNull('device_token')
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
            });

        $deviceGroups = $registeredDevices->groupBy('device_type');
        $devices = collect();
        foreach (['mobile', 'tv', 'desktop', 'browser'] as $deviceType) {
            $categoryDevices = $deviceGroups->get($deviceType, collect())->sort(function (array $left, array $right) {
                $eligible = ((int) $right['eligible']) <=> ((int) $left['eligible']);
                if ($eligible !== 0) {
                    return $eligible;
                }

                $extension = ((int) ($right['action'] === 'extend')) <=> ((int) ($left['action'] === 'extend'));
                if ($extension !== 0) {
                    return $extension;
                }

                return ($right['_last_activity'] <=> $left['_last_activity']) ?: ($right['id'] <=> $left['id']);
            });
            $device = $categoryDevices->firstWhere('eligible', true);

            if ($device === null) {
                $reason = null;
                $activeSubscription = $code->type === 'subscription'
                    ? Subscription::activeForUserAndDeviceType((string) $request->input('auth_user_id'), $deviceType)
                        ->with('plan')->orderByDesc('id')->first()
                    : null;
                if ($code->type === 'subscription') {
                    if ($activeSubscription) {
                        if (! $activeSubscription->plan || $activeSubscription->plan->device_type !== $deviceType) {
                            $reason = 'No compatible subscription plan is available for this device.';
                        } elseif ($code->plan_id !== null && (int) $activeSubscription->plan_id !== (int) $code->plan_id) {
                            $reason = 'An active subscription can only be extended with the same plan.';
                        }
                        // An existing active subscription can be extended even if its plan
                        // is no longer offered for new subscriptions.
                    } else {
                        try {
                            $this->redemptions->planForDeviceType($code, $deviceType);
                        } catch (ValidationException $exception) {
                            $reason = $exception->errors()['device_id'][0] ?? 'This device cannot activate the subscription.';
                        }
                    }
                }
                $device = $reason === null ? [
                    'id' => null, 'device_type' => $deviceType, 'eligible' => true,
                    'reason' => null,
                    'action' => $code->type === 'ppv' ? 'ppv' : ($activeSubscription ? 'extend' : 'add_new'),
                    'subscription_expires_at' => $activeSubscription?->end_at?->toIso8601String(),
                ] : ($categoryDevices->first() ?? [
                    'id' => null, 'device_type' => $deviceType, 'eligible' => false,
                    'reason' => $reason,
                    'action' => $code->type === 'ppv' ? 'ppv' : 'add_new',
                    'subscription_expires_at' => null,
                ]);
            } else {
                unset($device['_last_activity']);
            }
            $devices->push($device);
        }

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
        $data = $request->validate([
            'device_id' => ['nullable', 'integer', 'min:1', 'required_without:device_type'],
            'device_type' => ['nullable', 'string', Rule::in(['mobile', 'tv', 'desktop', 'browser']), 'required_without:device_id'],
        ]);

        return V4Response::success($this->redemptions->redeem(
            $token, (string) $request->input('auth_user_id'), isset($data['device_id']) ? (int) $data['device_id'] : null,
            isset($data['device_type']) ? (string) $data['device_type'] : null,
        ), 'Activated successfully.');
    }
}
