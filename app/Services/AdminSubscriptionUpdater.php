<?php

namespace App\Services;

use App\Models\New\ActiveStream;
use App\Models\New\Devices;
use App\Models\New\Plan;
use App\Models\New\StreamEvent;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminSubscriptionUpdater
{
    public function update(int $id, array $attributes, ?string $adminUserId = null): Subscription
    {
        return DB::transaction(function () use ($id, $attributes, $adminUserId) {
            $userId = Subscription::findOrFail($id)->user_id;
            // Serialize admin switches for the account, including different source subscriptions.
            UserModel::where('uid', $userId)->lockForUpdate()->first();
            $subscription = Subscription::lockForUpdate()->findOrFail($id);
            $oldPlan = $subscription->plan;
            $plan = Plan::findOrFail($attributes['plan_id'] ?? $subscription->plan_id);
            $switching = (int) $plan->id !== (int) $subscription->plan_id;
            $type = strtolower(trim((string) $plan->device_type));

            if ($switching && (! $plan->is_active || ! in_array($type, ['mobile', 'tv', 'browser', 'desktop'], true))) {
                throw ValidationException::withMessages(['plan_id' => 'Choose an active plan for a supported device type.']);
            }
            $start = isset($attributes['start_at']) ? Carbon::parse($attributes['start_at'])->startOfDay() : $subscription->start_at;
            $end = isset($attributes['end_at']) ? Carbon::parse($attributes['end_at'])->endOfDay() : $subscription->end_at;
            if ($start && $end && $end->lt($start)) {
                throw ValidationException::withMessages(['end_at' => 'End date cannot be earlier than start date.']);
            }
            if ($switching && ($attributes['is_active'] ?? $subscription->is_active) && $end && ! Subscription::endAtIsExpired($end)) {
                $duplicate = Subscription::activeForUserAndDeviceType($userId, $type)
                    ->where('id', '!=', $id)->lockForUpdate()->first();
                if ($duplicate) {
                    throw ValidationException::withMessages(['plan_id' => 'This user already has an active '.$type.' subscription. Edit that subscription instead.']);
                }
            }

            $previous = ['plan_id' => $subscription->plan_id, 'device_type' => $oldPlan?->device_type,
                'start_at' => $subscription->getRawOriginal('start_at'), 'end_at' => $subscription->getRawOriginal('end_at')];
            if (isset($attributes['start_at'])) $attributes['start_at'] = $start;
            if (isset($attributes['end_at'])) $attributes['end_at'] = $end;
            $subscription->fill($attributes);
            $revoke = $switching || ! $subscription->is_active || Subscription::endAtIsExpired($subscription->end_at);
            $subscription->save();

            if ($revoke) {
                // Existing tokens fail their next heartbeat; retain records for playback history.
                ActiveStream::where('subscription_id', $id)->where('status', 'active')
                    ->update(['status' => 'stopped', 'last_ping' => now()]);
            }
            if ($switching) {
                $crossDevice = strtolower(trim((string) $oldPlan?->device_type)) !== $type;
                if ($crossDevice) {
                    // Preserve device identity, ownership and block state, but remove the old entitlement.
                    Devices::where('subscription_id', $id)->update(['subscription_id' => null]);
                }
                $devices = Devices::where('user_id', $userId)->where('device_type', $type)
                    ->lockForUpdate()->get();
                foreach ($devices as $device) {
                    // Do not take a device away from another currently active subscription.
                    if ($device->subscription_id && (int) $device->subscription_id !== $id
                        && $device->subscription?->isActive()) continue;
                    $device->update([
                        'subscription_id' => $id,
                        'status' => $device->status === 'blocked' ? 'blocked'
                            : ($device->is_owner_device ? 'active' : 'inactive'),
                    ]);
                }
                StreamEvent::create([
                    'subscription_id' => $id, 'event_type' => 'renew',
                    'event_data' => ['action' => 'admin_plan_switch', 'admin_user_id' => $adminUserId, 'previous' => $previous,
                        'plan_id' => $plan->id, 'device_type' => $type,
                        'start_at' => $subscription->getRawOriginal('start_at'),
                        'end_at' => $subscription->getRawOriginal('end_at')],
                ]);
            }

            return $subscription->fresh('plan');
        });
    }
}
