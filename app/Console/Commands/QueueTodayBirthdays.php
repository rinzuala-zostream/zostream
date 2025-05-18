<?php

namespace App\Console\Commands;

use App\Models\BirthdayQueue;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Console\Command;

class QueueTodayBirthdays extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:queue-today-birthdays';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Birthday queue';

    /**
     * Execute the console command.
     */
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

        foreach ($users as $user) {
            $alreadyQueued = BirthdayQueue::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->exists();

            if (!$alreadyQueued) {
                BirthdayQueue::insert([
                    'user_id' => $user->uid,
                    'name' => $user->name,
                    'email' => $user->mail,
                    'birthday' => $user->dob,
                    'processed' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $queued++;
            }
        }

        $this->info("Queued {$queued} new birthday(s) for today.");
    }
}
