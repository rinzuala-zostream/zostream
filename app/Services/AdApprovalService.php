<?php

namespace App\Services;

use App\Models\AdsModel;
use App\Models\AdSubmission;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdApprovalService
{
    public function approve(AdSubmission $submission, array $overrides, string $adminId): AdSubmission
    {
        return DB::transaction(function () use ($submission, $overrides, $adminId) {
            $locked = AdSubmission::query()->lockForUpdate()->findOrFail($submission->getKey());

            if ($locked->status !== AdSubmission::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Only a pending submission can be approved.'],
                ]);
            }

            $locked->load('assets');
            $startDate = Carbon::parse(
                $overrides['start_date'] ?? $locked->requested_start_date ?? now()
            );
            $period = (int) ($overrides['period_days'] ?? $locked->requested_period_days);
            $feature = $locked->assets->firstWhere('kind', 'feature');
            $gallery = $locked->assets->where('kind', 'gallery')->values();
            $mainMediaUrl = $overrides['media_url'] ?? $locked->media_url;

            $ad = AdsModel::create([
                'ads_name' => $locked->ads_name,
                'create_date' => $startDate->format('F j, Y'),
                'description' => $locked->description,
                'period' => $period,
                'type' => $locked->type,
                'video_url' => $locked->type === 'video' ? $mainMediaUrl : null,
                'ads_url' => null,
                'target_url' => $locked->destination_url,
                'feature_img' => $feature?->file_url ?: ($locked->type === 'image' ? $mainMediaUrl : null),
                'img1' => $gallery->get(0)?->file_url,
                'img2' => $gallery->get(1)?->file_url,
                'img3' => $gallery->get(2)?->file_url,
                'img4' => $gallery->get(3)?->file_url,
            ]);

            $ad->ads_url = route('ads.show', [
                'ad' => $ad->getKey().'-'.Str::slug($ad->ads_name),
            ]);
            $ad->save();

            $locked->update([
                'status' => AdSubmission::STATUS_APPROVED,
                'review_note' => $overrides['review_note'] ?? null,
                'rejection_reason' => null,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'approved_ad_num' => $ad->getKey(),
            ]);

            $locked->events()->create([
                'action' => 'approved',
                'from_status' => AdSubmission::STATUS_PENDING,
                'to_status' => AdSubmission::STATUS_APPROVED,
                'note' => $overrides['review_note'] ?? null,
                'actor_type' => 'admin',
                'actor_id' => $adminId,
            ]);

            return $locked->fresh(['assets', 'events']);
        });
    }
}
