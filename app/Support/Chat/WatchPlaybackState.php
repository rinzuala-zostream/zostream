<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class WatchPlaybackState
{
    public function update(Request $r, string $table, int $id, string $positionColumn, array $values): object
    {
        return DB::transaction(function () use ($r, $table, $id, $positionColumn, $values) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && $row->active, 410, 'This watch session has ended.');
            $sequence = $values['control_sequence'] ?? ((int) $row->control_sequence + 1);
            // A delayed HTTP request must never overwrite a newer pause/play/seek.
            if ($sequence <= (int) $row->control_sequence) { return $row; }
            $paused = $values['paused'] ?? (bool) $row->paused;
            DB::table($table)->where('id', $id)->update([
                $positionColumn => $values['position_ms'], 'paused' => $paused,
                'control_sequence' => $sequence, 'sampled_at_ms' => now()->getTimestampMs(), 'updated_at' => now(),
            ]);
            app(ChatService::class)->outbox($r, (int) $row->room_id, 'watch_control', null, null);
            return DB::table($table)->find($id);
        });
    }

    public function payload(object $row, string $positionColumn, bool $host): array
    {
        $elapsed = $row->paused || $host ? 0 : max(0, now()->getTimestampMs() - ($row->sampled_at_ms ?: Carbon::parse($row->updated_at)->getTimestampMs()));
        return ['position_ms' => min(86400000, (int) $row->{$positionColumn} + $elapsed),
            'paused' => (bool) $row->paused, 'revision' => (string) $row->control_sequence,
            'server_time_ms' => now()->getTimestampMs(), 'is_host' => $host];
    }
}
