<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class NexframeSocialHistory
{
    public function observe(string $platform, array $account, Carbon $from, Carbon $to): array
    {
        $empty = ['available' => false, 'points' => [], 'daily_engagement' => [], 'change' => null, 'message' => 'Follower history starts with successful dashboard syncs.'];
        if (! ($account['healthy'] ?? false) || ! isset($account['account_id'])) {
            return $empty;
        }

        try {
            $disk = Storage::disk('local');
            $folder = 'nexframe-history/'.$platform.'/'.hash('sha256', (string) $account['account_id']);
            if (($account['followers'] ?? null) !== null) {
                $disk->put($folder.'/'.now()->toDateString().'.json', json_encode([
                    'date' => now()->toDateString(),
                    'followers' => (int) $account['followers'],
                    'observed_at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR));
            }
            foreach ($account['daily_engagement'] ?? [] as $date => $value) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date < now()->toDateString()) {
                    $disk->put($folder.'/activity/'.$date.'.json', json_encode([
                        'date' => $date, 'interactions' => $value, 'observed_at' => now()->toIso8601String(), 'completed_day' => true,
                    ], JSON_THROW_ON_ERROR));
                }
            }
            $daily = collect($disk->files($folder.'/activity'))->filter(function (string $file) use ($from, $to) {
                $date = pathinfo($file, PATHINFO_FILENAME);

                return $date >= $from->toDateString() && $date <= $to->toDateString();
            })->mapWithKeys(function (string $file) use ($disk) {
                $point = json_decode($disk->get($file), true, 512, JSON_THROW_ON_ERROR);

                return ($point['completed_day'] ?? false) ? [$point['date'] => $point['interactions']] : [];
            })->all();

            $points = collect($disk->files($folder))->filter(function (string $file) use ($from, $to) {
                $date = pathinfo($file, PATHINFO_FILENAME);

                return $date >= $from->toDateString() && $date <= $to->toDateString();
            })->sort()->map(fn (string $file) => json_decode($disk->get($file), true, 512, JSON_THROW_ON_ERROR))->values();

            return [
                'available' => $points->isNotEmpty(),
                'points' => $points->all(),
                'daily_engagement' => $daily,
                'change' => $points->count() >= 2 ? $points->last()['followers'] - $points->first()['followers'] : null,
                'message' => 'Observed followers at sync time. Missing days are not backfilled.',
            ];
        } catch (Throwable) {
            return [...$empty, 'message' => 'Follower history storage is unavailable.'];
        }
    }
}
