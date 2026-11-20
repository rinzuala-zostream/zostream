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
use Illuminate\Validation\ValidationException;

class RedemptionService
{
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

    public function planForDevice(RedemptionCode $code, Devices $device, ?Subscription $subscription): Plan
    {
        $active = $subscription?->isActive() ?? false;
        if ($code->plan_id !== null) {
            $plan = $this->target($code);
        } elseif ($active) {
            $plan = $subscription->plan;
        } else {
            $plan = Plan::where('device_type', $device->device_type)->where('is_active', true)
                ->where('duration_days', 30)
                ->orderBy('price')->orderBy('id')->first();
        }
        if (! $plan || $plan->device_type !== $device->device_type) {
            $message = $code->plan_id === null && ! $active
                ? 'No active 30-day subscription plan is available for this device.'
                : 'No compatible subscription plan is available for this device.';
            throw ValidationException::withMessages(['device_id' => $message]);
        }
        if ($active && (int) $subscription->plan_id !== (int) $plan->id) {
            throw ValidationException::withMessages(['device_id' => 'An active subscription can only be extended with the same plan.']);
        }

        return $plan;
    }

    public function redeem(string $token, string $userId, int $deviceId): array
    {
        return DB::transaction(function () use ($token, $userId, $deviceId) {
            $code = RedemptionCode::where('token', $token)->lockForUpdate()->firstOrFail();
            // A lost response may be retried only by the same account and device.
            if ($code->status === 'redeemed' && $code->redeemed_by === $userId && (int) $code->device_id === $deviceId) {
                return [...$code->result, 'already_redeemed' => true];
            }
            $this->assertAvailable($code);
            $device = Devices::whereKey($deviceId)->where('user_id', $userId)
                ->where('status', '!=', 'blocked')->lockForUpdate()->firstOrFail();
            abort_if(! $device->device_token, 422, 'The selected device needs to sign in again.');
            $target = $this->target($code);
            $subscription = null;
            $action = 'ppv';

            if ($code->type === 'subscription') {
                $subscription = Subscription::whereKey($device->subscription_id)->where('user_id', $userId)
                    ->lockForUpdate()->first();
                $target = $this->planForDevice($code, $device, $subscription);
                if ($subscription?->isActive()) {
                    $action = 'extend';
                    $endAt = $subscription->end_at->copy()->addDays(30)->endOfDay();
                    $subscription->update(['end_at' => $endAt]);
                } else {
                    $action = 'add_new';
                    $endAt = Subscription::endAtForDuration(now(), $code->plan_id === null ? 30 : $target->duration_days);
                    $subscription = Subscription::create([
                        'user_id' => $userId, 'plan_id' => $target->id,
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
                'subscription_id' => $subscription?->id, 'movie_id' => $code->movie_id,
                'plan_id' => $subscription?->plan_id,
                'content_type' => $code->type === 'ppv' ? ($code->content_type ?? 'movie') : null,
                'expires_at' => $endAt->toIso8601String(), 'payment_history_id' => $payment->id,
            ];
            $code->update([
                'status' => 'redeemed', 'redeemed_at' => now(), 'redeemed_by' => $userId,
                'device_id' => $device->id, 'payment_history_id' => $payment->id, 'result' => $result,
            ]);

            return [...$result, 'already_redeemed' => false];
        }, 3);
    }
}
