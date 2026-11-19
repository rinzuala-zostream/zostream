<?php

namespace App\Console\Commands;

use App\Jobs\SendSmartEngagement;
use App\Services\Engagement\EngagementRules;
use App\Services\Engagement\EngagementTiming;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QueueSmartEngagement extends Command
{
    protected $signature = 'engagement:queue {--dry-run : Preview candidates without recording or dispatching pushes} {--limit= : Maximum users per run (1-500)}';

    protected $description = 'Select bounded smart engagement batches independently of existing notifications.';

    public function handle(EngagementRules $rules, EngagementTiming $timing): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! config('engagement.tracking_enabled') || (! $dryRun && ! config('engagement.enabled'))) {
            $this->info('Smart engagement is disabled.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('engagement:planner', 240);
        if (! $lock->get()) {
            $this->info('Another engagement planner is running.');

            return self::SUCCESS;
        }
        try {
            $now = CarbonImmutable::now('UTC');
            $limit = min(500, max(1, (int) ($this->option('limit') ?? config('engagement.batch_size'))));
            $users = DB::table('engagement_users')->where('enabled', true)->where('next_check_at', '<=', $now)
                ->when(config('engagement.user_allowlist'), fn ($query) => $query->whereIn('user_id', config('engagement.user_allowlist')))
                ->orderBy('next_check_at')->orderBy('user_id')->limit($limit)->get();
            foreach ($users as $user) {
                $candidate = $rules->candidate($user, $now);
                if ($candidate) {
                    $slot = $timing->nextSlot($now, $user->watch_hours);
                    $this->line(json_encode(['user' => hash('sha256', $user->user_id), 'rule' => $candidate['rule'],
                        'content_id' => $candidate['content_id'], 'scheduled_at_ist' => $slot->setTimezone(config('engagement.timezone'))->toIso8601String()], JSON_THROW_ON_ERROR));
                    if (! $dryRun && ! DB::table('engagement_deliveries')->where('user_id', $user->user_id)->whereIn('status', ['pending', 'queued', 'sending'])->exists()) {
                        DB::table('engagement_deliveries')->insertOrIgnore([
                            'user_id' => $user->user_id, 'dedupe_key' => $rules->dedupe($user->user_id, $candidate),
                            ...array_diff_key($candidate, ['eligible_at' => true]),
                            'status' => 'pending', 'scheduled_at' => $slot, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
                if (! $dryRun) {
                    DB::table('engagement_users')->where('user_id', $user->user_id)->update(['next_check_at' => $now->addHours(6)]);
                }
            }
            if (! $dryRun && ! $timing->quiet($now)) {
                // Recover a dispatch/worker outage, never an uncertain FCM send (sending).
                $stale = DB::table('engagement_deliveries')->where('status', 'queued')->where('updated_at', '<', $now->subMinutes(30))
                    ->orderBy('updated_at')->limit($limit)->pluck('id');
                if ($stale->isNotEmpty()) {
                    DB::table('engagement_deliveries')->whereIn('id', $stale)->where('status', 'queued')
                        ->update(['status' => 'pending', 'updated_at' => $now]);
                }
                $due = DB::table('engagement_deliveries')->where('status', 'pending')->where('scheduled_at', '<=', $now)
                    ->orderBy('scheduled_at')->limit($limit)->get(['id']);
                foreach ($due as $delivery) {
                    if (DB::table('engagement_deliveries')->where('id', $delivery->id)->where('status', 'pending')->update(['status' => 'queued', 'updated_at' => $now])) {
                        SendSmartEngagement::dispatch($delivery->id);
                    }
                }
            }
            $this->info(($dryRun ? 'Previewed ' : 'Checked ').count($users).' users.');
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
