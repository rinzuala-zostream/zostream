<?php

namespace App\Console\Commands;

use App\Models\New\ActiveStream;
use Illuminate\Console\Command;

class PruneInactiveStreams extends Command
{
    protected $signature = 'streams:prune-inactive
        {--hours= : Override the configured retention period}
        {--batch= : Override the configured delete batch size}';

    protected $description = 'Delete stopped and expired playback sessions after the retention period';

    public function handle(): int
    {
        $hours = max(1, (int) (
            $this->option('hours')
            ?: config('playback.inactive_stream_retention_hours', 24)
        ));
        $batchSize = min(5000, max(1, (int) (
            $this->option('batch')
            ?: config('playback.inactive_stream_prune_batch', 1000)
        )));
        $cutoff = now()->subHours($hours);
        $deletedTotal = 0;

        do {
            $ids = ActiveStream::query()
                ->whereIn('status', ['stopped', 'expired'])
                ->where('last_ping', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // Re-check status and age so a session reactivated between the
            // select and delete statements can never be removed.
            $deletedTotal += ActiveStream::query()
                ->whereIn('id', $ids)
                ->whereIn('status', ['stopped', 'expired'])
                ->where('last_ping', '<', $cutoff)
                ->delete();
        } while ($ids->count() === $batchSize);

        $this->info("Pruned {$deletedTotal} inactive playback session(s) older than {$hours} hour(s).");

        return self::SUCCESS;
    }
}
