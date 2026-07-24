<?php

namespace App\Http\Controllers\Concerns;

use App\Models\New\Devices;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Support\Facades\DB;

trait ResolvesLoginDevices
{
    private function resolveLoginDevice(
        UserModel $user,
        ?Subscription $subscription,
        ?string $deviceId,
        ?string $deviceName,
        ?string $deviceType
    ): array {
        $deviceType = $this->normalizeLoginDeviceType($deviceType);
        $deviceName = $deviceName ?: 'Unknown Device';

        if (!$deviceId) {
            return [
                'device' => null,
                'is_owner_device' => false,
                'message' => 'Login successful without device token',
            ];
        }

        return DB::transaction(function () use ($user, $subscription, $deviceId, $deviceName, $deviceType) {
            $ownerDevice = Devices::where('user_id', $user->uid)
                ->where('device_type', $deviceType)
                ->where('is_owner_device', true)
                ->lockForUpdate()
                ->first();

            $device = Devices::where('device_token', $deviceId)
                ->lockForUpdate()
                ->first();

            if (!$device) {
                $isOwnerDevice = $ownerDevice === null;

                $device = Devices::create([
                    'user_id' => $user->uid,
                    'subscription_id' => $subscription?->id,
                    'device_token' => $deviceId,
                    'device_name' => $deviceName,
                    'device_type' => $deviceType,
                    'status' => $isOwnerDevice ? 'active' : 'inactive',
                    'is_owner_device' => $isOwnerDevice,
                ]);

                return [
                    'device' => $device,
                    'is_owner_device' => $isOwnerDevice,
                    'message' => $isOwnerDevice
                        ? ucfirst($deviceType) . ' owner device created'
                        : 'Device created and set as inactive' . ($subscription ? '' : ' without subscription'),
                ];
            }

            $updates = [];
            $isOwnerDevice = $ownerDevice === null || (int) $ownerDevice->id === (int) $device->id;
            $sameUser = (string) $device->user_id === (string) $user->uid;
            $targetStatus = $isOwnerDevice
                ? 'active'
                : ($sameUser ? ($device->status ?: 'inactive') : 'inactive');

            if ($device->user_id !== $user->uid) {
                $updates['user_id'] = $user->uid;
            }

            if ($subscription && (int) $device->subscription_id !== (int) $subscription->id) {
                $updates['subscription_id'] = $subscription->id;
            }

            if ($deviceName !== 'Unknown Device' && $device->device_name !== $deviceName) {
                $updates['device_name'] = $deviceName;
            }

            if ($device->device_type !== $deviceType) {
                $updates['device_type'] = $deviceType;
            }

            if ((bool) $device->is_owner_device !== $isOwnerDevice) {
                $updates['is_owner_device'] = $isOwnerDevice;
            }

            if ($device->status !== $targetStatus) {
                $updates['status'] = $targetStatus;
            }

            if (!empty($updates)) {
                $device->update($updates);
                $device->refresh();
            }

            $message = $isOwnerDevice
                ? ucfirst($deviceType) . ' owner device active'
                : 'Device stored and set as inactive' . ($subscription ? '' : ' without subscription');

            return [
                'device' => $device,
                'is_owner_device' => (bool) $device->is_owner_device,
                'message' => $message,
            ];
        });
    }

    private function normalizeLoginDeviceType(?string $deviceType): string
    {
        $deviceType = strtolower(trim((string) ($deviceType ?: 'mobile')));

        return in_array($deviceType, ['mobile', 'browser', 'tv'], true)
            ? $deviceType
            : 'mobile';
    }
}
