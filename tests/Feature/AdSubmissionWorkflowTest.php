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
        (require database_path('migrations/2026_09_22_000001_add_pause_metadata_to_ad_campaigns.php'))->up();
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

    public function test_flat_and_cpc_quotes_use_days_and_clicks(): void
    {
        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ad-pricing/quote', [
            'type' => 'image',
            'placement_code' => 'home_top',
            'billing_model' => 'FLAT',
            'requested_period_days' => 30,
        ])->assertOk()
            ->assertJsonPath('data.billing_quantity', 30)
            ->assertJsonPath('data.target_quantity', null)
            ->assertJsonPath('data.amount', 15000);

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ad-pricing/quote', [
            'type' => 'image',
            'placement_code' => 'home_top',
            'billing_model' => 'CPC',
            'target_quantity' => 250,
            'requested_period_days' => 30,
        ])->assertOk()
            ->assertJsonPath('data.billing_quantity', 250)
            ->assertJsonPath('data.amount', 1000);
    }

    public function test_cpc_counts_distinct_click_events_until_the_target_is_reached(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-CPC-DEDUP',
            'billing_model' => 'CPC',
            'target_quantity' => 2,
            'quoted_rate' => 4,
            'quoted_amount' => 100,
        ]);
        [$trackingToken, $impressionEvent] = $this->serveAndTrackImpression($approved->campaign->id);

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $trackingToken,
            'event_id' => (string) Str::uuid(),
            'event' => 'click',
            'impression_event_id' => $impressionEvent,
        ])->assertOk()->assertJsonPath('data.billable', true);

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $trackingToken,
            'event_id' => (string) Str::uuid(),
            'event' => 'click',
            'impression_event_id' => $impressionEvent,
        ])->assertOk()->assertJsonPath('data.billable', true);

        $campaign = $approved->campaign->fresh();
        $this->assertSame(2, $campaign->consumed_quantity);
        $this->assertSame('completed', $campaign->status);
        $this->assertDatabaseCount('ad_billing_events', 2);
    }

    public function test_cpc_reaches_its_target_from_two_separate_impressions(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-CPC-TWO-DEVICES',
            'billing_model' => 'CPC',
            'target_quantity' => 2,
            'quoted_rate' => 4,
            'quoted_amount' => 100,
        ]);

        foreach (['device-one', 'device-two'] as $device) {
            $headers = array_merge($this->clientHeaders(), ['Device-Token' => $device]);
            $served = $this->withHeaders($headers)
                ->getJson('/api/v4/ads/serve?placement=home_top&platform=web')
                ->assertOk()
                ->assertJsonPath('data.campaign_id', $approved->campaign->id);
            $impressionEvent = (string) Str::uuid();
            $this->withHeaders($headers)->postJson('/api/v4/ads/events', [
                'tracking_token' => $served->json('data.tracking_token'),
                'event_id' => $impressionEvent,
                'event' => 'impression',
            ])->assertOk();
            $this->withHeaders($headers)->postJson('/api/v4/ads/events', [
                'tracking_token' => $served->json('data.tracking_token'),
                'event_id' => (string) Str::uuid(),
                'event' => 'click',
                'impression_event_id' => $impressionEvent,
            ])->assertOk()->assertJsonPath('data.billable', true);
        }

        $campaign = $approved->campaign->fresh();
        $this->assertSame(2, $campaign->consumed_quantity);
        $this->assertSame('completed', $campaign->status);
        $this->assertDatabaseCount('ad_billing_events', 2);
        $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ads/serve?placement=home_top&platform=web')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_cpc_recovers_a_lost_impression_before_recording_the_click(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-CPC-LOST-IMPRESSION',
            'billing_model' => 'CPC',
            'target_quantity' => 2,
            'quoted_rate' => 4,
            'quoted_amount' => 100,
        ]);
        [$firstTrackingToken, $firstImpressionEvent] = $this->serveAndTrackImpression($approved->campaign->id);
        $this->withHeaders(array_merge($this->clientHeaders(), ['Device-Token' => 'first-device']))
            ->postJson('/api/v4/ads/events', [
                'tracking_token' => $firstTrackingToken,
                'event_id' => (string) Str::uuid(),
                'event' => 'click',
                'impression_event_id' => $firstImpressionEvent,
            ])->assertOk()->assertJsonPath('data.billable', true);
        $this->assertSame(1, $approved->campaign->fresh()->consumed_quantity);

        $served = $this->withHeaders(array_merge($this->clientHeaders(), ['Device-Token' => 'second-device']))
            ->getJson('/api/v4/ads/serve?placement=home_top&platform=web')
            ->assertOk();
        $lostImpressionEvent = (string) Str::uuid();

        $this->withHeaders(array_merge($this->clientHeaders(), ['Device-Token' => 'second-device']))
            ->postJson('/api/v4/ads/events', [
            'tracking_token' => $served->json('data.tracking_token'),
            'event_id' => (string) Str::uuid(),
            'event' => 'click',
            'impression_event_id' => $lostImpressionEvent,
        ])->assertOk()
            ->assertJsonPath('data.recorded', true)
            ->assertJsonPath('data.billable', true)
            ->assertJsonPath('data.impression_recovered', true);

        $this->assertDatabaseHas('ad_impressions', [
            'event_id' => $lostImpressionEvent,
            'campaign_id' => $approved->campaign->id,
        ]);
        $this->assertDatabaseCount('ad_clicks', 2);
        $campaign = $approved->campaign->fresh();
        $this->assertSame(2, $campaign->consumed_quantity);
        $this->assertSame('completed', $campaign->status);
    }

    public function test_final_cpc_click_completes_when_the_legacy_ads_schema_has_no_campaign_column(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-CPC-LEGACY-SCHEMA',
            'billing_model' => 'CPC',
            'target_quantity' => 1,
            'quoted_rate' => 4,
            'quoted_amount' => 100,
        ]);
        [$trackingToken, $impressionEvent] = $this->serveAndTrackImpression($approved->campaign->id);

        Schema::table('ads', fn (Blueprint $table) => $table->dropIndex(['campaign_id']));
        Schema::table('ads', fn (Blueprint $table) => $table->dropColumn('campaign_id'));

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $trackingToken,
            'event_id' => (string) Str::uuid(),
            'event' => 'click',
            'impression_event_id' => $impressionEvent,
        ])->assertOk()
            ->assertJsonPath('data.recorded', true)
            ->assertJsonPath('data.billable', true);

        $campaign = $approved->campaign->fresh();
        $this->assertSame(1, $campaign->consumed_quantity);
        $this->assertSame('completed', $campaign->status);
        $this->assertDatabaseCount('ad_billing_events', 1);
    }

    public function test_flat_campaign_expires_when_the_legacy_ads_schema_has_no_campaign_column(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-FLAT-LEGACY-SCHEMA',
            'billing_model' => 'FLAT',
            'target_quantity' => null,
            'quoted_rate' => 500,
            'quoted_amount' => 500,
            'requested_period_days' => 1,
        ]);
        $approved->campaign->update(['end_at' => now()->subMinute()]);

        Schema::table('ads', fn (Blueprint $table) => $table->dropIndex(['campaign_id']));
        Schema::table('ads', fn (Blueprint $table) => $table->dropColumn('campaign_id'));

        $this->artisan('ads:maintain-campaigns')
            ->assertSuccessful();

        $campaign = $approved->campaign->fresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertNotNull($campaign->completed_at);
    }

    public function test_final_cpv_view_reaches_its_exact_target(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-CPV-EXACT-TARGET',
            'billing_model' => 'CPC',
            'target_quantity' => 1,
            'quoted_rate' => 2,
            'quoted_amount' => 100,
        ]);
        $campaign = $approved->campaign;
        $campaign->update(['billing_model' => 'CPV']);
        $campaign->creatives()->update(['type' => 'video', 'skip_after_seconds' => 5]);
        [$trackingToken, $impressionEvent] = $this->serveAndTrackImpression($campaign->id);

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $trackingToken,
            'event_id' => (string) Str::uuid(),
            'event' => 'video_complete',
            'impression_event_id' => $impressionEvent,
            'watched_seconds' => 5,
        ])->assertOk()->assertJsonPath('data.recorded', true);

        $campaign->refresh();
        $this->assertSame(1, $campaign->consumed_quantity);
        $this->assertSame('completed', $campaign->status);
        $this->assertDatabaseHas('ad_billing_events', [
            'campaign_id' => $campaign->id,
            'event_type' => 'video_view',
        ]);
    }

    public function test_cpm_continues_serving_until_an_impression_is_confirmed(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-CPM-DELIVERY',
            'billing_model' => 'CPM',
            'target_quantity' => 1,
            'quoted_rate' => 120,
            'quoted_amount' => 100,
        ]);

        $first = $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ads/serve?placement=home_top&platform=web')
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $approved->campaign->id);
        $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ads/serve?placement=home_top&platform=web')
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $approved->campaign->id);

        $this->assertSame(2, $approved->campaign->fresh()->served_quantity);
        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $first->json('data.tracking_token'),
            'event_id' => (string) Str::uuid(),
            'event' => 'impression',
        ])->assertOk();

        $campaign = $approved->campaign->fresh();
        $this->assertSame(1, $campaign->consumed_quantity);
        $this->assertSame('completed', $campaign->status);
        $this->assertDatabaseHas('ad_billing_events', [
            'campaign_id' => $campaign->id,
            'event_type' => 'impression',
            'amount' => 0.12,
        ]);
    }

    public function test_non_prepaid_rate_activates_immediately_with_an_open_invoice(): void
    {
        $slotId = \App\Models\AdPlacementSlot::where('code', 'home_top')->value('id');
        \App\Models\AdBillingRate::where('placement_slot_id', $slotId)
            ->where('billing_model', 'CPC')
            ->update(['requires_prepayment' => false]);

        $submission = $this->makeImageSubmission([
            'reference_no' => 'ADS-NO-PREPAY',
            'billing_model' => 'CPC',
            'target_quantity' => 10,
            'quoted_rate' => 4,
            'quoted_amount' => 100,
        ]);
        $approved = app(AdApprovalService::class)->approve($submission, [], 'admin-uid');

        $this->assertFalse($approved->campaign->requires_prepayment);
        $this->assertSame('active', $approved->campaign->status);
        $this->assertSame('pending', $approved->campaign->invoices->first()->status);
        $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ads/serve?placement=home_top&platform=web')
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $approved->campaign->id);
    }

    public function test_daily_budget_pause_resumes_on_the_next_day(): void
    {
        $approved = $this->activateImageCampaign([
            'reference_no' => 'ADS-DAILY-BUDGET',
            'billing_model' => 'CPC',
            'target_quantity' => 3,
            'quoted_rate' => 4,
            'quoted_amount' => 100,
            'daily_budget' => 4,
        ]);
        [$trackingToken, $impressionEvent] = $this->serveAndTrackImpression($approved->campaign->id);

        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $trackingToken,
            'event_id' => (string) Str::uuid(),
            'event' => 'click',
            'impression_event_id' => $impressionEvent,
        ])->assertOk();

        $campaign = $approved->campaign->fresh();
        $this->assertSame('paused', $campaign->status);
        $this->assertSame('daily_budget', $campaign->pause_reason);
        $this->assertNotNull($campaign->resume_at);

        $this->travelTo($campaign->resume_at->copy()->addMinute(), function () use ($campaign) {
            $this->artisan('ads:maintain-campaigns')->assertSuccessful();
            $resumed = $campaign->fresh();
            $this->assertSame('active', $resumed->status);
            $this->assertNull($resumed->pause_reason);
            $this->assertNull($resumed->resume_at);

            $resumed->update(['status' => 'paused', 'pause_reason' => 'manual']);
            $this->travel(1)->day();
            $this->artisan('ads:maintain-campaigns')->assertSuccessful();
            $this->assertSame('paused', $resumed->fresh()->status);
        });
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

    private function makeImageSubmission(array $overrides = []): AdSubmission
    {
        return AdSubmission::create(array_merge([
            'reference_no' => 'ADS-'.Str::upper(Str::random(12)),
            'public_token_hash' => hash('sha256', Str::random(48)),
            'status' => AdSubmission::STATUS_PENDING,
            'business_name' => 'Billing Test Store',
            'contact_name' => 'Advertiser',
            'contact_phone' => '9876543210',
            'ads_name' => 'Billing campaign',
            'type' => 'image',
            'placement_code' => 'home_top',
            'billing_model' => 'CPC',
            'target_quantity' => 10,
            'quoted_rate' => 4,
            'quoted_amount' => 100,
            'currency' => 'INR',
            'media_url' => 'https://cdn.example.com/banner.webp',
            'destination_url' => 'https://example.com',
            'requested_period_days' => 14,
        ], $overrides));
    }

    private function activateImageCampaign(array $overrides = []): AdSubmission
    {
        $approved = app(AdApprovalService::class)->approve(
            $this->makeImageSubmission($overrides),
            [],
            'admin-uid',
        );
        app(AdBillingService::class)->markPaid($approved->campaign->invoices->first(), [
            'amount' => (float) $approved->campaign->invoices->first()->total,
            'payment_method' => 'manual',
            'gateway' => 'manual',
            'gateway_order_id' => 'UTR-'.Str::upper(Str::random(12)),
        ]);

        return $approved->fresh(['campaign.invoices']);
    }

    private function serveAndTrackImpression(int $campaignId): array
    {
        $served = $this->withHeaders($this->clientHeaders())
            ->getJson('/api/v4/ads/serve?placement=home_top&platform=web')
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $campaignId);
        $impressionEvent = (string) Str::uuid();
        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ads/events', [
            'tracking_token' => $served->json('data.tracking_token'),
            'event_id' => $impressionEvent,
            'event' => 'impression',
        ])->assertOk();

        return [$served->json('data.tracking_token'), $impressionEvent];
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
