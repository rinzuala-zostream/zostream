<?php

namespace App\Console\Commands;

use App\Http\Controllers\WhatsAppController;
use App\Isp\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendIspWifiReminders extends Command
{
    protected $signature = 'isp:send-wifi-reminders';

    protected $description = 'Send WhatsApp reminders to ISP customers whose WiFi plan expires tomorrow.';

    public function __construct(
        private readonly WhatsAppController $whatsAppController,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expiryDate = today()->addDay();
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        Customer::query()
            ->with('package')
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->whereDate('expires_at', $expiryDate)
            ->whereHas('package', fn ($query) => $query->where('is_active', true))
            ->where(function ($query) use ($expiryDate): void {
                $query->whereNull('wifi_reminder_sent_for')
                    ->orWhereDate('wifi_reminder_sent_for', '!=', $expiryDate);
            })
            ->orderBy('id')
            ->eachById(function (Customer $customer) use ($expiryDate, &$sent, &$failed, &$skipped): void {
                $phone = $this->normalizePhone((string) $customer->phone);
                if ($phone === '') {
                    $skipped++;
                    $this->warn("Skipped customer #{$customer->id}: invalid phone number.");

                    return;
                }

                try {
                    $response = $this->whatsAppController->send(new Request([
                        'to' => $phone,
                        'type' => 'template',
                        'template_name' => config('app.whatsapp_wifi_reminder_template', 'zostream_wifi_reminder'),
                        'template_params' => [
                            'customer_name' => $customer->name,
                            'expiry_date' => $expiryDate->format('d M Y'),
                            'amount' => number_format((float) $customer->package->price, 2, '.', ''),
                            'plan_name' => $customer->package->name,
                        ],
                        'language' => config('app.whatsapp_wifi_reminder_language', 'en'),
                    ]));

                    if ($response->getStatusCode() >= 400) {
                        $failed++;
                        $payload = $response->getData(true);
                        $error = data_get($payload, 'error.error.message')
                            ?? data_get($payload, 'error.message')
                            ?? $payload['message']
                            ?? 'WhatsApp rejected the reminder.';
                        $this->warn("Reminder failed for customer #{$customer->id}: {$error}");
                        Log::warning('ISP WiFi reminder failed', [
                            'customer_id' => $customer->id,
                            'expiry_date' => $expiryDate->toDateString(),
                            'status_code' => $response->getStatusCode(),
                            'error' => $payload,
                        ]);

                        return;
                    }

                    $customer->forceFill([
                        'wifi_reminder_sent_for' => $expiryDate->toDateString(),
                    ])->save();
                    $sent++;
                    $this->info("Reminder sent for customer #{$customer->id}.");
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("Reminder failed for customer #{$customer->id}: {$e->getMessage()}");
                    Log::error('ISP WiFi reminder exception', [
                        'customer_id' => $customer->id,
                        'expiry_date' => $expiryDate->toDateString(),
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        $this->info("ISP WiFi reminders complete: {$sent} sent, {$failed} failed, {$skipped} skipped.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';
        $phone = ltrim($phone, '0');

        if (strlen($phone) === 10) {
            $phone = '91'.$phone;
        }

        return strlen($phone) >= 11 && strlen($phone) <= 15 ? $phone : '';
    }
}
