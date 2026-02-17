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

            try {
                $fcmSent = false;
                $mailSent = false;

                // ✅ Send FCM notification
                if (!empty($user->token)) {
                    $fcmRequest = new Request([
                        'title' => 'Happy Birthday from Zo Stream!',
                        'body' => $body,
                        'image' => '',
                        'token' => $user->token,
                    ]);

                    $fcmResponse = $this->fcmNotificationController->send($fcmRequest);
                    $fcmSent = $fcmResponse['success'] ?? false;

                }

                // ✅ Send email (SMTP API)
                $mailResponse = Http::asForm()->post(url('/api/send-birthday-mail'), [
                    'recipient' => $user->email,
                    'subject' => 'Happy Birthday from Zo Stream!',
                    'body' => $body,
                ]);

                if ($mailResponse->successful()) {
                    $mailSent = true;
                }

                // ✅ Mark as sent if either succeeds
                if ($fcmSent || $mailSent) {
                    $sent++;
                    $user->update(['processed' => true]);
                } else {
                    $failed++;
                    Log::error('Birthday delivery failed', [
                        'email' => $user->email,
                        'status' => $mailResponse->status(),
                        'body' => $mailResponse->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Birthday send exception', [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("✅ Birthday send completed: Sent {$sent}, Failed {$failed}");
    }
}
