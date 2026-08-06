<?php

namespace Tests\Feature;

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
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('whatsapp_testing');
        config(['database.default' => $this->originalConnection]);
        parent::tearDown();
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
        ]);
        Http::assertSentCount(1);
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
        ]);
        Http::fake([
            'https://graph.facebook.com/v22.0/phone-id/messages' => Http::response([
                'messages' => [['id' => 'wamid.reply']],
            ]),
        ]);

        $message = app(WhatsAppCloudService::class)->sendText('91 98765 43210', 'Reply');

        $this->assertSame('919876543210', $message->contact_phone);
        $this->assertSame('wamid.reply', $message->wamid);
    }
}
