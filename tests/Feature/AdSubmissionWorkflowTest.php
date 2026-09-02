<?php

namespace Tests\Feature;

use App\Models\AdSubmission;
use App\Services\AdApprovalService;
use App\Services\AdBillingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdSubmissionWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ads', function (Blueprint $table) {
            $table->increments('num');
            $table->string('ads_name');
            $table->string('create_date');
            $table->text('description')->nullable();
            $table->integer('period');
            $table->string('type');
            $table->text('video_url')->nullable();
            $table->text('ads_url')->nullable();
            $table->text('feature_img')->nullable();
            $table->text('img1')->nullable();
            $table->text('img2')->nullable();
            $table->text('img3')->nullable();
            $table->text('img4')->nullable();
        });

        (require database_path('migrations/2026_09_02_000001_create_ad_submission_workflow.php'))->up();
        (require database_path('migrations/2026_09_02_000002_create_ad_campaign_billing_system.php'))->up();
    }

    public function test_public_ad_can_be_submitted_and_checked_with_private_token(): void
    {
        $response = $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ad-submissions', [
            'business_name' => 'Example Store',
            'contact_name' => 'Lalruata',
            'contact_phone' => '+919876543210',
            'contact_email' => 'ads@example.com',
            'ads_name' => 'September Offer',
            'description' => 'A seasonal banner campaign.',
            'type' => 'image',
            'placement_code' => 'home_top',
            'billing_model' => 'FLAT',
            'media_url' => 'https://cdn.example.com/banner.webp',
            'destination_url' => 'https://example.com/offers',
            'requested_period_days' => 30,
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission.status', AdSubmission::STATUS_PENDING);

        $statusUrl = $response->json('data.status_url');
        $token = basename(parse_url($statusUrl, PHP_URL_PATH));

        $this->assertSame(48, strlen($token));
        $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ad-submissions/status/'.$token)
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Example Store')
            ->assertJsonMissingPath('data.public_token_hash');
    }

    public function test_approval_invoices_then_payment_activates_the_live_ad(): void
    {
        $submission = AdSubmission::create([
            'reference_no' => 'ADS-TEST-0001',
            'public_token_hash' => hash('sha256', str_repeat('a', 48)),
            'status' => AdSubmission::STATUS_PENDING,
            'business_name' => 'Example Store',
            'contact_name' => 'Lalruata',
            'contact_phone' => '9876543210',
            'ads_name' => 'Video campaign',
            'type' => 'video',
            'placement_code' => 'pre_roll',
            'billing_model' => 'CPV',
            'target_quantity' => 1000,
            'quoted_rate' => 2,
            'quoted_amount' => 2000,
            'currency' => 'INR',
            'media_url' => 'https://cdn.example.com/ad.mp4',
            'destination_url' => 'https://example.com',
            'requested_period_days' => 14,
        ]);

        $approved = app(AdApprovalService::class)->approve($submission, [], 'admin-uid');

        $this->assertSame(AdSubmission::STATUS_APPROVED, $approved->status);
        $this->assertNull($approved->approved_ad_num);
        $this->assertSame('pending_payment', $approved->campaign->status);
        $invoice = $approved->campaign->invoices->first();
        $this->assertSame('pending', $invoice->status);

        app(AdBillingService::class)->markPaid($invoice, [
            'amount' => 2000,
            'payment_method' => 'manual',
            'gateway' => 'manual',
            'gateway_order_id' => 'UTR-TEST-1',
        ]);
        $approved->refresh();

        $this->assertNotNull($approved->approved_ad_num);
        $this->assertDatabaseHas('ads', [
            'num' => $approved->approved_ad_num,
            'ads_name' => 'Video campaign',
            'video_url' => 'https://cdn.example.com/ad.mp4',
            'target_url' => 'https://example.com',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('ad_submission_events', [
            'ad_submission_id' => $submission->id,
            'action' => 'approved',
            'actor_id' => 'admin-uid',
        ]);

        $served = $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ads/serve?placement=pre_roll&platform=web')
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $approved->campaign->id)
            ->assertJsonPath('data.type', 'video');
        $trackingToken = $served->json('data.tracking_token');
        $impressionEvent = (string) Str::uuid();

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $trackingToken,
            'event_id' => $impressionEvent,
            'event' => 'impression',
        ])->assertOk()->assertJsonPath('data.recorded', true);

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $trackingToken,
            'event_id' => (string) Str::uuid(),
            'event' => 'video_complete',
            'impression_event_id' => $impressionEvent,
            'watched_seconds' => 30,
        ])->assertOk()->assertJsonPath('data.recorded', true);

        $this->assertDatabaseHas('ad_billing_events', [
            'campaign_id' => $approved->campaign->id,
            'event_type' => 'video_view',
            'amount' => 2,
        ]);
        $this->assertSame(1, $approved->campaign->fresh()->consumed_quantity);

        $approved->campaign->update(['end_at' => now()->subMinute()]);
        $this->artisan('ads:maintain-campaigns')->assertSuccessful();
        $this->assertSame('completed', $approved->campaign->fresh()->status);
        $this->assertDatabaseHas('ads', ['num' => $approved->approved_ad_num, 'is_active' => false]);
    }

    public function test_video_submission_requires_video_media(): void
    {
        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ad-submissions', [
            'business_name' => 'Example Store',
            'contact_name' => 'Lalruata',
            'contact_phone' => '9876543210',
            'ads_name' => 'Video campaign',
            'type' => 'video',
            'placement_code' => 'pre_roll',
            'billing_model' => 'CPV',
            'target_quantity' => 1000,
            'requested_period_days' => 14,
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('media', 'error.details');
    }

    public function test_public_pricing_quote_is_calculated_from_admin_rates(): void
    {
        $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ad-pricing')
            ->assertOk()
            ->assertJsonFragment(['code' => 'home_top', 'billing_model' => 'CPM']);

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ad-pricing/quote', [
            'type' => 'image',
            'placement_code' => 'home_top',
            'billing_model' => 'CPM',
            'target_quantity' => 10000,
            'requested_period_days' => 30,
        ])->assertOk()
            ->assertJsonPath('data.rate', 120)
            ->assertJsonPath('data.amount', 1200);
    }

    public function test_admin_submission_routes_are_admin_protected(): void
    {
        foreach ([
            ['GET', 'api/v4/admin/ad-submissions'],
            ['GET', 'api/v4/admin/ad-submissions/{adSubmission}'],
            ['POST', 'api/v4/admin/ad-submissions/{adSubmission}/approve'],
            ['POST', 'api/v4/admin/ad-submissions/{adSubmission}/reject'],
            ['POST', 'api/v4/admin/ad-submissions/{adSubmission}/request-changes'],
            ['GET', 'api/v4/admin/ads/billing-rates'],
            ['PUT', 'api/v4/admin/ads/billing-rates/{rate}'],
            ['PUT', 'api/v4/admin/ads/placements/{placement}'],
            ['GET', 'api/v4/admin/ads/billing-dashboard'],
            ['POST', 'api/v4/admin/ads/invoices/{invoice}/mark-paid'],
            ['PUT', 'api/v4/admin/ads/campaigns/{campaign}/status'],
        ] as [$method, $uri]) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri
            );

            $this->assertNotNull($route, "Missing {$method} {$uri}");
            $this->assertContains('auth.token', $route->gatherMiddleware());
            $this->assertContains('admin.token', $route->gatherMiddleware());
        }
    }

    private function clientHeaders(): array
    {
        return [
            'X-Client-Platform' => 'web',
            'X-Client-Version' => '1.0',
        ];
    }
}
