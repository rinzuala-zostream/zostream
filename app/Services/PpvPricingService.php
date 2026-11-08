<?php

namespace App\Services;

use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\PlanFeature;
use App\Models\New\Season;
use App\Models\New\Subscription;
use Illuminate\Support\Facades\Schema;

class PpvPricingService
{
    public function discountForSubscription(
        ?string $subscriptionId,
        ?string $userId,
        bool $requireOwnerMatch = true
    ): float {
        if (! $subscriptionId) {
            return 0.0;
        }

        $subscription = Subscription::with('plan')->find($subscriptionId);
        if (
            ! $subscription
            || ! $subscription->isActive()
            || ! $subscription->plan
            || ($requireOwnerMatch && (
                ! $userId
                || ! hash_equals((string) $subscription->user_id, $userId)
            ))
        ) {
            return 0.0;
        }

        return max($this->discountsForPlans([(int) $subscription->plan_id]) ?: [0.0]);
    }

    /** Return the submitted price only when it matches a price this user may pay. */
    public function validatePurchaseAmount(string $userId, string $contentId, $submittedAmount): ?float
    {
        $baseAmount = $this->publishedPpvAmount($contentId);
        if ($baseAmount === null || ! is_numeric($submittedAmount)) {
            return null;
        }

        $allowedAmounts = [$this->discountedAmount($baseAmount, 0.0)];
        $planIds = Subscription::query()
            ->where('user_id', $userId)
            ->currentlyActive()
            ->pluck('plan_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($this->discountsForPlans($planIds) as $discountPercent) {
            $allowedAmounts[] = $this->discountedAmount($baseAmount, $discountPercent);
        }

        $submitted = round((float) $submittedAmount, 2);
        foreach (array_unique($allowedAmounts) as $allowed) {
            if (abs($submitted - $allowed) < 0.005) {
                return $allowed;
            }
        }

        return null;
    }

    private function publishedPpvAmount(string $contentId): ?float
    {
        $movie = MovieModel::query()
            ->where('id', $contentId)
            ->where('status', 'Published')
            ->where('isEnable', 1)
            ->where('isPayPerView', 1)
            ->first();
        if ($movie) {
            return max(0.0, (float) ($movie->ppv_amount ?? 0));
        }

        $episode = Episode::query()
            ->where('id', $contentId)
            ->where('status', 'Published')
            ->where('is_active', true)
            ->where('isPayPerView', true)
            ->first();
        if ($episode) {
            return max(0.0, (float) ($episode->amount ?? 0));
        }

        $season = Season::query()
            ->with('movie')
            ->where('id', $contentId)
            ->where('status', 'Published')
            ->where('isPayPerView', true)
            ->first();
        if (
            $season
            && $season->movie
            && $season->movie->status === 'Published'
            && (bool) $season->movie->isEnable
        ) {
            return max(0.0, (float) ($season->amount ?? 0));
        }

        return null;
    }

    private function discountsForPlans(array $planIds): array
    {
        if (
            $planIds === []
            || ! Schema::hasColumn('n_plan_features', 'plan_id')
            || ! Schema::hasColumn('n_plan_features', 'ppv_discount')
        ) {
            return [];
        }

        return PlanFeature::query()
            ->whereIn('plan_id', $planIds)
            ->where('is_active', 1)
            ->pluck('ppv_discount')
            ->filter(fn ($discount) => is_numeric($discount))
            ->map(fn ($discount) => min(100.0, max(0.0, (float) $discount)))
            ->unique()
            ->values()
            ->all();
    }

    private function discountedAmount(float $amount, float $discountPercent): float
    {
        return round(max($amount - ($amount * $discountPercent / 100), 0), 2);
    }
}
