<?php

namespace Tests\Feature;

use App\Models\AdSubmission;
use App\Services\AdApprovalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdSubmissionWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ad_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();
            $table->char('public_token_hash', 64)->unique();
            $table->string('status')->index();
            $table->string('business_name');
            $table->string('contact_name');
            $table->string('contact_phone');
            $table->string('contact_email')->nullable();
            $table->string('ads_name');
            $table->text('description')->nullable();
            $table->string('type');
            $table->text('media_url')->nullable();
            $table->text('destination_url')->nullable();
            $table->date('requested_start_date')->nullable();
            $table->unsignedInteger('requested_period_days');
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('approved_ad_num')->nullable();
            $table->char('submitted_ip_hash', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('ad_submission_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_submission_id');
            $table->string('kind');
            $table->text('file_url');
            $table->text('storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('ad_submission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_submission_id');
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('ads', function (Blueprint $table) {
            $table->increments('num');
            $table->string('ads_name');
            $table->string('create_date');
            $table->text('description')->nullable();
            $table->integer('period');
            $table->string('type');
            $table->text('video_url')->nullable();
            $table->text('ads_url')->nullable();
            $table->text('target_url')->nullable();
            $table->text('feature_img')->nullable();
            $table->text('img1')->nullable();
            $table->text('img2')->nullable();
            $table->text('img3')->nullable();
            $table->text('img4')->nullable();
        });
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

    public function test_approval_creates_the_live_ad_once(): void
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
            'media_url' => 'https://cdn.example.com/ad.mp4',
            'destination_url' => 'https://example.com',
            'requested_period_days' => 14,
        ]);

        $approved = app(AdApprovalService::class)->approve($submission, [], 'admin-uid');

        $this->assertSame(AdSubmission::STATUS_APPROVED, $approved->status);
        $this->assertNotNull($approved->approved_ad_num);
        $this->assertDatabaseHas('ads', [
            'num' => $approved->approved_ad_num,
            'ads_name' => 'Video campaign',
            'video_url' => 'https://cdn.example.com/ad.mp4',
            'target_url' => 'https://example.com',
        ]);
        $this->assertDatabaseHas('ad_submission_events', [
            'ad_submission_id' => $submission->id,
            'action' => 'approved',
            'actor_id' => 'admin-uid',
        ]);
    }

    public function test_video_submission_requires_video_media(): void
    {
        $this->withHeaders($this->clientHeaders())->postJson('/api/v4/ad-submissions', [
            'business_name' => 'Example Store',
            'contact_name' => 'Lalruata',
            'contact_phone' => '9876543210',
            'ads_name' => 'Video campaign',
            'type' => 'video',
            'requested_period_days' => 14,
            'terms_accepted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('media', 'error.details');
    }

    public function test_admin_submission_routes_are_admin_protected(): void
    {
        foreach ([
            ['GET', 'api/v4/admin/ad-submissions'],
            ['GET', 'api/v4/admin/ad-submissions/{adSubmission}'],
            ['POST', 'api/v4/admin/ad-submissions/{adSubmission}/approve'],
            ['POST', 'api/v4/admin/ad-submissions/{adSubmission}/reject'],
            ['POST', 'api/v4/admin/ad-submissions/{adSubmission}/request-changes'],
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
