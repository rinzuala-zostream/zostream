<?php

namespace App\Console\Commands;

use App\Models\BirthdayQueue;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Console\Command;

class QueueTodayBirthdays extends Command
{
    protected $signature = 'app:queue-today-birthdays';
    protected $description = 'Queue or update users whose birthdays are today';

    public function handle()
    {
        $now = Carbon::now();
        $users = UserModel::all()->filter(function ($user) use ($now) {
            if (empty($user->dob)) {
                return false;  // skip if dob is null or empty
            }
            try {
                $dob = Carbon::parse($user->dob);
                return $dob->month === $now->month && $dob->day === $now->day;
            } catch (\Exception $e) {
                return false;
            }
        });

        $queued = 0;
        $updated = 0;

        foreach ($users as $user) {
            $existing = BirthdayQueue::where('user_id', $user->uid)
            ->where('processed', true)
                ->first();

            if ($existing) {
                $existing->update([
                    'name' => $user->name,
                    'email' => $user->mail,
                    'birthday' => $user->dob,
                    'processed' => false,
                    'updated_at' => now(),
                    'token' => $user->token,
                ]);
                $updated++;
            } else {
                BirthdayQueue::create([
                    'user_id' => $user->uid,
                    'name' => $user->name,
                    'email' => $user->mail,
                    'birthday' => $user->dob,
                    'processed' => false,
                    'token' => $user->token,
                ]);
                $queued++;
            }
        }

        $this->info("🎉 Queued {$queued} new | 🔁 Updated {$updated} existing birthday(s).");
    }
}
