<?php

namespace Tests\Feature;

use App\Models\SessionTokenModel;
use App\Support\Chat\ChatFirebase;
use App\Support\Chat\ChatFirebaseLiveDelivery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ChatFirebaseAdminEmulatorTest extends TestCase
{
    private ?string $credentialsPath = null;
    private ?\GuzzleHttp\Client $emulatorHttp = null;
    private string $emulatorNamespace = 'demo-zostream-chat-default-rtdb';
    private string $emulatorHost = '127.0.0.1:9000';

    protected function setUp(): void
    {
        parent::setUp();
        $host = getenv('FIREBASE_DATABASE_EMULATOR_HOST') ?: '';
        if ($host === '') { $this->markTestSkipped('FIREBASE_DATABASE_EMULATOR_HOST is required for Admin SDK integration tests.'); }
        $parts = parse_url('http://'.$host);
        if (! in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->fail('Refusing to run Firebase Admin tests against a non-loopback database host.');
        }
        $this->emulatorHost = $host;

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'chat.connection' => 'sqlite', 'chat.firebase.enabled' => true,
            'chat.firebase.client_access_enabled' => true, 'chat.firebase.emulator_admin_access' => true,
            'chat.firebase.uid_key' => 'emulator-only-chat-key',
            'firebase.database_url' => 'https://demo-zostream-chat-default-rtdb.firebaseio.com']);
        DB::purge('sqlite');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey);
        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'zs-firebase-test-');
        file_put_contents($this->credentialsPath, json_encode([
            'type' => 'service_account', 'project_id' => 'demo-zostream-chat',
            'private_key_id' => 'synthetic-emulator-key', 'private_key' => $privateKey,
            'client_email' => 'firebase-emulator@example.invalid', 'client_id' => '100000000000000000000',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));
        config(['firebase.credentials' => $this->credentialsPath]);
        $this->emulatorHttp = new \GuzzleHttp\Client(['base_uri' => 'http://'.$host]);

        Schema::connection('sqlite')->create('chat_rooms', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary(); $table->string('type'); $table->string('content_id')->nullable();
            $table->boolean('closed')->default(false); $table->string('room_key')->nullable();
        });
        Schema::connection('sqlite')->create('chat_bans', function (Blueprint $table): void {
            $table->unsignedBigInteger('room_id'); $table->string('user_id'); $table->string('kind'); $table->timestamp('expires_at')->nullable();
        });
        Schema::connection('sqlite')->create('chat_blocks', function (Blueprint $table): void {
            $table->string('user_id'); $table->string('blocked_user_id');
        });
        Schema::connection('sqlite')->create('chat_room_reads', function (Blueprint $table): void {
            $table->string('user_id'); $table->unsignedBigInteger('room_id'); $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->primary(['user_id', 'room_id']);
        });
        Schema::connection('sqlite')->create('chat_messages', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('room_id'); $table->string('user_id'); $table->string('request_id');
            $table->string('display_name'); $table->text('body')->nullable(); $table->boolean('spoiler')->default(false);
            $table->unsignedBigInteger('reply_to_id')->nullable(); $table->text('movie_suggestion')->nullable();
            $table->text('moment')->nullable(); $table->unsignedBigInteger('invite_room_id')->nullable(); $table->text('watch_invite')->nullable();
            $table->unsignedBigInteger('revision')->default(1); $table->timestamp('deleted_at')->nullable(); $table->timestamp('created_at');
        });
        Schema::connection('sqlite')->create('chat_pins', function (Blueprint $table): void {
            $table->unsignedBigInteger('room_id'); $table->unsignedBigInteger('message_id');
        });
        Schema::connection('sqlite')->create('chat_firebase_message_recipients', function (Blueprint $table): void {
            $table->string('firebase_uid'); $table->unsignedBigInteger('room_id'); $table->unsignedBigInteger('message_id');
            $table->timestamp('published_at'); $table->unique(['firebase_uid', 'room_id', 'message_id']);
        });
        Schema::connection('sqlite')->create('chat_firebase_event_recipients', function (Blueprint $table): void {
            $table->string('firebase_uid'); $table->unsignedBigInteger('room_id'); $table->unsignedBigInteger('cursor');
            $table->timestamp('published_at'); $table->unique(['firebase_uid', 'room_id', 'cursor']);
        });
        Schema::connection('sqlite')->create('chat_firebase_typing_records', function (Blueprint $table): void {
            $table->string('viewer_uid'); $table->unsignedBigInteger('room_id'); $table->string('typist_uid');
            $table->timestamp('expires_at'); $table->timestamps(); $table->primary(['viewer_uid', 'room_id', 'typist_uid']);
        });
        Schema::connection('sqlite')->create('chat_firebase_badge_recipients', function (Blueprint $table): void {
            $table->string('firebase_uid'); $table->unsignedBigInteger('outbox_id'); $table->timestamp('published_at');
            $table->primary(['firebase_uid', 'outbox_id']);
        });
        Schema::connection('sqlite')->create('session_tokens', function (Blueprint $table): void {
            $table->id(); $table->string('user_id'); $table->string('access_token'); $table->string('device_id');
            $table->timestamp('access_expires_at'); $table->string('refresh_token')->nullable(); $table->timestamp('refresh_expires_at')->nullable();
            $table->string('device_name')->nullable(); $table->timestamps();
        });
        DB::table('chat_rooms')->insert(['id' => 17, 'type' => 'global', 'content_id' => null, 'closed' => false, 'room_key' => 'global']);
        DB::table('chat_messages')->insert(['id' => 501, 'room_id' => 17, 'user_id' => 'sender', 'request_id' => 'stable-request-id',
            'display_name' => 'Sender', 'body' => 'accepted by SQL', 'spoiler' => false, 'revision' => 1,
            'created_at' => now(), 'deleted_at' => null]);

        foreach ([['viewer-a', 'device-a'], ['viewer-b', 'device-b']] as [$userId, $device]) {
            SessionTokenModel::query()->create(['user_id' => $userId, 'access_token' => SessionTokenModel::digest($userId.'-token'),
                'refresh_token' => SessionTokenModel::digest($userId.'-refresh'), 'access_expires_at' => now()->addHour(),
                'refresh_expires_at' => now()->addDays(30), 'device_name' => 'synthetic', 'device_id' => $device]);
        }
        $grants = [];
        foreach (DB::table('session_tokens')->get() as $session) {
            $uid = ChatFirebase::uid($session->user_id);
            $grants[$uid][$session->id] = ['can_read' => true, 'can_write' => true,
                'read_until' => now()->addMinutes(2)->getTimestampMs(), 'write_until' => now()->addMinutes(2)->getTimestampMs(), 'playback_hash' => ''];
        }
        $this->writePath('chat_v1', ['auth_sessions' => array_fill_keys(array_keys($grants), []), 'room_authz' => [17 => $grants]]);
    }

    protected function tearDown(): void
    {
        if ($this->credentialsPath && is_file($this->credentialsPath)) { @unlink($this->credentialsPath); }
        parent::tearDown();
    }

    public function test_sql_accepted_message_and_cursor_are_published_atomically_by_kreait_admin_sdk(): void
    {
        $outbox = (object) ['id' => 5001, 'room_id' => 17, 'kind' => 'feed_created', 'message_id' => 501,
            'firebase_cursor' => 1, 'recipient_user_id' => null];
        app(ChatFirebaseLiveDelivery::class)->deliver($outbox);
        foreach (['viewer-a', 'viewer-b'] as $userId) {
            $uid = ChatFirebase::uid($userId);
            $message = $this->readPath("chat_v1/user_messages/$uid/17/00000000000000000501");
            $event = $this->readPath("chat_v1/user_sync_events/$uid/17/00000000000000000001");
            $this->assertSame('accepted by SQL', $message['body']);
            $this->assertSame(501, (int) $message['id']);
            $this->assertSame('created', $event['kind']);
            $this->assertSame(1, (int) $event['cursor']);
            $this->assertSame(1, (int) $event['unread_delta']);
        }
    }

    public function test_blocked_recipient_is_filtered_and_tombstone_revision_is_current(): void
    {
        DB::table('chat_blocks')->insert(['user_id' => 'viewer-a', 'blocked_user_id' => 'sender']);
        app(ChatFirebaseLiveDelivery::class)->deliver((object) ['id' => 5002, 'room_id' => 17, 'kind' => 'feed_created', 'message_id' => 501,
            'firebase_cursor' => 1, 'recipient_user_id' => null]);
        $this->assertNull($this->readPath('chat_v1/user_messages/'.ChatFirebase::uid('viewer-a').'/17/00000000000000000501'));
        DB::table('chat_messages')->where('id', 501)->update(['body' => '', 'revision' => 2, 'deleted_at' => now()]);
        app(ChatFirebaseLiveDelivery::class)->deliver((object) ['id' => 5003, 'room_id' => 17, 'kind' => 'feed_deleted', 'message_id' => 501,
            'firebase_cursor' => 2, 'recipient_user_id' => null]);
        $tombstone = $this->readPath('chat_v1/user_messages/'.ChatFirebase::uid('viewer-b').'/17/00000000000000000501');
        $this->assertTrue($tombstone['deleted']);
        $this->assertSame(2, (int) $tombstone['revision']);
        $deleteEvent = $this->readPath('chat_v1/user_sync_events/'.ChatFirebase::uid('viewer-b').'/17/00000000000000000002');
        $this->assertSame(-1, (int) $deleteEvent['unread_delta']);
    }

    public function test_custom_token_projection_renewal_and_session_revocation_use_admin_sdk(): void
    {
        $session = SessionTokenModel::query()->where('user_id', 'viewer-a')->firstOrFail();
        $firebase = app(ChatFirebase::class);
        $issued = $firebase->issueToken($session);
        $this->assertSame(ChatFirebase::uid('viewer-a'), $issued['firebase_uid']);
        $this->assertTrue($this->readPath('chat_v1/auth_sessions/'.ChatFirebase::uid('viewer-a').'/'.$session->id.'/account_events_access'));
        $this->assertNull($this->readPath('chat_v1/room_authz/17/'.ChatFirebase::uid('viewer-a').'/'.$session->id),
            'Home/list account listeners must work before any room grant exists.');
        $this->assertGreaterThan(now()->getTimestamp(), $firebase->renewSession($session),
            'Account-only lease renewal must not require an active room or a new custom token.');
        $firebase->projectRoomAuthorization('viewer-a', $session->id,
            (object) ['id' => 17, 'type' => 'global', 'content_id' => null], true, now()->addMinutes(2)->getTimestamp());
        $grant = $this->readPath('chat_v1/room_authz/17/'.ChatFirebase::uid('viewer-a').'/'.$session->id);
        $this->assertTrue($grant['can_read']);
        $this->assertTrue($grant['can_write']);
        $renewed = $firebase->renewSession($session);
        $this->assertGreaterThan(now()->getTimestamp(), $renewed);
        $firebase->revokeSession('viewer-a', $session->id);
        $revoked = $this->readPath('chat_v1/auth_sessions/'.ChatFirebase::uid('viewer-a').'/'.$session->id);
        $this->assertFalse($revoked['active']);
        $this->assertFalse($revoked['client_access']);
        $this->assertFalse($revoked['account_events_access']);
    }

    public function test_typing_is_short_lived_recipient_scoped_filtered_and_removed_on_stop(): void
    {
        DB::table('chat_blocks')->insert(['user_id' => 'viewer-a', 'blocked_user_id' => 'sender']);
        $firebase = app(ChatFirebase::class);
        $firebase->publishTyping(17, 'sender', ['name' => 'Sender', 'is_admin' => false, 'expires_at' => now()->addSeconds(8)->getTimestamp()]);
        $this->assertSame(1, DB::table('chat_firebase_typing_records')->count());
        $pathA = 'chat_v1/user_typing/'.ChatFirebase::uid('viewer-a').'/17/'.ChatFirebase::uid('sender');
        $pathB = 'chat_v1/user_typing/'.ChatFirebase::uid('viewer-b').'/17/'.ChatFirebase::uid('sender');
        $this->assertNull($this->readPath($pathA));
        $record = $this->readPath($pathB);
        $this->assertSame('Sender', $record['name']);
        $this->assertLessThanOrEqual(now()->addSeconds(30)->getTimestampMs(), (int) $record['expires_at']);
        $firebase->publishTyping(17, 'sender', null);
        $this->assertSame(0, DB::table('chat_firebase_typing_records')->count());
        $this->assertNull($this->readPath($pathB));
    }

    public function test_account_badge_invalidation_reaches_users_without_room_leases_and_is_idempotent(): void
    {
        $this->emulatorHttp->delete('/chat_v1/room_authz/17.json', ['query' => ['ns' => $this->emulatorNamespace],
            'headers' => ['Authorization' => 'Bearer owner']]);
        $outbox = (object) ['id' => 7001, 'room_id' => 17, 'kind' => 'feed_created', 'message_id' => 501,
            'firebase_cursor' => 9001, 'recipient_user_id' => null];
        app(ChatFirebaseLiveDelivery::class)->deliver($outbox);
        app(ChatFirebaseLiveDelivery::class)->deliver($outbox);

        foreach (['viewer-a', 'viewer-b'] as $userId) {
            $event = $this->readPath('chat_v1/user_account_events/'.ChatFirebase::uid($userId).'/00000000000000007001');
            $this->assertSame('unread_invalidated', $event['kind']);
            $this->assertSame('7001', $event['revision']);
            $this->assertArrayNotHasKey('room_id', $event);
        }
        $this->assertSame(2, DB::table('chat_firebase_badge_recipients')->count());

        DB::table('chat_rooms')->insert(['id' => 18, 'type' => 'invite', 'content_id' => null, 'closed' => false, 'room_key' => 'invite:18']);
        app(ChatFirebaseLiveDelivery::class)->deliver((object) ['id' => 7002, 'room_id' => 18,
            'kind' => 'badge_sync', 'message_id' => null, 'firebase_cursor' => 9002, 'recipient_user_id' => 'viewer-a']);
        $privateInvalidation = $this->readPath('chat_v1/user_account_events/'.ChatFirebase::uid('viewer-a').'/00000000000000007002');
        $this->assertSame('room_state_invalidated', $privateInvalidation['kind']);
        $this->assertArrayNotHasKey('room_id', $privateInvalidation);
        $this->assertSame(3, DB::table('chat_firebase_badge_recipients')->count());
    }

    public function test_temporary_firebase_unavailability_preserves_sql_acceptance_and_same_outbox_retry_succeeds(): void
    {
        $outbox = (object) ['id' => 8001, 'room_id' => 17, 'kind' => 'feed_created', 'message_id' => 501,
            'firebase_cursor' => 8001, 'recipient_user_id' => null];
        $previousEnv = $_ENV['FIREBASE_DATABASE_EMULATOR_HOST'] ?? null;
        $previousServer = $_SERVER['FIREBASE_DATABASE_EMULATOR_HOST'] ?? null;
        $_ENV['FIREBASE_DATABASE_EMULATOR_HOST'] = '127.0.0.1:9199';
        $_SERVER['FIREBASE_DATABASE_EMULATOR_HOST'] = '127.0.0.1:9199';
        putenv('FIREBASE_DATABASE_EMULATOR_HOST=127.0.0.1:9199');
        $failure = null;
        try {
            try {
                app(ChatFirebase::class)->publishAccountEvents([
                    'user_account_events/'.ChatFirebase::uid('viewer-a').'/00000000000000008001' => [
                        'revision' => '8001', 'kind' => 'unread_invalidated', 'server_at' => now()->getTimestampMs()],
                ]);
            } catch (\Throwable $error) {
                $failure = $error;
            }
        } finally {
            if ($previousEnv === null) { unset($_ENV['FIREBASE_DATABASE_EMULATOR_HOST']); }
            else { $_ENV['FIREBASE_DATABASE_EMULATOR_HOST'] = $previousEnv; }
            if ($previousServer === null) { unset($_SERVER['FIREBASE_DATABASE_EMULATOR_HOST']); }
            else { $_SERVER['FIREBASE_DATABASE_EMULATOR_HOST'] = $previousServer; }
            putenv('FIREBASE_DATABASE_EMULATOR_HOST='.$this->emulatorHost);
        }

        $this->assertInstanceOf(\Throwable::class, $failure, 'The publication should fail while the emulator endpoint is unavailable.');
        $this->assertSame('accepted by SQL', DB::table('chat_messages')->where('id', 501)->value('body'));
        app(ChatFirebaseLiveDelivery::class)->deliver($outbox);
        $this->assertNotNull($this->readPath('chat_v1/user_account_events/'.ChatFirebase::uid('viewer-a').'/00000000000000008001'));
        $this->assertSame(2, DB::table('chat_firebase_badge_recipients')->count());
    }

    private function readPath(string $path): mixed
    {
        $response = $this->emulatorHttp->get('/'.trim($path, '/').'.json', ['query' => ['ns' => $this->emulatorNamespace],
            'headers' => ['Authorization' => 'Bearer owner']]);
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function writePath(string $path, mixed $value): void
    {
        $this->emulatorHttp->put('/'.trim($path, '/').'.json', ['query' => ['ns' => $this->emulatorNamespace],
            'headers' => ['Authorization' => 'Bearer owner'], 'json' => $value])->getBody()->getContents();
    }
}
