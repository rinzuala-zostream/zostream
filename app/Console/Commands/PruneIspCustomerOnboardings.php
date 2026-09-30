<?php

namespace App\Console\Commands;

use App\Isp\Models\CustomerOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneIspCustomerOnboardings extends Command
{
    protected $signature = 'isp:prune-customer-onboardings';

    protected $description = 'Remove expired ISP customer onboarding data and abandoned private documents.';

    public function handle(): int
    {
        $abandoned = 0;
        CustomerOnboarding::query()
            ->where('status', '!=', 'completed')
            ->where('expires_at', '<', now())
            ->eachById(function (CustomerOnboarding $onboarding) use (&$abandoned): void {
                Storage::disk('local')->delete(array_filter([
                    $onboarding->aadhaar_front_path,
                    $onboarding->aadhaar_back_path,
                ]));
                $onboarding->delete();
                $abandoned++;
            });

        $completed = CustomerOnboarding::query()
            ->where('status', 'completed')
            ->where('completed_at', '<', now()->subDays(7))
            ->delete();

        $this->info("ISP customer onboarding cleanup complete: {$abandoned} abandoned, {$completed} completed record(s) removed.");

        return self::SUCCESS;
    }
}
