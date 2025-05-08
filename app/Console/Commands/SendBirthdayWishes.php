<?php

namespace App\Console\Commands;

use App\Models\UserModel;
use Carbon\Carbon;
use Http;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

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
    protected $description = 'Send birthday wishes to users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        $todayMonth = $now->month;
        $todayDay = $now->day;

        $users = UserModel::all()->filter(function ($user) use ($todayMonth, $todayDay) {
            try {
                $dob = Carbon::parse($user->dob);
                return $dob->month === $todayMonth && $dob->day === $todayDay;
            } catch (\Exception $e) {
                return false;
            }
        });

        foreach ($users as $user) {
            $response = Http::asForm()->post('https://zostream.in/mail/send_mail.php', [
                'recipient' => $user->mail,
                'subject' => '🎂 Happy Birthday from Zo Stream!',
                'body' => "🎉 Happy Birthday, {$user->name}!",
            ]);

            if ($response->successful()) {
                $this->info("Sent to {$user->mail}");
            } else {
                $this->error("Failed to send to {$user->mail}");
            }
        }

        $this->info("Finished sending birthday wishes to {$users->count()} user(s).");
        return 0;
    }
}
