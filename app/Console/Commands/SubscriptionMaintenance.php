<?php

namespace App\Console\Commands;

use App\Http\Controllers\WhatsAppController;
use App\Models\New\Subscription;
use App\Models\UserModel;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionMaintenance extends Command
{
    protected $signature = 'app:subscription-maintenance
                            {--reminder-days=3 : Send reminders for subscriptions expiring within this many days}
                            {--send-reminders=1 : Whether to send WhatsApp reminders to users}';

    protected $description = 'Deactivate expired subscriptions and send WhatsApp reminders for subscriptions that are about to expire.';

    public function __construct(
        protected WhatsAppController $whatsAppController
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reminderDays = max((int) $this->option('reminder-days'), 1);
        $sendReminders = filter_var((string) $this->option('send-reminders'), FILTER_VALIDATE_BOOLEAN);

        $this->deactivateExpiredSubscriptions();
        $this->processExpiringSubscriptions($reminderDays, $sendReminders);

        return self::SUCCESS;
    }

    protected function deactivateExpiredSubscriptions(): void
    {
        $expiredIds = Subscription::query()
            ->where('is_active', true)
            ->whereNotNull('end_at')
            ->where('end_at', '<=', now())
            ->pluck('id');

        if ($expiredIds->isEmpty()) {
            $this->info('No expired subscriptions to deactivate.');
            return;
        }

        Subscription::whereIn('id', $expiredIds)->update(['is_active' => false]);

        $this->info("Deactivated {$expiredIds->count()} expired subscriptions.");
    }

    protected function processExpiringSubscriptions(int $reminderDays, bool $sendReminders): void
    {
        $cutoff = now()->copy()->addDays($reminderDays);

        $subscriptions = Subscription::with('plan')
            ->where('is_active', true)
            ->whereNotNull('end_at')
            ->where('end_at', '>', now())
            ->where('end_at', '<=', $cutoff)
            ->orderBy('end_at')
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->info("No active subscriptions expiring within {$reminderDays} day(s).");
            return;
        }

        foreach ($subscriptions as $subscription) {
            $daysLeft = max(Carbon::now()->startOfDay()->diffInDays($subscription->end_at->copy()->startOfDay(), false), 0);

            $this->line(sprintf(
                'Reminder: subscription #%d for user %s expires on %s (%d day(s) left).',
                $subscription->id,
                $subscription->user_id,
                $subscription->end_at->format('Y-m-d H:i:s'),
                $daysLeft
            ));

            if ($sendReminders) {
                $this->sendReminderNotification($subscription, $daysLeft);
            }
        }
    }

    protected function sendReminderNotification(Subscription $subscription, int $daysLeft): void
    {
        $user = UserModel::query()
            ->where('uid', $subscription->user_id)
            ->first();

        $phone = $this->normalizePhone((string) ($user?->auth_phone));

        if (!$user || $phone === '') {
            $this->warn("Skipped reminder for subscription #{$subscription->id}: user phone not found.");
            return;
        }

        $planName = $subscription->plan?->name ?? 'subscription';
        $daysText = $daysLeft <= 0 ? 'today' : "{$daysLeft} day(s)";

        try {
            $response = $this->whatsAppController->send(new Request([
                "to" => $phone,
                "type" => "template",
                "template_name" => "zostream_subscription_reminder",
                "template_params" => [$planName, $daysText],
                "language" => "en"
            ]));


            if ($response->getStatusCode() >= 400) {
                $payload = $response->getData(true);
                $error = is_array($payload) ? ($payload['message'] ?? json_encode($payload)) : 'Unknown error';

                $this->warn("Reminder failed for subscription #{$subscription->id}: {$error}");
                Log::warning('Subscription reminder failed', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'phone' => $phone,
                    'error' => $error,
                ]);
                return;
            }

            $this->info("Reminder sent for subscription #{$subscription->id}.");
        } catch (\Throwable $e) {
            $this->warn("Reminder failed for subscription #{$subscription->id}: {$e->getMessage()}");
            Log::error('Subscription reminder exception', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone));
    }
}
