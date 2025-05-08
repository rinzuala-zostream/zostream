<?php

namespace App\Console\Commands;

use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SendBirthdayWishes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-birthday-wishes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send birthday wishes to users via Zo Stream mail API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        $todayMonth = $now->month;
        $todayDay = $now->day;

        // Collect users whose birthday is today
        $users = UserModel::all()->filter(function ($user) use ($todayMonth, $todayDay) {
            try {
                $dob = Carbon::parse($user->dob);
                return $dob->month === $todayMonth && $dob->day === $todayDay;
            } catch (\Exception $e) {
                return false;
            }
        });

        // Message templates
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

            // Send mail via Zo Stream API
            $response = Http::asForm()->post('https://zostream.in/mail/send_mail.php', [
                'recipient' => $user->mail,
                'subject' => 'Happy Birthday from Zo Stream!',
                'body'     => $body,
            ]);

            if ($response->successful()) {
                $this->info("✅ Sent to {$user->mail}");
            } else {
                $this->error("❌ Failed to send to {$user->mail}");
            }
        }

        $this->info("🎉 Finished sending birthday wishes to {$users->count()} user(s).");
        return 0;
    }
}
