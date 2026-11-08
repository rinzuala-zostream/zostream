<?php

namespace App\Console\Commands;

use App\Models\New\ActiveStream;
use Illuminate\Console\Command;

class ExpireStaleStreams extends Command
{
    protected $signature = 'streams:expire-stale {--seconds= : Override the configured inactivity timeout}';

    protected $description = 'Mark inactive playback sessions as expired';

    public function handle(): int
    {
        $seconds = max(60, (int) ($this->option('seconds') ?: config('playback.stream_timeout_seconds', 500)));
        $cutoff = now()->subSeconds($seconds);

        $expired = ActiveStream::query()
            ->where('status', 'active')
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_ping')
                    ->orWhere('last_ping', '<', $cutoff);
            })
            ->update(['status' => 'expired']);

        $this->info("Expired {$expired} stale playback session(s).");

        return self::SUCCESS;
    }
}
