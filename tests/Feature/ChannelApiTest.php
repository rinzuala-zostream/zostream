<?php

namespace Tests\Feature;

use App\Models\Channel\Channel;
use App\Models\Channel\ChannelContent;
use App\Models\Channel\ChannelContentMedia;
use App\Models\Channel\ChannelSubscriber;
use App\Models\Channel\ChannelSubscriptionPlan;
use App\Models\SessionTokenModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChannelApiTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
        config([
            'database.default' => 'channel_testing',
            'database.connections.channel_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'services.admin_qr.allowed_uids' => [],
            'services.admin_whatsapp.allowed_numbers' => [],
        ]);
        DB::purge('channel_testing');
        Schema::create('user', function (Blueprint $table) {
            $table->increments('num');
            $table->string('uid')->unique();
            $table->string('name');
            $table->string('img')->nullable();
            $table->string('auth_phone')->nullable();
            $table->string('token')->nullable();
            $table->string('mail')->nullable();
        });
        foreach (['admin', 'owner', 'viewer', 'other'] as $uid) {
            DB::table('user')->insert(['uid' => $uid, 'name' => $uid, 'token' => 'secret-'.$uid, 'mail' => $uid.'@example.test']);
        }
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('admin_uid');
        });
        DB::table('admin_users')->insert(['admin_uid' => 'admin']);
        Schema::create('session_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('access_token');
            $table->string('device_id');
            $table->dateTime('access_expires_at');
            $table->timestamps();
        });
        Schema::create('n_devices', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('device_token');
            $table->boolean('is_owner_device');
            $table->string('status');
        });
        (require database_path('migrations/2026_01_01_000001_create_channel_tables.php'))->up();
        (require database_path('migrations/2026_11_16_000002_create_channel_uploads_table.php'))->up();
        \Illuminate\Support\Facades\Storage::fake('channel_uploads');
        \Illuminate\Support\Facades\Storage::fake('r2');
        config(['channel_uploads.chunk_bytes' => 16, 'filesystems.disks.r2.url' => 'https://artwork.example.test']);
    }

    protected function tearDown(): void
    {
        DB::purge('channel_testing');
        config(['database.default' => $this->originalConnection]);
        parent::tearDown();
    }

    public function test_public_discovery_hides_non_active_channels_even_with_status_filters(): void
    {
        $active = $this->channel();
        foreach (['pending', 'suspended', 'deleted'] as $status) {
            $hidden = $this->channel($status);
            foreach (['/api/v4/channels/', '/api/v3.0/channels/'] as $prefix) {
                $this->getJson($prefix.$hidden->id)->assertNotFound();
                $this->getJson($prefix.$hidden->id.'/plans')->assertNotFound();
                $this->getJson($prefix.$hidden->id.'/contents')->assertNotFound();
            }
        }
        $this->getJson('/api/v4/channels?status=suspended&search=Test')
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $active->id)
            ->assertJsonMissingPath('data.data.0.user_id')
            ->assertJsonPath('meta.api_version', '4');
        $this->getJson('/api/v3.0/channels')->assertOk()
            ->assertJsonPath('status', 'success')->assertJsonPath('data.total', 1);
    }

    public function test_public_catalog_exposes_only_released_metadata_and_active_plans(): void
    {
        $channel = $this->channel();
        $plan = $this->plan($channel);
        $inactivePlan = $this->plan($channel, false);
        foreach (['free', 'subscriber_only', 'ppv'] as $access) {
            $video = $this->content($channel, ['access_type' => $access]);
            ChannelContentMedia::create(['content_id' => $video->id, 'media_type' => 'video', 'url' => 'https://private.example/source.mp4']);
        }
        $draft = $this->content($channel, ['status' => 'draft']);
        $private = $this->content($channel, ['status' => 'private']);
        $future = $this->content($channel, ['release_date' => now()->addDay()]);

        foreach (['/api/v4/channels/', '/api/v3.0/channels/'] as $prefix) {
            $response = $this->getJson($prefix.$channel->id)->assertOk()
                ->assertJsonCount(1, 'data.plans')->assertJsonCount(3, 'data.contents');
            $this->assertStringNotContainsString('private.example', $response->getContent());
            $response->assertJsonMissingPath('data.contents.0.media');
            $this->getJson($prefix.$channel->id.'/plans?is_active=0')
                ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $plan->id);
            $this->getJson($prefix.$channel->id.'/contents?status=draft')
                ->assertOk()->assertJsonPath('data.total', 3)->assertJsonMissingPath('data.data.0.media');
        }
        foreach ([$draft, $private, $future] as $hidden) {
            $this->getJson('/api/v4/channel-contents/'.$hidden->id)->assertNotFound();
            $this->getJson('/api/v3.0/channels/contents/'.$hidden->id)->assertNotFound();
        }
        $this->getJson('/api/v4/channel-plans/'.$inactivePlan->id)->assertNotFound();
        $this->getJson('/api/v3.0/channels/plans/'.$inactivePlan->id)->assertNotFound();
    }

    public function test_suspending_a_channel_removes_its_plan_and_content_detail_routes(): void
    {
        $channel = $this->channel();
        $video = $this->content($channel);
        $plan = $this->plan($channel);
        $this->getJson('/api/v4/channel-contents/'.$video->id)->assertOk();
        $this->getJson('/api/v4/channel-plans/'.$plan->id)->assertOk();
        $channel->update(['status' => 'suspended']);
        $this->getJson('/api/v4/channel-contents/'.$video->id)->assertNotFound();
        $this->getJson('/api/v4/channel-plans/'.$plan->id)->assertNotFound();
    }

    public function test_public_lists_are_bounded_and_filters_cannot_expose_private_data(): void
    {
        $channel = $this->channel();
        for ($i = 0; $i < 17; $i++) {
            $this->content($channel);
        }
        $this->getJson('/api/v4/channels/'.$channel->id)->assertOk()->assertJsonCount(15, 'data.contents');
        $this->getJson('/api/v4/channels/'.$channel->id.'/contents?per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.total', 17)->assertJsonCount(2, 'data.data');
        foreach (['per_page=101', 'per_page=bad', 'page=0'] as $query) {
            $this->getJson('/api/v4/channels?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
    }

    public function test_every_sensitive_legacy_channel_route_is_authenticated_and_admin_only(): void
    {
        $publicMethods = ['index', 'show', 'plans', 'showPlan', 'contents', 'showContent'];
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v3.0/channels'));
        foreach ($routes as $route) {
            if (in_array($route->getActionMethod(), $publicMethods, true)) {
                continue;
            }
            $this->assertContains('auth.token', $route->gatherMiddleware(), $route->uri());
            $this->assertContains('admin.token', $route->gatherMiddleware(), $route->uri());
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $method = $route->methods()[0];
            $this->json($method, '/'.$uri)->assertUnauthorized();
            $this->json($method, '/'.$uri, ['auth_user_id' => 'admin'], $this->headers('viewer'))
                ->assertForbidden();
        }
    }

    public function test_v4_management_requires_real_admin_authentication(): void
    {
        $channel = $this->channel();
        foreach (['/api/v4/admin/channels', '/api/v4/admin/channels/'.$channel->id,
            '/api/v4/admin/channels/'.$channel->id.'/subscribers'] as $path) {
            $this->getJson($path)->assertUnauthorized();
            $this->getJson($path, $this->headers('owner'))->assertForbidden();
            $this->getJson($path, $this->headers('admin'))->assertOk()->assertJsonPath('success', true);
        }
        $this->putJson('/api/v4/admin/channels/'.$channel->id, ['name' => 'Hijacked', 'auth_user_id' => 'admin'], $this->headers('other'))
            ->assertForbidden();
        $this->assertSame('Test Channel', $channel->fresh()->name);
    }

    public function test_admin_can_manage_channel_plans_contents_and_media_using_native_v4_responses(): void
    {
        $headers = $this->headers('admin');
        $created = $this->postJson('/api/v4/admin/channels', ['user_id' => 'owner', 'name' => 'New Channel', 'status' => 'active'], $headers)
            ->assertCreated()->assertJsonPath('data.name', 'New Channel')->assertJsonMissingPath('data.status.status');
        $id = $created->json('data.id');
        $this->putJson('/api/v4/admin/channels/'.$id, ['name' => 'Updated'], $headers)->assertOk()->assertJsonPath('data.name', 'Updated');
        $planId = $this->postJson('/api/v4/admin/channels/'.$id.'/plans', ['duration_days' => 30, 'price' => 88], $headers)
            ->assertCreated()->json('data.id');
        $this->getJson('/api/v4/admin/channel-plans/'.$planId, $headers)->assertOk();
        $videoId = $this->postJson('/api/v4/admin/channels/'.$id.'/contents', ['title' => 'Draft', 'content_type' => 'video', 'status' => 'draft'], $headers)
            ->assertCreated()->json('data.id');
        $mediaId = $this->postJson('/api/v4/admin/channel-contents/'.$videoId.'/media', ['media_type' => 'video', 'url' => 'https://private.example/source.mp4'], $headers)
            ->assertCreated()->json('data.id');
        $this->getJson('/api/v4/admin/channel-contents/'.$videoId, $headers)->assertOk()
            ->assertJsonPath('data.media.0.id', $mediaId);
        $this->putJson('/api/v4/admin/channel-contents/'.$videoId, ['status' => 'published'], $headers)->assertOk();
        $this->getJson('/api/v4/channel-contents/'.$videoId)->assertOk()->assertJsonMissingPath('data.media');
        $this->deleteJson('/api/v4/admin/channel-contents/'.$videoId, [], $headers)->assertOk();
        $this->assertDatabaseHas('channel_contents', ['id' => $videoId, 'status' => 'private']);
        $this->assertDatabaseHas('channel_content_media', ['id' => $mediaId]);
        $this->getJson('/api/v4/channel-contents/'.$videoId)->assertNotFound();
        $this->deleteJson('/api/v4/admin/channel-plans/'.$planId, [], $headers)->assertOk();
        $this->getJson('/api/v4/channel-plans/'.$planId)->assertNotFound();
    }

    public function test_unverified_membership_and_rental_requests_never_create_financial_records(): void
    {
        $channel = $this->channel();
        $plan = $this->plan($channel);
        $video = $this->content($channel);
        $body = ['user_id' => 'other', 'plan_id' => $plan->id, 'status' => 'success', 'transaction_id' => 'forged'];
        $this->postJson('/api/v4/channels/'.$channel->id.'/subscriptions', $body)->assertUnauthorized();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v4/channels/'.$channel->id.'/subscriptions', $body, $this->headers('viewer'))
                ->assertStatus(409)->assertJsonPath('error.code', 'CHANNEL_PAYMENT_NOT_AVAILABLE');
        }
        foreach (['/'.$channel->id.'/subscribe', '/contents/'.$video->id.'/rent'] as $path) {
            $this->postJson('/api/v3.0/channels'.$path, $body, $this->headers('admin'))
                ->assertStatus(409)->assertJsonPath('error.code', 'CHANNEL_PAYMENT_NOT_AVAILABLE');
        }
        foreach (['channel_subscribers', 'channel_subscription_history', 'channel_content_rentals', 'channel_content_rental_history'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_membership_reads_and_cancellation_use_only_token_identity_and_preserve_expiry_records(): void
    {
        $channel = $this->channel();
        $plan = $this->plan($channel);
        $membership = ChannelSubscriber::create(['channel_id' => $channel->id, 'plan_id' => $plan->id,
            'user_id' => 'viewer', 'status' => 'active', 'subscribed_at' => now()->subDay(), 'expires_at' => now()->addDays(5)]);
        $other = ChannelSubscriber::create(['channel_id' => $channel->id, 'plan_id' => $plan->id,
            'user_id' => 'other', 'status' => 'active', 'subscribed_at' => now()->subDay(), 'expires_at' => now()->addDays(10)]);
        $path = '/api/v4/channels/'.$channel->id;
        $this->getJson($path.'/subscription?user_id=other&auth_user_id=other', $this->headers('viewer'))
            ->assertOk()->assertJsonPath('data.membership.user_id', 'viewer')->assertJsonPath('data.has_access', true);
        $expires = $membership->expires_at->toDateTimeString();
        for ($i = 0; $i < 2; $i++) {
            $this->deleteJson($path.'/subscriptions', ['user_id' => 'other'], $this->headers('viewer'))
                ->assertOk()->assertJsonPath('data.status', 'cancelled');
        }
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame($expires, $membership->fresh()->expires_at->toDateTimeString());
        $this->getJson($path.'/subscription', $this->headers('viewer'))->assertOk()->assertJsonPath('data.has_access', false);
        $this->deleteJson('/api/v4/admin/channels/'.$channel->id.'/subscribers/viewer', [], $this->headers('admin'))->assertOk();
        $this->getJson($path.'/subscription', $this->headers('viewer'))->assertOk()->assertJsonPath('data.has_access', false);
    }

    public function test_inactive_expired_and_future_memberships_do_not_grant_access(): void
    {
        $channel = $this->channel();
        $plan = $this->plan($channel);
        $path = '/api/v4/channels/'.$channel->id.'/subscription';
        $this->getJson($path, $this->headers('viewer'))->assertOk()->assertJsonPath('data.membership', null)->assertJsonPath('data.has_access', false);
        $membership = ChannelSubscriber::create(['channel_id' => $channel->id, 'plan_id' => $plan->id,
            'user_id' => 'viewer', 'status' => 'active', 'subscribed_at' => now()->subDay(), 'expires_at' => now()->subSecond()]);
        $this->getJson($path, $this->headers('viewer'))->assertOk()->assertJsonPath('data.has_access', false);
        $membership->update(['subscribed_at' => now()->addDay(), 'expires_at' => now()->addDays(2)]);
        $this->getJson($path, $this->headers('viewer'))->assertOk()->assertJsonPath('data.has_access', false);
        $membership->update(['subscribed_at' => now()->subDay()]);
        $channel->update(['status' => 'suspended']);
        $this->getJson($path, $this->headers('viewer'))->assertOk()->assertJsonPath('data.has_access', false);
    }

    public function test_sensitive_subscriber_data_is_not_serialized_to_admin_lists(): void
    {
        $channel = $this->channel();
        $plan = $this->plan($channel);
        ChannelSubscriber::create(['channel_id' => $channel->id, 'plan_id' => $plan->id, 'user_id' => 'viewer', 'status' => 'active']);
        $this->getJson('/api/v4/admin/channels/'.$channel->id.'/subscribers', $this->headers('admin'))
            ->assertOk()->assertJsonPath('data.data.0.user.name', 'viewer')
            ->assertJsonMissingPath('data.data.0.user.token')->assertJsonMissingPath('data.data.0.user.mail');
    }

    public function test_creator_only_sees_assigned_channels_and_cannot_spoof_identity(): void
    {
        $own = $this->channel();
        $other = $this->channel();
        $other->update(['user_id' => 'other']);
        $this->getJson('/api/v4/creator/channels')->assertUnauthorized();
        $this->getJson('/api/v4/creator/channels?auth_user_id=other&user_id=other', $this->headers('owner'))
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $own->id);
        $this->getJson('/api/v4/creator/channels/'.$other->id, $this->headers('owner'))->assertNotFound();
        $this->getJson('/api/v4/creator/channels', $this->headers('viewer'))->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/v4/creator/channels', ['user_id' => 'viewer'], $this->headers('viewer'))->assertStatus(405);
    }

    public function test_creator_can_edit_profile_but_cannot_assign_ownership_or_admin_fields(): void
    {
        $channel = $this->channel();
        $path = '/api/v4/creator/channels/'.$channel->id;
        $headers = $this->headers('owner');
        $this->putJson($path, ['name' => 'Owner edit', 'description' => 'New profile'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Owner edit');
        foreach (['user_id' => 'other', 'status' => 'active', 'is_verified' => true] as $field => $value) {
            $this->putJson($path, [$field => $value], $headers)->assertUnprocessable();
        }
        $this->assertSame('owner', $channel->fresh()->user_id);
        $this->assertFalse($channel->fresh()->is_verified);
        $this->putJson($path, ['name' => 'Attack'], $this->headers('other'))->assertNotFound();
        $this->putJson('/api/v4/admin/channels/'.$channel->id, ['status' => 'active'], $headers)->assertForbidden();
    }

    public function test_creator_drafts_are_private_and_publication_and_media_cannot_be_self_authorized(): void
    {
        $channel = $this->channel();
        $path = '/api/v4/creator/channels/'.$channel->id.'/contents';
        $headers = $this->headers('owner');
        $created = $this->postJson($path, ['title' => 'Owner video'], $headers)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.access_type', 'subscriber_only');
        $id = $created->json('data.id');
        $this->getJson('/api/v4/channel-contents/'.$id)->assertNotFound();
        foreach (['status' => 'published', 'channel_id' => 99, 'url' => 'https://example.test/source.mp4', 'media' => [['url' => 'https://example.test/file.mpd']]] as $field => $value) {
            $this->putJson($path.'/'.$id, [$field => $value], $headers)->assertUnprocessable();
        }
        $this->postJson('/api/v4/admin/channel-contents/'.$id.'/media', ['url' => 'https://example.test/file.mpd'], $headers)->assertForbidden();
        $this->assertDatabaseHas('channel_contents', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('channel_content_media', 0);
    }

    public function test_creator_editing_published_content_requires_staff_review_and_cannot_edit_other_channel_content(): void
    {
        $channel = $this->channel();
        $video = $this->content($channel);
        $other = $this->channel();
        $other->update(['user_id' => 'other']);
        $otherVideo = $this->content($other);
        ChannelContentMedia::create(['content_id' => $video->id, 'url' => 'https://private.example/source.mp4']);
        $path = '/api/v4/creator/channels/'.$channel->id.'/contents';
        $headers = $this->headers('owner');
        $this->getJson($path, $headers)->assertOk()->assertJsonMissingPath('data.data.0.media');
        $this->putJson($path.'/'.$video->id, ['title' => 'Revised title'], $headers)->assertOk()->assertJsonPath('data.status', 'draft');
        $this->putJson($path.'/'.$otherVideo->id, ['title' => 'Attack'], $headers)->assertNotFound();
        $this->deleteJson($path.'/'.$otherVideo->id, [], $headers)->assertNotFound();
        $this->deleteJson($path.'/'.$video->id, [], $headers)->assertOk()->assertJsonPath('data.status', 'private');
        $this->assertDatabaseCount('channel_content_media', 1);
        $this->assertSame('published', $otherVideo->fresh()->status);
    }

    public function test_suspended_creator_is_read_only_and_deleted_channels_are_unavailable(): void
    {
        $channel = $this->channel('suspended');
        $video = $this->content($channel);
        $path = '/api/v4/creator/channels/'.$channel->id;
        $headers = $this->headers('owner');
        $this->getJson($path, $headers)->assertOk();
        $this->putJson($path, ['name' => 'Cannot edit'], $headers)->assertForbidden();
        $this->postJson($path.'/contents', ['title' => 'Cannot add'], $headers)->assertForbidden();
        $this->putJson($path.'/contents/'.$video->id, ['title' => 'Cannot edit'], $headers)->assertForbidden();
        $this->deleteJson($path.'/contents/'.$video->id, [], $headers)->assertForbidden();
        $channel->update(['status' => 'deleted']);
        $this->getJson($path, $headers)->assertNotFound();
        $this->getJson('/api/v4/creator/channels', $headers)->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_creator_summary_counts_only_current_members_and_plans_are_read_only(): void
    {
        $channel = $this->channel();
        $plan = $this->plan($channel);
        $this->content($channel);
        foreach (['owner', 'viewer', 'other'] as $uid) {
            ChannelSubscriber::create(['channel_id' => $channel->id, 'plan_id' => $plan->id, 'user_id' => $uid,
                'status' => 'active', 'subscribed_at' => $uid === 'other' ? now()->addDay() : now()->subDay(),
                'expires_at' => $uid === 'viewer' ? now()->subHour() : now()->addDays(2)]);
        }
        $path = '/api/v4/creator/channels/'.$channel->id;
        $headers = $this->headers('owner');
        $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.summary.active_members', 1)
            ->assertJsonPath('data.summary.published', 1)->assertJsonPath('data.summary.contents', 1)
            ->assertJsonMissingPath('data.subscribers');
        $this->getJson($path.'/plans', $headers)->assertOk()->assertJsonPath('data.data.0.price', '88.00');
        $this->postJson($path.'/plans', ['price' => 1], $headers)->assertStatus(405);
    }

    public function test_review_code_does_not_bypass_otp_for_a_normal_owner_account(): void
    {
        Schema::create('otp_requests', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('otp_code');
            $table->dateTime('expires_at');
            $table->timestamps();
        });
        DB::table('user')->where('uid', 'owner')->update(['auth_phone' => '9000000001']);
        $this->postJson('/api/v4/auth/otp/verify', ['user_id' => 'owner', 'otp' => '326416', 'device_type' => 'browser'])
            ->assertNotFound();
        $this->assertDatabaseCount('session_tokens', 0);
    }

    public function test_owner_uploads_private_mp4_in_chunks_and_only_admin_can_download(): void
    {
        $channel = $this->channel();
        $video = $this->content($channel);
        $bytes = $this->mp4Bytes();
        $base = '/api/v4/creator/channels/'.$channel->id.'/uploads';
        $headers = $this->headers('owner');
        $upload = $this->postJson($base, ['filename' => 'video.mp4', 'kind' => 'source', 'size' => strlen($bytes), 'content_id' => $video->id], $headers)->assertCreated()->json('data');
        $this->putChunks($base.'/'.$upload['id'], $bytes, $headers);
        $completed = $this->postJson($base.'/'.$upload['id'].'/complete', [], $headers)->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.url', null);
        $this->assertSame('draft', $video->fresh()->status);
        $this->assertDatabaseCount('channel_content_media', 0);
        $this->postJson($base.'/'.$upload['id'].'/complete', [], $headers)->assertOk();
        $this->assertDatabaseCount('channel_uploads', 1);
        $this->getJson($base.'/'.$upload['id'], $this->headers('other'))->assertNotFound();
        $this->getJson('/api/v4/channels/'.$channel->id.'/contents')->assertOk()->assertJsonPath('data.total', 0);
        $download = '/api/v4/admin/channels/'.$channel->id.'/uploads/'.$upload['id'].'/download';
        $this->getJson($download)->assertUnauthorized();
        $this->getJson($download, $headers)->assertForbidden();
        $response = $this->get($download, $this->headers('admin'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertNull($completed->json('data.source_object_key'));
    }

    public function test_uploads_reject_spoofed_types_and_incomplete_chunks_without_publishing(): void
    {
        $channel = $this->channel();
        $video = $this->content($channel);
        $base = '/api/v4/creator/channels/'.$channel->id.'/uploads';
        $headers = $this->headers('owner');
        $body = ['filename' => 'fake.mp4', 'kind' => 'source', 'size' => 16, 'content_id' => $video->id];
        $this->postJson($base, array_merge($body, ['filename' => '../fake.mp4']), $headers)->assertUnprocessable();
        $this->postJson($base, array_merge($body, ['filename' => 'fake.exe']), $headers)->assertUnprocessable();
        $id = $this->postJson($base, $body, $headers)->assertCreated()->json('data.id');
        $this->postJson($base.'/'.$id.'/complete', [], $headers)->assertUnprocessable();
        $this->post($base.'/'.$id.'/parts/0', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('chunk.bin', 'short')], $headers)->assertUnprocessable();
        $this->putChunks($base.'/'.$id, str_repeat('X', 16), $headers);
        $this->postJson($base.'/'.$id.'/complete', [], $headers)->assertUnprocessable();
        $this->assertSame('published', $video->fresh()->status);
        $this->assertDatabaseMissing('channel_uploads', ['status' => 'ready']);
    }

    public function test_upload_authorization_is_rechecked_after_suspension_and_chunk_retries_are_safe(): void
    {
        $channel = $this->channel();
        $video = $this->content($channel);
        $bytes = $this->mp4Bytes();
        $base = '/api/v4/creator/channels/'.$channel->id.'/uploads';
        $headers = $this->headers('owner');
        $id = $this->postJson($base, ['filename' => 'video.mp4', 'kind' => 'source', 'size' => strlen($bytes), 'content_id' => $video->id], $headers)->assertCreated()->json('data.id');
        $this->putChunks($base.'/'.$id, $bytes, $headers);
        $this->putChunks($base.'/'.$id, $bytes, $headers);
        $channel->update(['status' => 'suspended']);
        $this->postJson($base.'/'.$id.'/complete', [], $headers)->assertForbidden();
        $channel->update(['status' => 'active', 'user_id' => 'other']);
        $this->postJson($base.'/'.$id.'/complete', [], $headers)->assertNotFound();
        $this->assertSame('published', $video->fresh()->status);
    }

    public function test_artwork_is_validated_converted_to_webp_and_not_applied_until_profile_save(): void
    {
        $channel = $this->channel();
        $headers = $this->headers('owner');
        $file = \Illuminate\Http\UploadedFile::fake()->image('cover.png', 40, 40);
        $bytes = file_get_contents($file->getRealPath());
        config(['channel_uploads.chunk_bytes' => 1048576]);
        $base = '/api/v4/creator/channels/'.$channel->id.'/uploads';
        $id = $this->postJson($base, ['filename' => 'cover.png', 'kind' => 'artwork', 'size' => strlen($bytes)], $headers)->assertCreated()->json('data.id');
        $this->post($base.'/'.$id.'/parts/0', ['file' => $file], $headers)->assertOk();
        $response = $this->postJson($base.'/'.$id.'/complete', [], $headers)->assertOk();
        $url = $response->json('data.url');
        $this->assertStringStartsWith('https://artwork.example.test/channels/'.$channel->id.'/artwork/', $url);
        $this->assertStringEndsWith('.webp', $url);
        \Illuminate\Support\Facades\Storage::disk('r2')->assertExists('channels/'.$channel->id.'/artwork/'.$id.'.webp');
        $this->assertNull($channel->fresh()->logo);
        $this->putJson('/api/v4/creator/channels/'.$channel->id, ['logo' => $url], $headers)->assertOk();
        $this->assertSame($url, $channel->fresh()->logo);
    }

    public function test_upload_quota_cancel_and_expired_session_cleanup_preserve_completed_sources(): void
    {
        $channel = $this->channel();
        $video = $this->content($channel);
        $headers = $this->headers('owner');
        $base = '/api/v4/creator/channels/'.$channel->id.'/uploads';
        $body = ['filename' => 'video.mp4', 'kind' => 'source', 'size' => strlen($this->mp4Bytes()), 'content_id' => $video->id];
        config(['channel_uploads.max_pending_per_channel' => 1]);
        $id = $this->postJson($base, $body, $headers)->assertCreated()->json('data.id');
        $this->postJson($base, $body, $headers)->assertUnprocessable();
        $this->deleteJson($base.'/'.$id, [], $headers)->assertOk();
        $this->deleteJson($base.'/'.$id, [], $headers)->assertOk();
        $readyId = $this->postJson($base, $body, $headers)->assertCreated()->json('data.id');
        $this->putChunks($base.'/'.$readyId, $this->mp4Bytes(), $headers);
        $this->postJson($base.'/'.$readyId.'/complete', [], $headers)->assertOk();
        \App\Models\Channel\ChannelUpload::query()->update(['expires_at' => now()->subHour()]);
        $this->artisan('channel-uploads:prune')->assertExitCode(0);
        $this->assertDatabaseCount('channel_uploads', 1);
        \Illuminate\Support\Facades\Storage::disk('channel_uploads')->assertExists($channel->id.'/'.$readyId.'/source');
    }

    private function mp4Bytes(): string
    {
        return pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2';
    }

    private function putChunks(string $base, string $bytes, array $headers): void
    {
        $chunkSize = config('channel_uploads.chunk_bytes');
        foreach (str_split($bytes, $chunkSize) as $part => $chunk) {
            $this->post($base.'/parts/'.$part, ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('chunk.bin', $chunk)], $headers)->assertOk();
        }
    }

    private function headers(string $uid): array
    {
        SessionTokenModel::firstOrCreate(['access_token' => SessionTokenModel::digest('test-'.$uid)],
            ['user_id' => $uid, 'device_id' => 'device-'.$uid, 'access_expires_at' => now()->addHour()]);
        DB::table('n_devices')->updateOrInsert(['user_id' => $uid, 'device_token' => 'device-'.$uid], ['is_owner_device' => true, 'status' => 'active']);

        return ['Authorization' => 'Bearer test-'.$uid, 'Accept' => 'application/json'];
    }

    private function channel(string $status = 'active'): Channel
    {
        return Channel::create(['user_id' => 'owner', 'name' => 'Test Channel', 'slug' => 'channel-'.Channel::count(), 'status' => $status]);
    }

    private function plan(Channel $channel, bool $active = true): ChannelSubscriptionPlan
    {
        return ChannelSubscriptionPlan::create(['channel_id' => $channel->id, 'duration_days' => 30, 'price' => 88, 'is_active' => $active]);
    }

    private function content(Channel $channel, array $overrides = []): ChannelContent
    {
        return ChannelContent::create(array_merge(['channel_id' => $channel->id, 'title' => 'Video', 'content_type' => 'video', 'status' => 'published'], $overrides));
    }
}
