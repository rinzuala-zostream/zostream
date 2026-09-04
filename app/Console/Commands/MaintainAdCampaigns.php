<?php

namespace App\Console\Commands;

use App\Models\AdCampaign;
use App\Models\AdsModel;
use Illuminate\Console\Command;

class MaintainAdCampaigns extends Command
{
    protected $signature = 'ads:maintain-campaigns';

    protected $description = 'Complete expired or fully delivered ad campaigns and remove them from ad serving';

    public function handle(): int
    {
        $completed = 0;

        AdCampaign::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where(fn ($expired) => $expired->whereNotNull('end_at')->where('end_at', '<', now()))
                    ->orWhere(fn ($delivered) => $delivered->whereNotNull('target_quantity')->whereColumn('consumed_quantity', '>=', 'target_quantity'));
            })
            ->select('id')
            ->chunkById(100, function ($campaigns) use (&$completed) {
                $ids = $campaigns->pluck('id');
                $completed += AdCampaign::whereIn('id', $ids)->where('status', 'active')->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                AdsModel::whereIn('campaign_id', $ids)->update(['is_active' => false]);
            });

        $this->info("Completed {$completed} expired ad campaign(s).");

        return self::SUCCESS;
    }
}
