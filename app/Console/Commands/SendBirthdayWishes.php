<?php

namespace App\Console\Commands;

use App\Http\Controllers\WhatsAppController;
use App\Models\BirthdayQueue;
use App\Models\UserModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SendBirthdayWishes extends Command
{
    protected $signature = 'birthday:send';
    protected $description = 'Send birthday wishes via WhatsApp to users whose birthday is today';

    protected $whatsAppController;

    public function __construct(
        WhatsAppController $whatsAppController
    ) {
        parent::__construct();
        $this->whatsAppController = $whatsAppController;
    }

    public function handle()
    {
        $today = now();

        // 🎯 Get users whose birthday is today
        $users = BirthdayQueue::where('processed', false)
            ->get()
            ->filter(function ($user) use ($today) {
                if (empty($user->birthday))
                    return false;
                try {
                    $dob = Carbon::parse($user->birthday);
                    return $dob->month === $today->month && $dob->day === $today->day;
                } catch (\Exception $e) {
                    return false;
                }
            });

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {

            $whatsAppSent = false;
            $phone = $this->resolvePhone($user);

            if ($phone === '') {
                $failed++;
                $this->error("❌ Failed for {$user->name}: WhatsApp phone not found");

                Log::warning('Birthday WhatsApp skipped: phone not found', [
                    'birthday_queue_id' => $user->id,
                    'user_id' => $user->user_id,
                    'user' => $user->name,
                ]);

                continue;
            }

            try {
                $whatsAppRequest = new Request([
                    "to" => $phone,
                    "type" => "template",
                    "template_name" => "zostream_birthday_wish",
                    "template_params" => [$user->name],
                    "language" => "en"
                ]);

                $whatsAppResponse = $this->whatsAppController->send($whatsAppRequest);
                $json = $whatsAppResponse->getData(true);
                $whatsAppSent = isset($json['status']) && $json['status'] === 'success';

                Log::info('💬 Birthday WhatsApp send result', [
                    'birthday_queue_id' => $user->id,
                    'user_id' => $user->user_id,
                    'user' => $user->name,
                    'phone' => $phone,
                    'status' => $json['status'] ?? null,
                    'response' => $json['response'] ?? null,
                    'error' => $json['error'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('🔥 Birthday WhatsApp send failed', [
                    'birthday_queue_id' => $user->id,
                    'user_id' => $user->user_id,
                    'user' => $user->name,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($whatsAppSent) {
                $sent++;
                $user->update(['processed' => true]);
                $this->info("✅ WhatsApp birthday wish sent to {$user->name}");
            } else {
                $failed++;
                $this->error("❌ WhatsApp birthday wish failed for {$user->name}");
            }
        }

        $this->info("🎉 Birthday send completed — Sent: {$sent}, Failed: {$failed}");
    }

    protected function resolvePhone(BirthdayQueue $user): string
    {
        $phone = $user->auth_phone ?? null;
        $countryCode = $user->country_code ?? null;

        if (empty($phone) || empty($countryCode)) {
            $sourceUser = UserModel::where('uid', $user->user_id)
                ->select(['auth_phone', 'country_code'])
                ->first();

            if (empty($phone)) {
                $phone = $sourceUser?->auth_phone;
            }

            if (empty($countryCode)) {
                $countryCode = $sourceUser?->country_code;
            }
        }

        $phone = preg_replace('/\D+/', '', trim((string) $phone)) ?? '';
        $countryCode = preg_replace('/\D+/', '', trim((string) $countryCode)) ?? '';

        if ($phone === '') {
            return '';
        }

        if ($countryCode === '') {
            return $phone;
        }

        $hasCountryCode = str_starts_with($phone, $countryCode) && strlen($phone) > 10;

        return $hasCountryCode ? $phone : $countryCode . $phone;
    }
}
