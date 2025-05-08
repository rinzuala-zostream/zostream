<?php

namespace App\Console\Commands;

use App\Models\UserModel;
use Carbon\Carbon;
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
    
        // Only proceed between 7 AM and 10 AM
        if ($now->hour < 7 || $now->hour >= 11) {
            $this->info('Outside scheduled birthday wish hours.');
            return 0;
        }
    
        $todayMonth = $now->month;
        $todayDay = $now->day;
    
        $users = UserModel::all()->filter(function ($user) use ($todayMonth, $todayDay) {
            try {
                $dob = Carbon::parse($user->dob);
                return $dob->month === $todayMonth && $dob->day === $todayDay;
            } catch (\Exception $e) {
                return false; // skip if parse fails
            }
        });
    
        foreach ($users as $user) {
            Mail::raw("🎉 Happy Birthday, {$user->name}!", function ($message) use ($user) {
                $message->to($user->mail)
                    ->subject('🎂 Happy Birthday from Zo Stream!');
            });
        }
    
        $this->info("Sent birthday wishes to {$users->count()} user(s).");
        return 0;
    }    
}
