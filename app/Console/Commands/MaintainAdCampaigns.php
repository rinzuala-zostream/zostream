<?php

namespace App\Console\Commands;

use App\Models\AdCampaign;
use App\Models\AdsModel;
use Illuminate\Console\Command;

class MaintainAdCampaigns extends Command
{
    protected $signature = 'ads:maintain-campaigns';

    protected $description = 'Resume daily-budget campaigns and complete expired or fully delivered campaigns';

    public function handle(): int
    {
        $resumedIds = AdCampaign::query()
            ->where('status', 'paused')
            ->where('pause_reason', 'daily_budget')
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->where(fn ($query) => $query->whereNull('target_quantity')->orWhereColumn('consumed_quantity', '<', 'target_quantity'))
            ->pluck('id');

        if ($resumedIds->isNotEmpty()) {
            AdCampaign::whereIn('id', $resumedIds)->update([
                'status' => 'active',
                'pause_reason' => null,
                'resume_at' => null,
            ]);
            AdsModel::whereIn('campaign_id', $resumedIds)->update(['is_active' => true]);
        }

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

        $this->info("Resumed {$resumedIds->count()} and completed {$completed} ad campaign(s).");

        return self::SUCCESS;
    }
}
