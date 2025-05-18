<?php

namespace App\Console\Commands;

use App\Models\BirthdayQueue;
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
        $users = BirthdayQueue::where('processed', false)
            ->whereDate('created_at', today())
            ->get();

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
                "🎈 Sending you all the smiles, hugs, and cheer on your birthday, {$user->name}!",
                "🎂 Another year older, wiser, and more fabulous, {$user->name}! Have a great one!",
                "🎊 Birthdays are nature’s way of telling us to eat more cake. Enjoy it, {$user->name}!",
                "🎁 On your special day, {$user->name}, may you receive all the love you give — and more!",
                "🌈 Happy Birthday, {$user->name}! May your dreams take flight this year!",
                "🍭 Sweet wishes to the sweetest person – Happy Birthday, {$user->name}!",
                "☀️ Here's to sunshine, laughter, and unforgettable memories – Happy Birthday, {$user->name}!",
                "💫 Keep glowing, {$user->name}! Today is your day to sparkle!",
                "🦄 A magical birthday to you, {$user->name}! Hope it's full of wonder and delight!",
                "🎉 Wishing you love, laughter, and cake today and always, {$user->name}!",
                "📺 From all of us at Zo Stream – enjoy your day, {$user->name}! You deserve the best!",
            ];

            $body = $messages[array_rand($messages)];

            try {
                $response = Http::asForm()->post('https://zostream.in/mail/send_mail.php', [
                    'recipient' => $user->email,
                    'subject' => 'Happy Birthday from Zo Stream!',
                    'body' => $body,
                ]);

                if ($response->successful()) {
                    $sent++;
                    $user->update(['processed' => true, 'updated_at' => now()]);
                } else {
                    $failed++;
                    Log::error("Mail sending failed", [
                        'email' => $user->email,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("Mail send exception", [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Sent: $sent | Failed: $failed");
    }
}
