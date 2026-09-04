<?php

namespace Tests\Feature;

use App\Models\AdSubmission;
use App\Models\SessionTokenModel;
use App\Models\UserModel;
use App\Models\WhatsAppMessage;
use App\Services\AdApprovalNotificationService;
use App\Services\AdApprovalService;
use App\Services\AdBillingService;
use App\Services\AdCampaignService;
use App\Services\WhatsAppCloudService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
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
        Schema::create('user', function (Blueprint $table) {
            $table->increments('num');
            $table->uuid('uid')->unique();
            $table->string('auth_phone')->nullable();
            $table->string('country_code')->nullable();
        });
        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('access_token');
            $table->string('refresh_token');
            $table->timestamp('access_expires_at');
            $table->timestamp('refresh_expires_at');
            $table->string('device_name')->nullable();
            $table->string('device_id')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_09_02_000001_create_ad_submission_workflow.php'))->up();
        (require database_path('migrations/2026_09_02_000002_create_ad_campaign_billing_system.php'))->up();
        (require database_path('migrations/2026_09_03_000001_add_user_and_payment_notification_to_ad_submissions.php'))->up();
        (require database_path('migrations/2026_09_04_000001_add_served_quantity_to_ad_campaigns.php'))->up();
    }

    public function test_legacy_whatsapp_payment_button_url_redirects_to_the_payment_page(): void
    {
        $token = str_repeat('w', 48);

        $this->get('/advertise/payment/%7B%7B1%7D%7D'.$token)
            ->assertRedirect('/advertise/payment/'.$token);

        $this->get('/advertise/payment/%7B%7B1%7D%7D/open'.$token)
            ->assertRedirect('/advertise/payment/'.$token);
    }

    public function test_public_ad_can_be_submitted_and_checked_with_private_token(): void
    {
        $headers = $this->authenticatedClientHeaders();
        $response = $this->withHeaders($headers)->postJson('/api/v4/ad-submissions', [
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
            ->assertJsonPath('data.submission.status', AdSubmission::STATUS_PENDING)
            ->assertJsonPath('data.submission.user_id', '11111111-1111-4111-8111-111111111111');

        $statusUrl = $response->json('data.status_url');
        $token = basename(parse_url($statusUrl, PHP_URL_PATH));

        $this->assertSame(48, strlen($token));
        $this->withHeaders($headers)
            ->getJson('/api/v4/ad-submissions/status/'.$token)
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Example Store')
            ->assertJsonMissingPath('data.public_token_hash');

        $this->withHeaders($this->authenticatedClientHeaders(
            '33333333-3333-4333-8333-333333333333',
            'other-user-access',
        ))->getJson('/api/v4/ad-submissions/status/'.$token)->assertNotFound();
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
        $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ads/serve?placement=pre_roll&platform=web')
            ->assertOk()
            ->assertJsonPath('data', null);
        try {
            app(AdCampaignService::class)->activate($approved->campaign);
            $this->fail('An unpaid campaign was activated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }

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
            'watched_seconds' => 5,
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
        $this->withHeaders($this->authenticatedClientHeaders())->postJson('/api/v4/ad-submissions', [
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

    public function test_approval_notification_uses_the_submitting_users_auth_phone_and_payment_link(): void
    {
        $token = str_repeat('p', 48);
        $user = UserModel::create([
            'uid' => '22222222-2222-4222-8222-222222222222',
            'auth_phone' => '9876501234',
            'country_code' => '+91',
        ]);
        $submission = AdSubmission::create([
            'user_id' => $user->uid,
            'reference_no' => 'ADS-TEST-0002',
            'public_token_hash' => hash('sha256', $token),
            'public_token_encrypted' => Crypt::encryptString($token),
            'status' => AdSubmission::STATUS_PENDING,
            'business_name' => 'Authenticated Store',
            'contact_name' => 'Advertiser',
            'contact_phone' => '0000000000',
            'ads_name' => 'Home campaign',
            'type' => 'image',
            'placement_code' => 'home_top',
            'billing_model' => 'FLAT',
            'quoted_rate' => 500,
            'quoted_amount' => 15000,
            'currency' => 'INR',
            'media_url' => 'https://cdn.example.com/banner.webp',
            'requested_period_days' => 30,
        ]);
        $approved = app(AdApprovalService::class)->approve($submission, [], 'admin-uid');

        config(['ads.payment_whatsapp_template' => null]);
        $whatsApp = Mockery::mock(WhatsAppCloudService::class);
        $whatsApp->shouldReceive('sendText')
            ->once()
            ->with('919876501234', Mockery::on(fn ($message) => str_contains(
                $message,
                '/advertise/payment/'.$token,
            )))
            ->andReturn(new WhatsAppMessage);

        $sent = (new AdApprovalNotificationService($whatsApp))->sendPaymentLink($approved);

        $this->assertTrue($sent);
        $this->assertNotNull($approved->fresh()->approval_whatsapp_sent_at);
        $this->assertDatabaseHas('ad_submission_events', [
            'ad_submission_id' => $submission->id,
            'action' => 'payment_link_sent',
        ]);
    }

    public function test_approval_template_uses_amount_currency_and_button_token(): void
    {
        $token = str_repeat('t', 48);
        $user = UserModel::create([
            'uid' => '33333333-3333-4333-8333-333333333333',
            'auth_phone' => '9876505678',
            'country_code' => '+91',
        ]);
        $submission = AdSubmission::create([
            'user_id' => $user->uid,
            'reference_no' => 'ADS-TEST-0003',
            'public_token_hash' => hash('sha256', $token),
            'public_token_encrypted' => Crypt::encryptString($token),
            'status' => AdSubmission::STATUS_PENDING,
            'business_name' => 'Template Store',
            'contact_name' => 'Advertiser',
            'contact_phone' => '0000000000',
            'ads_name' => 'Template campaign',
            'type' => 'image',
            'placement_code' => 'home_top',
            'billing_model' => 'FLAT',
            'quoted_rate' => 500,
            'quoted_amount' => 15000,
            'currency' => 'INR',
            'media_url' => 'https://cdn.example.com/banner.webp',
            'requested_period_days' => 30,
        ]);
        $approved = app(AdApprovalService::class)->approve($submission, [], 'admin-uid');

        config([
            'ads.payment_whatsapp_template' => 'ad_payment_link',
            'ads.payment_whatsapp_template_language' => 'en',
        ]);
        $whatsApp = Mockery::mock(WhatsAppCloudService::class);
        $whatsApp->shouldReceive('sendTemplate')
            ->once()
            ->with(
                '919876505678',
                'ad_payment_link',
                ['ADS-TEST-0003', '15000.00', 'INR'],
                $token,
                'en',
            )
            ->andReturn(new WhatsAppMessage);

        $sent = (new AdApprovalNotificationService($whatsApp))->sendPaymentLink($approved);

        $this->assertTrue($sent);
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
            ['POST', 'api/v4/admin/ad-submissions/{adSubmission}/resend-payment-link'],
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

    public function test_submission_routes_require_login(): void
    {
        foreach ([
            ['POST', 'api/v4/ad-submissions'],
            ['GET', 'api/v4/ad-submissions/status/{token}'],
            ['POST', 'api/v4/ad-submissions/status/{token}/resubmit'],
        ] as [$method, $uri]) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri
            );

            $this->assertNotNull($route, "Missing {$method} {$uri}");
            $this->assertContains('auth.token', $route->gatherMiddleware());
        }
    }

    public function test_payment_link_routes_do_not_require_login(): void
    {
        foreach ([
            ['GET', 'api/v4/ad-submissions/status/{token}/payment'],
            ['POST', 'api/v4/ad-submissions/status/{token}/payments/razorpay/order'],
            ['POST', 'api/v4/ad-submissions/status/{token}/payments/razorpay/verify'],
        ] as [$method, $uri]) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri
            );

            $this->assertNotNull($route, "Missing {$method} {$uri}");
            $this->assertNotContains('auth.token', $route->gatherMiddleware());
        }
    }

    private function clientHeaders(): array
    {
        return [
            'X-Client-Platform' => 'web',
            'X-Client-Version' => '1.0',
        ];
    }

    private function authenticatedClientHeaders(
        string $uid = '11111111-1111-4111-8111-111111111111',
        string $accessToken = 'ad-workflow-access',
    ): array {
        UserModel::firstOrCreate(['uid' => $uid], [
            'auth_phone' => '9876543210',
            'country_code' => '+91',
        ]);
        SessionTokenModel::updateOrCreate(['user_id' => $uid], [
            'access_token' => SessionTokenModel::digest($accessToken),
            'refresh_token' => SessionTokenModel::digest($accessToken.'-refresh'),
            'access_expires_at' => now()->addHour(),
            'refresh_expires_at' => now()->addDay(),
            'device_name' => 'PHPUnit browser',
            'device_id' => 'phpunit-ad-device',
        ]);

        return array_merge($this->clientHeaders(), [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }
}
