<?php

namespace App\Services\Engagement;

use Carbon\CarbonImmutable;

class EngagementTiming
{
    public function nextSlot(CarbonImmutable $eligible, ?string $watchHours = null): CarbonImmutable
    {
        $local = $eligible->setTimezone(config('engagement.timezone'));
        $hours = json_decode($watchHours ?? '{}', true) ?: [];
        $samples = array_sum($hours);
        $hour = (int) config('engagement.default_send_hour', 18);
        if ($samples >= 3) {
            arsort($hours);
            $hour = (int) array_key_first($hours);
        }
        // Send an hour before the usual viewing hour, within 09:00–20:59 IST.
        if ($samples >= 3) {
            $hour--;
        }
        $hour = max((int) config('engagement.quiet_end_hour'), min($hour, (int) config('engagement.quiet_start_hour') - 1));
        $slot = $local->setTime($hour, 0);
        if ($slot->lt($local)) {
            $slot = $slot->addDay();
        }

        return $slot->utc();
    }

    public function quiet(CarbonImmutable $now): bool
    {
        $hour = $now->setTimezone(config('engagement.timezone'))->hour;

        return $hour >= (int) config('engagement.quiet_start_hour') || $hour < (int) config('engagement.quiet_end_hour');
    }
}
