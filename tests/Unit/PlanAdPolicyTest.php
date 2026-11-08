<?php

namespace Tests\Unit;

use App\Models\New\Plan;
use App\Models\New\PlanFeature;
use App\Services\PlanAdPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanAdPolicyTest extends TestCase
{
    #[DataProvider('featureProvider')]
    public function test_it_maps_plan_features_to_ad_percentages(string $feature, int $expected): void
    {
        $plan = new Plan();
        $plan->setRelation('features', new Collection([
            new PlanFeature(['feature' => $feature, 'is_active' => true]),
        ]));

        $this->assertSame($expected, (new PlanAdPolicy())->percentageForPlan($plan));
    }

    public static function featureProvider(): array
    {
        return [
            'full ads' => ['Ads', 100],
            'forty percent ads' => ['Ad 40%', 40],
            'twenty percent ads' => ['Ad 20%', 20],
            'ad free' => ['Ad free', 0],
        ];
    }

    public function test_it_fails_open_when_a_plan_has_no_ad_feature(): void
    {
        $plan = new Plan();
        $plan->setRelation('features', new Collection([
            new PlanFeature(['feature' => 'Unlock all premium content', 'is_active' => true]),
        ]));

        $this->assertSame(100, (new PlanAdPolicy())->percentageForPlan($plan));
    }

    public function test_it_ignores_inactive_ad_features(): void
    {
        $plan = new Plan();
        $plan->setRelation('features', new Collection([
            new PlanFeature(['feature' => 'Ad free', 'is_active' => false]),
            new PlanFeature(['feature' => 'Ads', 'is_active' => true]),
        ]));

        $this->assertSame(100, (new PlanAdPolicy())->percentageForPlan($plan));
    }
}
