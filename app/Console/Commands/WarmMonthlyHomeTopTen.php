<?php

namespace App\Console\Commands;

use App\Services\LiveHomeSectionService;
use Illuminate\Console\Command;

class WarmMonthlyHomeTopTen extends Command
{
    protected $signature = 'home:warm-monthly-top-ten';

    protected $description = 'Precompute last-month Top 10 home shelves for all audiences';

    public function handle(LiveHomeSectionService $sections): int
    {
        $sections->warmLastMonthTopTen();
        $this->info('Last-month Top 10 home shelves refreshed.');

        return self::SUCCESS;
    }
}
