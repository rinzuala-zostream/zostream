<?php

namespace App\Console\Commands;

use App\Http\Controllers\FCMNotificationController;
use App\Http\Controllers\WhatsAppController;
use App\Models\BirthdayQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SendBirthdayWishes extends Command
{
    protected $signature = 'birthday:send';
    protected $description = 'Send birthday wishes via FCM, Email, and WhatsApp to users whose birthday is today';

    protected $fcmNotificationController;
    protected $whatsAppController;

    public function __construct(
        FCMNotificationController $fcmNotificationController,
        WhatsAppController $whatsAppController
    ) {
        parent::__construct();
        $this->fcmNotificationController = $fcmNotificationController;
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
            $messages = [
                "🎉 Happy Birthday, {$user->name}! Wishing you a day filled with love, laughter, and joy!",
                "🎂 Cheers to you, {$user->name}! May your birthday be as amazing as you are!",
                "🎈 Hey {$user->name}, it's your special day! Enjoy every moment of it – happy birthday!",
                "🥳 Zo Stream wishes you the happiest of birthdays, {$user->name}! Stay awesome!",
                "🎁 Warmest wishes on your birthday, {$user->name}! Hope your day is full of surprises and joy.",
                "🌟 Happy Birthday, {$user->name}! May your year ahead be bright and full of success!",
                "🎊 Happy Birthday, {$user->name}! Keep shining and making the world a better place!",
                "🍰 It’s cake time, {$user->name}! Hope your birthday is just the beginning of a year full of happiness!",
                "🎉 Let’s celebrate YOU today, {$user->name}! Wishing you endless happiness and blessings!",
                "📺 From all of us at Zo Stream – enjoy your day, {$user->name}! You deserve the best!",
            ];

            $body = $messages[array_rand($messages)];

            // --- Initialize send status flags ---
            $fcmSent = false;
            $mailSent = false;
            $whatsAppSent = false;

            // ✅ 1. Try sending FCM
            try {
                if (!empty($user->token)) {
                    $fcmRequest = new Request([
                        'title' => 'Happy Birthday from Zo Stream!',
                        'body' => $body,
                        'image' => '',
                        'token' => $user->token,
                    ]);

                    $fcmResponse = $this->fcmNotificationController->send($fcmRequest);
                    $fcmSent = $fcmResponse['success'] ?? false;

                    Log::info('🎯 FCM Send Result', [
                        'user' => $user->name,
                        'status' => $fcmResponse['status'] ?? null,
                        'body' => $fcmResponse['body'] ?? null,
                        'error' => $fcmResponse['error'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('🔥 FCM send failed', [
                    'user' => $user->name,
                    'error' => $e->getMessage(),
                ]);
            }

            // ✅ 2. Try sending Email
            try {
                $mailResponse = Http::withOptions(['verify' => false])
                    ->asForm()
                    ->post(url('/api/send-birthday-mail'), [
                        'recipient' => $user->email,
                        'subject' => 'Happy Birthday from Zo Stream!',
                        'body' => $body,
                    ]);

                $mailSent = $mailResponse->successful();

                if (!$mailSent) {
                    Log::warning('⚠️ Mail sending returned non-success', [
                        'user' => $user->name,
                        'status' => $mailResponse->status(),
                        'body' => $mailResponse->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('🔥 Mail send failed', [
                    'user' => $user->name,
                    'error' => $e->getMessage(),
                ]);
            }

            // ✅ 3. Try sending WhatsApp Message
            try {
                if (!empty($user->auth_phone)) {
                    $whatsAppRequest = new Request([
                        "to" => $user->auth_phone,
                        "type" => "template",
                        "template_name" => "zostream_birthday_wish",
                        "template_params" => [$user->name, $body],
                        "language" => "en"
                    ]);

                    $whatsAppResponse = $this->whatsAppController->send($whatsAppRequest);
                    $json = $whatsAppResponse->getData(true);
                    $whatsAppSent = isset($json['status']) && $json['status'] === 'success';

                    Log::info('💬 WhatsApp Send Result', [
                        'user' => $user->name,
                        'phone' => $user->phone,
                        'status' => $json['status'] ?? null,
                        'response' => $json['response'] ?? null,
                        'error' => $json['error'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('🔥 WhatsApp send failed', [
                    'user' => $user->name,
                    'error' => $e->getMessage(),
                ]);
            }

            // ✅ Mark as processed if any success
            if ($fcmSent || $mailSent || $whatsAppSent) {
                $sent++;
                $user->update(['processed' => true]);
                $this->info("✅ Sent to {$user->name} — FCM: " . ($fcmSent ? '✅' : '❌') . ", Mail: " . ($mailSent ? '✅' : '❌') . ", WhatsApp: " . ($whatsAppSent ? '✅' : '❌'));
            } else {
                $failed++;
                $this->error("❌ Failed for {$user->name}");
            }
        }

        $this->info("🎉 Birthday send completed — Sent: {$sent}, Failed: {$failed}");
    }
}