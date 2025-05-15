<?php

namespace App\Console\Commands;

use App\Models\UserModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendBirthdayWishes extends Command
{
    protected $signature = 'birthday:send';
    protected $description = 'Send birthday wishes to users whose birthday is today';

    public function handle()
    {
        $now = Carbon::now();

        $users = UserModel::all()->filter(function ($user) use ($now) {
            try {
                $dob = Carbon::parse($user->dob);
                return $dob->month === $now->month && $dob->day === $now->day;
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
            ];

            $body = $messages[array_rand($messages)];

            try {
                $response = Http::asForm()->post('https://zostream.in/mail/send_mail.php', [
                    'recipient' => $user->mail,
                    'subject'   => 'Happy Birthday from Zo Stream!',
                    'body'      => $body,
                ]);

                if ($response->successful()) {
                    $sent++;
                } else {
                    $failed++;
                    Log::error("Mail failed", [
                        'email' => $user->mail,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("Mail send exception", [
                    'email' => $user->mail,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Sent: $sent | Failed: $failed");
    }
}

