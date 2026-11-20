<?php

namespace App\Services;

use App\Models\MovieModel;
use App\Models\New\Devices;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use App\Models\New\Plan;
use App\Models\New\Season;
use App\Models\New\Subscription;
use App\Models\RedemptionCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RedemptionService
{
    private const DEVICE_TYPES = ['mobile', 'tv', 'desktop', 'browser'];

    public function assertAvailable(RedemptionCode $code): void
    {
        abort_if($code->status !== 'available', 409, 'This QR is already used or cancelled.');
        abort_if($code->expires_at?->isPast(), 410, 'This QR has expired.');
    }

    public function target(RedemptionCode $code): MovieModel|Season|Episode|Plan|null
    {
        if ($code->type === 'subscription' && $code->plan_id === null) {
            return null;
        }
        $target = $code->type === 'ppv'
            ? app(RedemptionPpvCatalog::class)->target($code)
            : Plan::whereKey($code->plan_id)->where('is_active', true)->first();
        if (! $target) {
            throw ValidationException::withMessages(['target' => 'This movie or plan is no longer available.']);
        }

        return $target;
    }

    public function planForDeviceType(RedemptionCode $code, string $deviceType): Plan
    {
        if (! in_array($deviceType, self::DEVICE_TYPES, true)) {
            throw ValidationException::withMessages(['device_type' => 'This device type is not supported.']);
        }
        $plan = $code->plan_id !== null
            ? $this->target($code)
            : Plan::where('device_type', $deviceType)->where('is_active', true)
                ->where('duration_days', 30)->orderBy('price')->orderBy('id')->first();
        if (! $plan || $plan->device_type !== $deviceType) {
            $message = $code->plan_id === null
                ? 'No active 30-day subscription plan is available for this device.'
                : 'No compatible subscription plan is available for this device.';
            throw ValidationException::withMessages(['device_id' => $message]);
        }

        return $plan;
    }

    public function planForDevice(RedemptionCode $code, Devices $device, ?Subscription $subscription): Plan
    {
        $active = $subscription?->isActive() ?? false;
        $plan = $active ? $subscription->plan : $this->planForDeviceType($code, $device->device_type);
        if (! $plan || $plan->device_type !== $device->device_type) {
            throw ValidationException::withMessages(['device_id' => 'No compatible subscription plan is available for this device.']);
        }
        if ($active && $code->plan_id !== null && (int) $subscription->plan_id !== (int) $this->target($code)->id) {
            throw ValidationException::withMessages(['device_id' => 'An active subscription can only be extended with the same plan.']);
        }
        if ($active && $code->plan_id === null && (int) $subscription->plan_id !== (int) $plan->id) {
            throw ValidationException::withMessages(['device_id' => 'An active subscription can only be extended with the same plan.']);
        }

        return $plan;
    }

    public function redeem(string $token, string $userId, ?int $deviceId, ?string $deviceType = null): array
    {
        return DB::transaction(function () use ($token, $userId, $deviceId, $deviceType) {
            $code = RedemptionCode::where('token', $token)->lockForUpdate()->firstOrFail();
            $deviceType = $deviceType ? strtolower($deviceType) : null;

            if ($code->status === 'redeemed' && $code->redeemed_by === $userId) {
                if ($deviceId !== null && (int) $code->device_id === $deviceId) {
                    return [...($code->result ?? []), 'already_redeemed' => true];
                }
                if ($deviceType !== null && $code->redeemed_device_type === $deviceType) {
                    return [...($code->result ?? []), 'already_redeemed' => true];
                }
            }

            $this->assertAvailable($code);
            if ($deviceId !== null) {
                $device = Devices::whereKey($deviceId)->where('user_id', $userId)
                    ->where('status', '!=', 'blocked')->lockForUpdate()->firstOrFail();
                abort_if(! $device->device_token, 422, 'The selected device needs to sign in again.');
                abort_if($deviceType !== null && $device->device_type !== $deviceType, 422, 'The selected device category changed. Refresh and try again.');
                return $this->grantToDevice($code, $userId, $device);
            }

            if (! in_array($deviceType, self::DEVICE_TYPES, true)) {
                throw ValidationException::withMessages(['device_type' => 'Choose a supported device category.']);
            }

            // The category may have gained a login since the list was loaded.
            $candidate = $this->eligibleDeviceForCategory($code, $userId, $deviceType);
            if ($candidate) {
                return $this->grantToDevice($code, $userId, $candidate);
            }

            return $this->grantToCategory($code, $userId, $deviceType);
        }, 3);
    }

    public function activateLegacyCategoryGrants(): void
    {
        if (! Schema::hasTable('redemption_pending_devices')) {
            return;
        }

        $ids = DB::table('redemption_pending_devices')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $pending = DB::table('redemption_pending_devices')->where('id', $id)->lockForUpdate()->first();
                if (! $pending) {
                    return;
                }
                $code = RedemptionCode::whereKey($pending->redemption_code_id)->lockForUpdate()->first();
                if (! $code || $code->payment_history_id) {
                    DB::table('redemption_pending_devices')->where('id', $id)->delete();
                    return;
                }

                $this->grantToCategory($code, (string) $pending->user_id, (string) $pending->device_type);
                DB::table('redemption_pending_devices')->where('id', $id)->delete();
            }, 3);
        }
    }

    private function grantToCategory(RedemptionCode $code, string $userId, string $deviceType): array
    {
        $subscription = null;
        $action = 'ppv';
        if ($code->type === 'subscription') {
            $subscription = Subscription::activeForUserAndDeviceType($userId, $deviceType)
                ->with('plan')->orderByDesc('id')->lockForUpdate()->first();
            if ($subscription) {
                if ($code->plan_id !== null && (int) $subscription->plan_id !== (int) $code->plan_id) {
                    throw ValidationException::withMessages(['device_type' => 'An active subscription can only be extended with the same plan.']);
                }
                $plan = $subscription->plan;
            } else {
                $plan = $this->planForDeviceType($code, $deviceType);
            }

            if ($subscription) {
                $action = 'extend';
                $endAt = $subscription->end_at->copy()->addDays(30)->endOfDay();
                $subscription->update(['end_at' => $endAt]);
            } else {
                $action = 'add_new';
                $endAt = Subscription::endAtForDuration(now(), $code->plan_id === null ? 30 : $plan->duration_days);
                $subscription = Subscription::create([
                    'user_id' => $userId, 'plan_id' => $plan->id,
                    'start_at' => now(), 'end_at' => $endAt, 'is_active' => true,
                ]);
            }
        } else {
            $this->target($code);
            $endAt = now()->addDays($code->rental_days);
        }

        $payment = PaymentHistory::create([
            'user_id' => $userId, 'subscription_id' => $subscription?->id,
            'plan_id' => $subscription?->plan_id, 'movie_id' => $code->movie_id,
            'device_type' => $deviceType, 'app_payment_type' => $code->type,
            'amount' => 0, 'currency' => 'INR', 'payment_method' => 'qr',
            'payment_gateway' => 'admin_grant', 'transaction_id' => 'qr_grant_'.$code->id,
            'status' => 'success', 'payment_type' => $action === 'extend' ? 'renew' : 'new',
            'payment_date' => now(), 'expiry_date' => $endAt,
            'meta' => [
                'redemption_code_id' => $code->id, 'action' => $action,
                'category_grant' => true, 'device_type' => $deviceType,
                'content_type' => $code->type === 'ppv' ? ($code->content_type ?? 'movie') : null,
                'content_id' => $code->movie_id,
            ],
        ]);
        $result = [
            'type' => $code->type, 'action' => $action, 'device_id' => null,
            'device_type' => $deviceType, 'category_grant' => true,
            'subscription_id' => $subscription?->id, 'movie_id' => $code->movie_id,
            'plan_id' => $subscription?->plan_id,
            'content_type' => $code->type === 'ppv' ? ($code->content_type ?? 'movie') : null,
            'expires_at' => $endAt->toIso8601String(), 'payment_history_id' => $payment->id,
        ];
        $code->update([
            'status' => 'redeemed', 'redeemed_at' => now(), 'redeemed_by' => $userId,
            'device_id' => null, 'payment_history_id' => $payment->id,
            'redeemed_device_type' => $deviceType, 'result' => $result,
        ]);

        return [...$result, 'already_redeemed' => false];
    }

    private function eligibleDeviceForCategory(RedemptionCode $code, string $userId, string $deviceType): ?Devices
    {
        $devices = Devices::with('subscription.plan')->where('user_id', $userId)->where('device_type', $deviceType)
            ->where('status', '!=', 'blocked')->whereNotNull('device_token')
            ->orderByDesc('last_activity')->orderByDesc('id')->lockForUpdate()->get();
        $eligible = [];
        foreach ($devices as $device) {
            $subscription = $device->subscription && (string) $device->subscription->user_id === $userId
                ? $device->subscription : null;
            try {
                if ($code->type === 'subscription') {
                    $this->planForDevice($code, $device, $subscription);
                }
                $eligible[] = [
                    'device' => $device,
                    'extend' => $code->type === 'subscription' && ($subscription?->isActive() ?? false),
                ];
            } catch (ValidationException) {
                // Keep looking for another usable device in this category.
            }
        }
        usort($eligible, fn ($a, $b) => ($b['extend'] <=> $a['extend'])
            ?: (($b['device']->last_activity?->getTimestamp() ?? 0) <=> ($a['device']->last_activity?->getTimestamp() ?? 0))
            ?: ($b['device']->id <=> $a['device']->id));

        return $eligible[0]['device'] ?? null;
    }

    private function grantToDevice(RedemptionCode $code, string $userId, Devices $device): array
    {
        $subscription = null;
        $action = 'ppv';
        if ($code->type === 'subscription') {
            $subscription = Subscription::whereKey($device->subscription_id)->where('user_id', $userId)
                ->lockForUpdate()->first();
            $plan = $this->planForDevice($code, $device, $subscription);
            if ($subscription?->isActive()) {
                $action = 'extend';
                $endAt = $subscription->end_at->copy()->addDays(30)->endOfDay();
                $subscription->update(['end_at' => $endAt]);
            } else {
                $action = 'add_new';
                $endAt = Subscription::endAtForDuration(now(), $code->plan_id === null ? 30 : $plan->duration_days);
                $subscription = Subscription::create([
                    'user_id' => $userId, 'plan_id' => $plan->id,
                    'start_at' => now(), 'end_at' => $endAt, 'is_active' => true,
                ]);
            }
            $device->update(['subscription_id' => $subscription->id, 'status' => 'active']);
        } else {
            $endAt = now()->addDays($code->rental_days);
        }

        $payment = PaymentHistory::create([
            'user_id' => $userId, 'subscription_id' => $subscription?->id,
            'plan_id' => $subscription?->plan_id, 'movie_id' => $code->movie_id,
            'device_type' => $device->device_type, 'app_payment_type' => $code->type,
            'amount' => 0, 'currency' => 'INR', 'payment_method' => 'qr',
            'payment_gateway' => 'admin_grant', 'transaction_id' => 'qr_grant_'.$code->id,
            'status' => 'success', 'payment_type' => $action === 'extend' ? 'renew' : 'new',
            'payment_date' => now(), 'expiry_date' => $endAt,
            'meta' => [
                'redemption_code_id' => $code->id, 'action' => $action,
                'device_id' => $device->id, 'device_token' => $device->device_token,
                'device_type' => $device->device_type,
                'content_type' => $code->type === 'ppv' ? ($code->content_type ?? 'movie') : null,
                'content_id' => $code->movie_id,
            ],
        ]);
        $result = [
        'type' => $code->type, 'action' => $action, 'device_id' => $device->id,
        'device_type' => $device->device_type, 'category_grant' => false,
            'subscription_id' => $subscription?->id, 'movie_id' => $code->movie_id,
            'plan_id' => $subscription?->plan_id,
            'content_type' => $code->type === 'ppv' ? ($code->content_type ?? 'movie') : null,
            'expires_at' => $endAt->toIso8601String(), 'payment_history_id' => $payment->id,
        ];
        $code->update([
            'status' => 'redeemed', 'redeemed_at' => $code->redeemed_at ?? now(), 'redeemed_by' => $userId,
            'device_id' => $device->id, 'payment_history_id' => $payment->id,
            'redeemed_device_type' => $device->device_type, 'result' => $result,
        ]);

        return [...$result, 'already_redeemed' => false];
    }
}
