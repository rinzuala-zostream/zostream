<?php

namespace App\Services;

use App\Models\New\Plan;
use App\Models\New\Subscription;
use App\Models\SessionTokenModel;
use Illuminate\Http\Request;

class PlanAdPolicy
{
    public function shouldServe(Request $request): bool
    {
        $percentage = $this->percentageForRequest($request);

        if ($percentage <= 0) {
            return false;
        }

        if ($percentage >= 100) {
            return true;
        }

        return random_int(1, 100) <= $percentage;
    }

    public function percentageForRequest(Request $request): int
    {
        $userId = trim((string) $request->attributes->get('auth_user_id', ''));

        if ($userId === '') {
            $userId = $this->userIdFromBearerToken($request) ?? '';
        }

        $deviceType = strtolower(trim((string) (
            $request->header('X-Device-Type')
            ?: $request->input('device_type', 'mobile')
        )));

        return $this->percentageForUser($userId, $deviceType ?: 'mobile');
    }

    public function percentageForUser(?string $userId, string $deviceType = 'mobile'): int
    {
        $userId = trim((string) $userId);
        if ($userId === '') {
            return 100;
        }

        $subscriptions = Subscription::query()
            ->with(['plan.features' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('sort_order')])
            ->where('user_id', $userId)
            ->currentlyActive()
            ->whereHas('plan', fn ($query) => $query
                ->where('is_active', true)
                ->where('device_type', strtolower(trim($deviceType))))
            ->get();

        if ($subscriptions->isEmpty()) {
            return 100;
        }

        return (int) $subscriptions
            ->map(fn (Subscription $subscription) => $this->percentageForPlan($subscription->plan))
            ->min();
    }

    public function percentageForPlan(?Plan $plan): int
    {
        if (! $plan) {
            return 100;
        }

        $features = $plan->relationLoaded('features')
            ? $plan->features
            : $plan->features()->where('is_active', true)->orderBy('sort_order')->get();

        foreach ($features as $feature) {
            if (isset($feature->is_active) && ! (bool) $feature->is_active) {
                continue;
            }

            $label = strtolower(trim((string) $feature->feature));

            if (preg_match('/\bads?\s*free\b/i', $label)) {
                return 0;
            }

            if (preg_match('/\bads?\s*(\d+(?:\.\d+)?)\s*%/i', $label, $matches)) {
                return max(0, min(100, (int) round((float) $matches[1])));
            }
        }

        return 100;
    }

    private function userIdFromBearerToken(Request $request): ?string
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            return null;
        }

        $session = SessionTokenModel::findByAccessToken($token);
        if (! $session || ! $session->access_expires_at || $session->access_expires_at->isPast()) {
            return null;
        }

        return trim((string) $session->user_id) ?: null;
    }
}
