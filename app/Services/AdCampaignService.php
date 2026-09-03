<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\AdsModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdCampaignService
{
    public function activate(AdCampaign $campaign): AdCampaign
    {
        return DB::transaction(function () use ($campaign) {
            $locked = AdCampaign::query()->lockForUpdate()->findOrFail($campaign->getKey());
            $locked->load(['creatives', 'submission.assets']);

            if (in_array($locked->status, ['active', 'completed'], true)) {
                return $locked;
            }

            if (! in_array($locked->status, ['pending_payment', 'approved', 'scheduled'], true)) {
                throw ValidationException::withMessages(['campaign' => ['This campaign cannot be activated.']]);
            }
            if ($locked->requires_prepayment && ! $locked->invoices()->where('status', 'paid')->exists()) {
                throw ValidationException::withMessages([
                    'payment' => ['The campaign invoice must be paid before activation.'],
                ]);
            }

            $submission = $locked->submission;
            $startAt = $locked->start_at ?: now();
            $period = max(1, $startAt->diffInDays($locked->end_at ?: $startAt->copy()->addDays($submission->requested_period_days)));
            $feature = $submission->assets->firstWhere('kind', 'feature');
            $gallery = $submission->assets->where('kind', 'gallery')->values();

            foreach ($locked->creatives as $creative) {
                if ($creative->existing_ad_num) {
                    continue;
                }

                $ad = AdsModel::create([
                    'ads_name' => $creative->name,
                    'create_date' => Carbon::parse($startAt)->format('F j, Y'),
                    'description' => $submission->description,
                    'period' => $period,
                    'type' => $creative->type,
                    'video_url' => $creative->type === 'video' ? $creative->media_url : null,
                    'ads_url' => null,
                    'target_url' => $creative->target_url,
                    'campaign_id' => $locked->id,
                    'is_active' => true,
                    'feature_img' => $feature?->file_url ?: $creative->thumbnail_url,
                    'img1' => $gallery->get(0)?->file_url,
                    'img2' => $gallery->get(1)?->file_url,
                    'img3' => $gallery->get(2)?->file_url,
                    'img4' => $gallery->get(3)?->file_url,
                ]);
                $ad->ads_url = route('ads.show', ['ad' => $ad->getKey().'-'.Str::slug($ad->ads_name)]);
                $ad->save();
                $creative->update(['existing_ad_num' => $ad->getKey()]);
                $submission->update(['approved_ad_num' => $ad->getKey()]);
            }

            $locked->update(['status' => 'active', 'activated_at' => $locked->activated_at ?: now()]);

            return $locked->fresh(['creatives', 'invoices']);
        });
    }
}
