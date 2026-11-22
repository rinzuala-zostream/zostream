<?php

namespace App\Jobs;

use App\Http\Controllers\FCMNotificationController;
use App\Services\Engagement\EngagementRules;
use App\Services\Engagement\EngagementTiming;
use App\Services\Engagement\ExistingPushActivity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SendSmartEngagement implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $deliveryId)
    {
        $this->onConnection(config('engagement.queue_connection'));
        $this->onQueue(config('engagement.queue'));
    }

    public function handle(EngagementRules $rules, EngagementTiming $timing, ExistingPushActivity $existing, FCMNotificationController $push): void
    {
        $delivery = DB::table('engagement_deliveries')->where('id', $this->deliveryId)->first();
        if (! $delivery || ! in_array($delivery->status, ['pending', 'queued'], true)) {
            return;
        }
        if (! config('engagement.enabled') || ! config('engagement.tracking_enabled')) {
            $this->finish('cancelled', 'disabled');

            return;
        }
        if (config('engagement.user_allowlist') && ! in_array($delivery->user_id, config('engagement.user_allowlist'), true)) {
            $this->finish('cancelled', 'outside_rollout');

            return;
        }
        $lock = Cache::lock('engagement:user:'.hash('sha256', $delivery->user_id), 120);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            // Re-read after acquiring the lock: duplicate queue deliveries are harmless.
            $delivery = DB::table('engagement_deliveries')->where('id', $this->deliveryId)->first();
            if (! $delivery || ! in_array($delivery->status, ['pending', 'queued'], true)) {
                return;
            }
            $now = CarbonImmutable::now('UTC');
            $user = DB::table('engagement_users')->where('user_id', $delivery->user_id)->first();
            if (! $user || ! $user->enabled) {
                $this->finish('cancelled', 'opted_out');

                return;
            }
            if (CarbonImmutable::parse($delivery->scheduled_at, 'UTC')->gt($now)) {
                $this->postpone(CarbonImmutable::parse($delivery->scheduled_at, 'UTC'), 'not_due');

                return;
            }
            if ($timing->quiet($now)) {
                $this->postpone($timing->nextSlot($now, $user->watch_hours), 'quiet_hours');

                return;
            }
            $account = DB::table('user')->where('uid', $user->user_id)->first(['token', 'isACActive']);
            if (! $account || ! $account->isACActive || ! trim((string) $account->token)) {
                $this->finish('cancelled', 'no_active_recipient');

                return;
            }
            if ($existing->recent($user->user_id, $account->token, $now)) {
                $this->postpone($timing->nextSlot($now->addHours((int) config('engagement.existing_push_gap_hours')), $user->watch_hours), 'existing_notification');

                return;
            }
            if (DB::table('n_devices')->join('n_active_streams', 'n_active_streams.device_id', '=', 'n_devices.id')
                ->where('n_devices.user_id', $user->user_id)->where('n_active_streams.status', 'active')
                ->where('n_active_streams.last_ping', '>=', $now->subSeconds((int) config('playback.stream_timeout_seconds', 500))
                    ->setTimezone(config('app.timezone'))->toDateTimeString())->exists()) {
                $this->postpone($timing->nextSlot($now->addHour(), $user->watch_hours), 'currently_watching');

                return;
            }
            $candidate = $rules->candidate($user, $now);
            if (! $candidate || $rules->dedupe($user->user_id, $candidate) !== $delivery->dedupe_key || $candidate['content_id'] !== $delivery->content_id) {
                $this->finish('cancelled', 'no_longer_relevant');

                return;
            }
            $content = $rules->content($user, $delivery->content_type, $delivery->content_id);
            if (! $content) {
                $this->finish('cancelled', 'content_unavailable');

                return;
            }
            $text = app(\App\Services\Engagement\EngagementTemplates::class)->render($delivery->rule, $content['title']);
            // Serialize the shared counter across workers, independently of user locks.
            $rateLock = Cache::lock('engagement:push-rate-lock', 10);
            if (! $rateLock->get()) {
                $this->postpone($now->addMinutes(2), 'rate_limit');

                return;
            }
            try {
                if (RateLimiter::tooManyAttempts('engagement:push-rate', max(1, (int) config('engagement.pushes_per_minute')))) {
                    $this->postpone($now->addMinutes(2), 'rate_limit');

                    return;
                }
                RateLimiter::hit('engagement:push-rate', 60);
            } finally {
                $rateLock->release();
            }
            $now = CarbonImmutable::now('UTC');
            if ($timing->quiet($now)) {
                $this->postpone($timing->nextSlot($now, $user->watch_hours), 'quiet_hours');

                return;
            }
            if (! DB::table('engagement_deliveries')->where('id', $delivery->id)->whereIn('status', ['pending', 'queued'])
                ->update(['status' => 'sending', 'updated_at' => $now])) {
                return;
            }
            // Persist the claim before I/O. An ambiguous network failure must not
            // automatically send the same push twice. FCM acceptance is not device delivery.
            $result = $push->sendToToken($account->token, $text['title'], $text['body'],
                $content['image'], $content['movie_id'], [
                    'type' => 'smart_engagement', 'rule' => $delivery->rule, 'notification_id' => (string) $delivery->id,
                    'content_id' => $delivery->content_id, 'content_type' => $delivery->content_type,
                    'movie_id' => $content['movie_id'], 'episode_id' => $content['episode_id'],
                ]);
            DB::transaction(function () use ($result, $user, $now) {
                $success = ($result['success'] ?? false) === true;
                $this->finish($success ? 'sent' : 'failed', $success ? null : 'fcm_rejected_or_uncertain', $success ? $now : null);
                if ($success) {
                    DB::table('engagement_users')->where('user_id', $user->user_id)->update([
                        'last_sent_at' => $now, 'unopened_count' => DB::raw('unopened_count + 1'),
                        'next_check_at' => $now->addHours((int) config('engagement.cooldown_hours')),
                    ]);
                }
            });
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $error): void
    {
        // Do not alter a sending/sent row: its external delivery may be uncertain.
        DB::table('engagement_deliveries')->where('id', $this->deliveryId)->whereIn('status', ['pending', 'queued'])
            ->update(['status' => 'failed', 'reason' => 'worker_failed', 'updated_at' => now('UTC')]);
    }

    private function postpone(CarbonImmutable $at, string $reason): void
    {
        DB::table('engagement_deliveries')->where('id', $this->deliveryId)->update([
            'status' => 'pending', 'reason' => $reason, 'scheduled_at' => $at, 'updated_at' => now('UTC'),
        ]);
    }

    private function finish(string $status, ?string $reason, ?CarbonImmutable $sentAt = null): void
    {
        DB::table('engagement_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status, 'reason' => $reason, 'sent_at' => $sentAt, 'updated_at' => now('UTC'),
        ]);
    }
}
