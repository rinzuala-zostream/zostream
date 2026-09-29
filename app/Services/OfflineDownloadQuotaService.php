<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class OfflineDownloadQuotaService
{
    /**
     * Reserve one of today's download slots. Re-requesting the same content
     * is idempotent so a retry does not consume another slot.
     *
     * @return array{allowed: bool, limit: int, used: int, remaining: int, reset_at: string}
     */
    public function claim(string $userId, string $contentType, string $contentId): array
    {
        $limit = max(1, (int) config('offline.daily_download_limit', 3));
        $now = now();
        $quotaDate = $now->toDateString();
        $userKey = hash('sha256', $userId);
        $contentKey = hash('sha256', strtolower($contentType).':'.$contentId);

        $result = DB::transaction(function () use (
            $userId,
            $contentType,
            $contentId,
            $limit,
            $now,
            $quotaDate,
            $userKey,
            $contentKey,
        ): array {
            DB::table('offline_download_daily_quotas')->insertOrIgnore([
                'user_key' => $userKey,
                'user_id' => $userId,
                'quota_date' => $quotaDate,
                'downloads_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $quota = DB::table('offline_download_daily_quotas')
                ->where('user_key', $userKey)
                ->whereDate('quota_date', $quotaDate)
                ->lockForUpdate()
                ->first();

            $alreadyGranted = DB::table('offline_download_grants')
                ->where('quota_id', $quota->id)
                ->where('content_key', $contentKey)
                ->exists();

            $used = (int) $quota->downloads_count;

            if (! $alreadyGranted && $used >= $limit) {
                return ['allowed' => false, 'used' => $used];
            }

            if (! $alreadyGranted) {
                DB::table('offline_download_grants')->insert([
                    'quota_id' => $quota->id,
                    'content_key' => $contentKey,
                    'content_type' => strtolower($contentType),
                    'content_id' => $contentId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $used++;
                DB::table('offline_download_daily_quotas')
                    ->where('id', $quota->id)
                    ->update([
                        'downloads_count' => $used,
                        'updated_at' => $now,
                    ]);
            }

            return ['allowed' => true, 'used' => $used];
        }, 3);

        return [
            'allowed' => $result['allowed'],
            'limit' => $limit,
            'used' => $result['used'],
            'remaining' => max(0, $limit - $result['used']),
            'reset_at' => $this->resetAt($now),
        ];
    }

    private function resetAt(CarbonInterface $now): string
    {
        return $now->copy()->addDay()->startOfDay()->utc()->toIso8601ZuluString();
    }
}
