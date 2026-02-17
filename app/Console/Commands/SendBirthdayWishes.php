<?php

namespace App\Console\Commands;

use App\Http\Controllers\FCMNotificationController;
use App\Models\BirthdayQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SendBirthdayWishes extends Command
{
    protected $signature = 'birthday:send';
    protected $description = 'Send birthday wishes to users whose birthday is today';
    protected $fcmNotificationController;

    public function __construct(FCMNotificationController $fcmNotificationController)
    {
        parent::__construct();
        $this->fcmNotificationController = $fcmNotificationController;
    }

    public function handle()
    {
        $today = now();

        // Get all users with birthdays today (string-based check)
        $users = BirthdayQueue::where('processed', false)
            ->get()
            ->filter(function ($user) use ($today) {
                if (empty($user->birthday)) return false;
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

            // --- Initialize flags ---
            $fcmSent = false;
            $mailSent = false;

            // ✅ Try sending FCM
            try {
                if (!empty($user->token)) {
                    $fcmRequest = new Request([
                        'title' => 'Happy Birthday from Zo Stream!',
                        'body'  => $body,
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

            // ✅ Try sending email separately
            try {
                $mailResponse = Http::withOptions(['verify' => false]) // disable SSL for localhost
                    ->asForm()
                    ->post(url('/api/send-birthday-mail'), [
                        'recipient' => $user->email,
                        'subject'   => 'Happy Birthday from Zo Stream!',
                        'body'      => $body,
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

            // ✅ Now safely mark sent
            if ($fcmSent || $mailSent) {
                $sent++;
                $user->update(['processed' => true]);
                $this->info("✅ Sent to {$user->name} — FCM: " . ($fcmSent ? '✅' : '❌') . ", Mail: " . ($mailSent ? '✅' : '❌'));
            } else {
                $failed++;
                $this->error("❌ Failed for {$user->name}");
            }
        }

        $this->info("✅ Birthday send completed: Sent {$sent}, Failed {$failed}");
    }
}
