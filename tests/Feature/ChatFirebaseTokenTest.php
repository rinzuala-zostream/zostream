<?php

namespace Tests\Feature;

use App\Models\SessionTokenModel;
use App\Support\Chat\ChatAuthorizationProjector;
use App\Support\Chat\ChatFirebase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ChatFirebaseTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::connection('sqlite')->create('session_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id');
            $table->string('access_token');
            $table->string('refresh_token')->nullable();
            $table->timestamp('access_expires_at');
            $table->timestamp('refresh_expires_at')->nullable();
            $table->string('device_name')->nullable();
            $table->string('device_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_firebase_identity_mapping_is_stable_and_does_not_embed_the_zo_stream_id(): void
    {
        config(['chat.firebase.uid_key' => 'fixture-stable-firebase-chat-key']);
        $uid = ChatFirebase::uid('zo-user-123');

        $this->assertSame($uid, ChatFirebase::uid('zo-user-123'));
        $this->assertSame('zs_'.hash_hmac('sha256', 'zo-user-123', 'fixture-stable-firebase-chat-key'), $uid);
        $this->assertStringNotContainsString('zo-user-123', $uid);
    }

    public function test_room_authorization_leases_respect_session_and_playback_expiry(): void
    {
        $now = now();
        $sessionExpiry = $now->copy()->addMinutes(10);

        $this->assertSame($now->copy()->addSeconds(120)->getTimestamp(), ChatAuthorizationProjector::leaseExpiry($now, $sessionExpiry, 'global', 120));
        $this->assertSame($now->copy()->addSeconds(90)->getTimestamp(), ChatAuthorizationProjector::leaseExpiry($now, $sessionExpiry, 'movie', 120));
        $this->assertSame($now->copy()->addSeconds(20)->getTimestamp(), ChatAuthorizationProjector::leaseExpiry($now, $now->copy()->addSeconds(20), 'global', 120));
    }

    public function test_firebase_token_endpoint_requires_the_existing_zo_stream_session(): void
    {
        $this->postJson('/api/v4/chat/firebase/token', [], ['X-Client-Platform' => 'android'])
            ->assertUnauthorized();
    }

    public function test_firebase_token_endpoint_rejects_excluded_platforms_before_firebase_access(): void
    {
        $token = $this->createSession('web-user');

        $this->postJson('/api/v4/chat/firebase/token', [], [
            'Authorization' => 'Bearer '.$token,
            'X-Client-Platform' => 'web',
        ])->assertForbidden();
    }

    public function test_firebase_token_endpoint_is_disabled_until_the_controlled_cutover(): void
    {
        config(['chat.firebase.enabled' => false]);
        $token = $this->createSession('mobile-user');

        $this->postJson('/api/v4/chat/firebase/token', [], [
            'Authorization' => 'Bearer '.$token,
            'X-Client-Platform' => 'android',
        ])->assertStatus(503);
    }

    public function test_firebase_lease_renewal_requires_an_authenticated_supported_client(): void
    {
        $this->postJson('/api/v4/chat/firebase/lease', [], ['X-Client-Platform' => 'android'])->assertUnauthorized();
        $token = $this->createSession('lease-user');
        $this->postJson('/api/v4/chat/firebase/lease', [], [
            'Authorization' => 'Bearer '.$token,
            'X-Client-Platform' => 'android',
        ])->assertStatus(503);
    }

    private function createSession(string $userId): string
    {
        $token = $userId.'-access-token';
        SessionTokenModel::query()->create([
            'user_id' => $userId,
            'access_token' => SessionTokenModel::digest($token),
            'refresh_token' => SessionTokenModel::digest($userId.'-refresh-token'),
            'access_expires_at' => now()->addHour(),
            'refresh_expires_at' => now()->addDays(30),
            'device_name' => 'Test device',
            'device_id' => $userId.'-device',
        ]);

        return $token;
    }
}
