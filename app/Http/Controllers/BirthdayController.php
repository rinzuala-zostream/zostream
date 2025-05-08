<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BirthdayController extends Controller
{
    public function sendWishes()
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

            $response = Http::asForm()->post('https://zostream.in/mail/send_mail.php', [
                'recipient' => $user->mail,
                'subject'   => 'Happy Birthday from Zo Stream!',
                'body'      => $body,
            ]);

            if ($response->successful()) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return response()->json([
            'status' => 'done',
            'sent' => $sent,
            'failed' => $failed,
            'total' => $users->count(),
        ]);
    }
}
