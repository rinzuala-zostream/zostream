<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V4\AdminWhatsAppInboxController;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppCloudService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'whatsapp_testing',
            'database.connections.whatsapp_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('whatsapp_testing');
        DB::reconnect('whatsapp_testing');

        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('verify_token')->nullable();
            $table->text('app_secret')->nullable();
            $table->string('api_version')->default('v22.0');
            $table->boolean('enabled')->default(false);
            $table->boolean('auto_reply_enabled')->default(false);
            $table->text('auto_reply_message')->nullable();
            $table->timestamps();
        });
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wamid')->nullable()->unique();
            $table->string('contact_phone');
            $table->string('contact_name')->nullable();
            $table->string('direction');
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->string('status')->default('received');
            $table->string('reply_to_wamid')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('message_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('whatsapp_testing');
        config(['database.default' => $this->originalConnection]);
        parent::tearDown();
    }

    public function test_provider_error_code_is_preserved_for_invitation_delivery_diagnostics(): void
    {
        config(['app.whatsapp_phone_id' => 'phone-id', 'app.whatsapp_token' => 'access-token']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Template unavailable', 'code' => 132001]], 400)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(132001);
        app(WhatsAppCloudService::class)->sendTemplate('919876543210', 'zostream_room_invitation');
    }

    public function test_meta_can_verify_the_webhook(): void
    {
        WhatsAppSetting::current()->update(['verify_token' => 'verify-me']);

        $this->get('/api/v4/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
            ->assertOk()
            ->assertSeeText('12345');
    }

    public function test_signed_inbound_message_is_stored_and_auto_replied(): void
    {
        config([
            'app.whatsapp_phone_id' => 'phone-id',
            'app.whatsapp_token' => 'access-token',
            'services.whatsapp.app_secret' => 'app-secret',
            'services.whatsapp.api_version' => 'v22.0',
        ]);
        WhatsAppSetting::current()->update([
            'auto_reply_enabled' => true,
            'auto_reply_message' => 'Kan lo dawng e.',
        ]);
        Http::fake([
            'https://graph.facebook.com/v22.0/phone-id/messages' => Http::response([
                'messages' => [['id' => 'wamid.outbound']],
            ]),
        ]);
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => '919876543210', 'profile' => ['name' => 'Test User']]],
                        'messages' => [[
                            'from' => '919876543210',
                            'id' => 'wamid.inbound',
                            'timestamp' => '1770000000',
                            'type' => 'text',
                            'text' => ['body' => 'Hello'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $json, 'app-secret');

        $this->call('POST', '/api/v4/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $json)->assertOk()->assertSeeText('EVENT_RECEIVED');

        $this->assertDatabaseHas('whatsapp_messages', [
            'wamid' => 'wamid.inbound',
            'direction' => 'inbound',
            'body' => 'Hello',
        ]);
        $this->assertDatabaseHas('whatsapp_messages', [
            'wamid' => 'wamid.outbound',
            'direction' => 'outbound',
            'body' => 'Kan lo dawng e.',
            'reply_to_wamid' => null,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ! isset($request->data()['context']));
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        config(['services.whatsapp.app_secret' => 'app-secret']);

        $this->withHeader('X-Hub-Signature-256', 'sha256=wrong')
            ->postJson('/api/v4/webhooks/whatsapp', ['entry' => []])
            ->assertUnauthorized();
    }

    public function test_cloud_service_records_an_outbound_reply(): void
    {
        config([
            'app.whatsapp_phone_id' => 'phone-id',
            'app.whatsapp_token' => 'access-token',
            'services.whatsapp.api_version' => 'v22.0',
        ]);
        Http::fake([
            'https://graph.facebook.com/v22.0/phone-id/messages' => Http::response([
                'messages' => [['id' => 'wamid.reply']],
            ]),
        ]);

        $message = app(WhatsAppCloudService::class)->sendText('91 98765 43210', 'Reply');

        $this->assertSame('919876543210', $message->contact_phone);
        $this->assertSame('wamid.reply', $message->wamid);
        Http::assertSent(fn ($request) => ! isset($request->data()['context']));
    }

    public function test_cloud_service_downloads_inbound_media(): void
    {
        config([
            'app.whatsapp_token' => 'access-token',
            'services.whatsapp.api_version' => 'v22.0',
        ]);
        Http::fake([
            'https://graph.facebook.com/v22.0/media-123' => Http::response([
                'url' => 'https://lookaside.example/media-123',
                'mime_type' => 'image/jpeg',
            ]),
            'https://lookaside.example/media-123' => Http::response('image-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $media = app(WhatsAppCloudService::class)->downloadMedia('media-123');

        $this->assertSame('image-bytes', $media['content']);
        $this->assertSame('image/jpeg', $media['mime_type']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function test_admin_inbox_returns_every_conversation_with_unread_counts(): void
    {
        foreach (range(1, 55) as $index) {
            WhatsAppMessage::create([
                'wamid' => "wamid.conversation.{$index}",
                'contact_phone' => '91900000'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'contact_name' => "Customer {$index}",
                'direction' => 'inbound',
                'type' => 'text',
                'body' => "Message {$index}",
                'status' => 'received',
                'message_at' => now()->addSeconds($index),
            ]);
        }

        $response = app(AdminWhatsAppInboxController::class)->conversations(request());
        $conversations = $response->getData(true);

        $this->assertCount(55, $conversations);
        $this->assertSame(1, $conversations[0]['unread_count']);
    }

    public function test_admin_inbox_keeps_latest_preview_and_latest_non_empty_name(): void
    {
        WhatsAppMessage::create([
            'wamid' => 'wamid.old',
            'contact_phone' => '919333333333',
            'contact_name' => 'Earlier name',
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Earlier message',
            'status' => 'received',
            'message_at' => now()->subMinute(),
        ]);
        WhatsAppMessage::create([
            'wamid' => 'wamid.latest',
            'contact_phone' => '919333333333',
            'contact_name' => null,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Latest reply',
            'status' => 'sent',
            'message_at' => now(),
        ]);

        $response = app(AdminWhatsAppInboxController::class)->conversations(request());
        $conversation = collect($response->getData(true))->firstWhere('phone', '919333333333');

        $this->assertSame('Earlier name', $conversation['name']);
        $this->assertSame('Latest reply', $conversation['last_message']);
        $this->assertSame('outbound', $conversation['direction']);
        $this->assertSame('sent', $conversation['status']);
        $this->assertSame(2, $conversation['message_count']);
        $this->assertSame(1, $conversation['unread_count']);
    }

    public function test_conversation_summary_reads_one_row_per_contact_in_one_query(): void
    {
        foreach (range(1, 300) as $index) {
            WhatsAppMessage::create([
                'wamid' => "wamid.perf.{$index}",
                'contact_phone' => '91940000'.str_pad((string) (intdiv($index - 1, 30) + 1), 4, '0', STR_PAD_LEFT),
                'direction' => $index % 2 === 0 ? 'outbound' : 'inbound',
                'type' => 'text',
                'body' => "Message {$index}",
                'status' => 'sent',
                'message_at' => now()->addSeconds($index),
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queries[] = ['sql' => $query->sql, 'time_ms' => $query->time];
            }
        });

        $response = app(AdminWhatsAppInboxController::class)->conversations(request());
        $responseBytes = strlen($response->getContent());

        $this->assertCount(10, $response->getData(true));
        $this->assertCount(1, $queries);
        $this->assertSame(10, count($response->getData(true)));
        $this->assertGreaterThanOrEqual(0, $queries[0]['time_ms']);
        $this->assertLessThan(10_000, $responseBytes);
        $this->assertStringNotContainsString('latest.reply_to_wamid', strtolower($queries[0]['sql']));
    }

    public function test_message_history_pagination_is_opt_in_and_keeps_chronological_array_contract(): void
    {
        foreach (range(1, 5) as $index) {
            WhatsAppMessage::create([
                'wamid' => "wamid.history.{$index}",
                'contact_phone' => '919555555555',
                'direction' => 'inbound',
                'type' => 'text',
                'body' => "Message {$index}",
                'status' => 'received',
                // Same-second timestamps exercise the ID tie-break cursor.
                'message_at' => now()->startOfSecond(),
            ]);
        }

        $controller = app(AdminWhatsAppInboxController::class);
        $legacy = $controller->messages(request(), '919555555555');
        $this->assertCount(5, $legacy->getData(true));
        $this->assertNull($legacy->headers->get('X-Has-More'));

        $first = $controller->messages(\Illuminate\Http\Request::create('/?limit=2', 'GET'), '919555555555');
        $firstPage = $first->getData(true);
        $this->assertSame(['Message 4', 'Message 5'], array_column($firstPage, 'body'));
        $this->assertSame('true', $first->headers->get('X-Has-More'));
        $this->assertSame((string) $firstPage[0]['id'], $first->headers->get('X-Next-Before-Id'));

        $next = $controller->messages(\Illuminate\Http\Request::create('/?limit=2', 'GET', [
            'limit' => 2,
            'before_at' => $first->headers->get('X-Next-Before-At'),
            'before_id' => $first->headers->get('X-Next-Before-Id'),
        ]), '919555555555');
        $this->assertSame(['Message 2', 'Message 3'], array_column($next->getData(true), 'body'));
    }

    public function test_opening_a_conversation_marks_only_its_inbound_messages_read(): void
    {
        foreach (['919111111111', '919222222222'] as $phone) {
            WhatsAppMessage::create([
                'wamid' => "wamid.{$phone}",
                'contact_phone' => $phone,
                'direction' => 'inbound',
                'type' => 'text',
                'body' => 'Unread',
                'status' => 'received',
                'message_at' => now(),
            ]);
        }

        $response = app(AdminWhatsAppInboxController::class)->markRead('919111111111');

        $this->assertSame(1, $response->getData(true)['read_count']);
        $this->assertNotNull(WhatsAppMessage::where('contact_phone', '919111111111')->value('read_at'));
        $this->assertNull(WhatsAppMessage::where('contact_phone', '919222222222')->value('read_at'));
    }
}
