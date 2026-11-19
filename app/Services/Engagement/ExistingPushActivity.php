<?php

namespace App\Services\Engagement;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ExistingPushActivity
{
    public function record(?string $token = null, ?string $userId = null): void
    {
        if (! config('engagement.tracking_enabled')) {
            return;
        }
        try {
            $key = $userId !== null ? 'user:'.hash('sha256', $userId) : ($token ? 'token:'.hash('sha256', $token) : 'broadcast');
            Cache::put('engagement:existing:'.$key, now('UTC')->toDateTimeString(), now()->addHours(48));
        } catch (Throwable $error) {
            // Recording must never change whether an existing notification succeeds.
            report($error);
        }
    }

    public function recent(string $userId, string $token, CarbonImmutable $now): bool
    {
        // Cache errors propagate here: only the optional smart push is postponed.
        foreach (['broadcast', 'user:'.hash('sha256', $userId), 'token:'.hash('sha256', $token)] as $key) {
            $value = Cache::get('engagement:existing:'.$key);
            if ($value && CarbonImmutable::parse($value, 'UTC')->gt($now->subHours((int) config('engagement.existing_push_gap_hours')))) {
                return true;
            }
        }

        return false;
    }
}
