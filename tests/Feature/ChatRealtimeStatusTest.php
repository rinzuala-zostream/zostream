<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ChatRealtimeStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'realtime_testing', 'database.connections.realtime_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'chat.enabled' => true, 'chat.websocket_url' => 'wss://chat.example.test/app/public-key?protocol=7',
            'app.url' => 'https://api.example.test', 'broadcasting.connections.reverb.key' => 'public-key',
            'broadcasting.connections.reverb.secret' => 'never-print-this-secret',
            'broadcasting.connections.reverb.app_id' => 'chat',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'reverb.apps.apps.0.allowed_origins' => ['api.example.test']]);
        DB::purge('realtime_testing');
        Schema::create('chat_outbox', function (Blueprint $table) {
            $table->id();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at');
        });
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::purge('realtime_testing');
        parent::tearDown();
    }

    public function test_default_check_is_read_only_and_does_not_make_network_requests(): void
    {
        $this->assertSame(0, Artisan::call('chat:realtime-status'));
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('chat_outbox')->count());
        $output = Artisan::output();
        $this->assertStringNotContainsString('public-key', $output);
        $this->assertStringNotContainsString('never-print-this-secret', $output);
    }

    public function test_probe_checks_the_real_public_upgrade_and_native_origin_without_subscribing(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('https://chat.example.test/app/public-key?protocol=7', $request->url());
            $this->assertSame('https://api.example.test', $request->header('Origin')[0]);
            $this->assertSame('websocket', $request->header('Upgrade')[0]);
            $this->assertFalse($request->hasHeader('Authorization'));

            return Http::response('', 101, ['Upgrade' => 'websocket', 'Sec-WebSocket-Accept' => base64_encode(sha1(
                $request->header('Sec-WebSocket-Key')[0].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))]);
        });
        $this->assertSame(0, Artisan::call('chat:realtime-status', ['--probe' => true]));
        $this->assertStringContainsString('HTTP 101 (OK)', Artisan::output());
        Http::assertSentCount(1);
    }

    public function test_probe_reports_gateway_failure_and_invalid_upgrade_signatures(): void
    {
        $currentStatus = 500;
        Http::fake(function () use (&$currentStatus) {
            return Http::response('', $currentStatus, ['Upgrade' => 'websocket', 'Sec-WebSocket-Accept' => 'invalid']);
        });
        foreach ([500, 101] as $status) {
            $currentStatus = $status;
            $this->assertSame(1, Artisan::call('chat:realtime-status', ['--probe' => true]));
            $this->assertStringContainsString("HTTP $status (FAIL)", Artisan::output());
        }
    }

    public function test_key_mismatch_and_disallowed_origin_are_actionable_failures(): void
    {
        config(['chat.websocket_url' => 'wss://chat.example.test/app/wrong-key']);
        $this->assertSame(1, Artisan::call('chat:realtime-status', ['--probe' => true]));
        $this->assertStringContainsString('Fix the WSS URL/app key', Artisan::output());
        Http::assertNothingSent();
        config(['chat.websocket_url' => 'wss://chat.example.test/app/public-key?protocol=7']);
        $this->assertSame(1, Artisan::call('chat:realtime-status', ['--origin' => 'https://other-api.example.test']));
        $output = Artisan::output();
        $this->assertStringContainsString('Native API origin allowed', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_old_backlog_is_reported_without_draining_or_changing_events(): void
    {
        DB::table('chat_outbox')->insert(['created_at' => now()->subMinute()]);
        $this->assertSame(1, Artisan::call('chat:realtime-status'));
        $this->assertStringContainsString('Broadcasts are overdue', Artisan::output());
        $this->assertSame(1, DB::table('chat_outbox')->whereNull('delivered_at')->count());
    }
}
