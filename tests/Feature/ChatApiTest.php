<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminTokenMiddleware;
use App\Http\Middleware\AuthTokenMiddleware;
use App\Support\Analytics\AnalyticsCollectionSetting;
use App\Support\Chat\ChatChannels;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    private function addMembership(string $room, string $user, string $status = 'accepted'): int
    {
        return DB::table('chat_room_invites')->insertGetId(['room_id' => $room, 'inviter_user_id' => 'alice',
            'invitee_user_id' => $user, 'status' => $status, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_private_room_accepts_ten_members_including_creator_and_keeps_full_invite_pending(): void
    {
        $room = $this->createPrivateRoom();
        foreach (range(1, 8) as $n) { $this->addMembership($room, 'member-'.$n); }
        $bob = DB::table('chat_room_invites')->where('room_id', $room)->where('invitee_user_id', 'bob')->value('id');
        $this->postJson("/api/v4/chat/invites/$bob/respond", ['auth_user_id' => 'bob', 'accept' => true])->assertOk();
        $next = $this->addMembership($room, 'next-member', 'pending');
        $this->postJson("/api/v4/chat/invites/$next/respond", ['auth_user_id' => 'next-member', 'accept' => true])->assertStatus(409);
        $this->assertSame('pending', DB::table('chat_room_invites')->where('id', $next)->value('status'));
        $this->postJson("/api/v4/chat/invites/$next/respond", ['auth_user_id' => 'next-member', 'accept' => false])->assertOk();
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])->assertOk();
    }

    public function test_dedicated_watch_room_accepts_only_five_members_including_creator(): void
    {
        $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch five', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        foreach (range(1, 3) as $n) { $this->addMembership($room, 'watch-member-'.$n); }
        $bob = DB::table('chat_room_invites')->where('room_id', $room)->where('invitee_user_id', 'bob')->value('id');
        $this->postJson("/api/v4/chat/invites/$bob/respond", ['auth_user_id' => 'bob', 'accept' => true])->assertOk();
        $next = $this->addMembership($room, 'sixth', 'pending');
        $this->postJson("/api/v4/chat/invites/$next/respond", ['auth_user_id' => 'sixth', 'accept' => true])->assertStatus(409);
    }

    public function test_private_room_watch_join_limit_cannot_be_bypassed_by_rejoin_or_heartbeat(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        foreach (['bob', 'guest-2', 'guest-3', 'guest-4'] as $guest) {
            if ($guest !== 'bob') { $this->addMembership($room, $guest); }
            $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => $guest])->assertOk();
        }
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'bob'])->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", $this->identity())->assertOk();
        $this->addMembership($room, 'sixth');
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'sixth'])->assertStatus(409);
        $device = DB::table('n_devices')->insertGetId(['user_id' => 'sixth', 'device_token' => 'sixth-device']);
        DB::table('n_active_streams')->insert(['device_id' => $device, 'stream_token' => 'sixth-stream', 'status' => 'active',
            'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        $this->withHeaders(['X-Playback-Token' => 'sixth-stream'])->postJson("/api/v4/chat/rooms/$room/watch-heartbeat", [
            'auth_user_id' => 'sixth', 'auth_device_id' => 'sixth-device', 'session_id' => $session,
        ])->assertStatus(409);
        $this->assertSame(4, DB::table('chat_watch_participants')->where('session_id', $session)->count());
    }

    public function test_private_message_push_is_durable_and_request_retries_do_not_duplicate_it(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('user')->where('uid', 'bob')->update(['token' => 'bob-push-token']);
        $request = (string) Str::uuid();
        $sent = $this->send($room, 'Hello Bob', $request)->assertCreated();
        $this->send($room, 'Hello Bob', $request)->assertOk();
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'message_push')->count());
        $fcm = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $fcm->shouldReceive('sendToToken')->once()->with('bob-push-token', 'Friends', 'Alice: Hello Bob', '', 'chat_message', [
            'room_id' => $room, 'message_id' => $sent->json('data.id'),
        ])->andReturn(['success' => true]);
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $fcm);
        Http::fake();
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertNotNull(DB::table('chat_outbox')->where('kind', 'message_push')->value('delivered_at'));
    }

    public function test_visible_room_suppresses_push_but_socket_presence_alone_does_not(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('user')->where('uid', 'bob')->update(['token' => 'bob-push-token']);
        $identity = ['auth_user_id' => 'bob', 'auth_device_id' => 'bob-device'];
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $identity + ['visible' => true, 'visibility_sequence' => 100])->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $identity)->assertOk(); // Control connection cannot clear UI presence.
        $this->send($room)->assertCreated();
        $fcm = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $fcm->shouldNotReceive('sendToToken');
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $fcm);
        $row = DB::table('chat_outbox')->where('kind', 'message_push')->first();
        app(\App\Support\Chat\ChatMessageNotifications::class)->deliver($row);
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $identity + ['visible' => false, 'visibility_sequence' => 102])->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $identity + ['visible' => true, 'visibility_sequence' => 101])->assertOk();
        $this->assertFalse(app(\App\Support\Chat\ChatVisibility::class)->visible((int) $room, 'bob'));
        $fcm = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $fcm->shouldReceive('sendToToken')->once()->andReturn(['success' => true]);
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $fcm);
        app(\App\Support\Chat\ChatMessageNotifications::class)->deliver($row);
    }

    public function test_visibility_is_scoped_to_room_and_device_and_expires_after_unclean_exit(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        foreach (['first', 'second'] as $device) {
            $this->postJson("/api/v4/chat/rooms/$room/heartbeat", ['auth_user_id' => 'bob', 'auth_device_id' => $device,
                'visible' => true, 'visibility_sequence' => 10])->assertOk();
        }
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", ['auth_user_id' => 'bob', 'auth_device_id' => 'first',
            'visible' => false, 'visibility_sequence' => 11])->assertOk();
        $visible = app(\App\Support\Chat\ChatVisibility::class);
        $this->assertTrue($visible->visible((int) $room, 'bob'));
        $this->assertFalse($visible->visible((int) $room + 1, 'bob'));
        $this->travel(91)->seconds();
        $this->assertFalse($visible->visible((int) $room, 'bob'));
    }

    public function test_push_respects_spoilers_and_does_not_notify_pending_members_or_public_rooms(): void
    {
        $room = $this->createPrivateRoom();
        $this->send($room)->assertCreated(); // Bob has not accepted yet.
        $this->assertSame(0, DB::table('chat_outbox')->where('kind', 'message_push')->count());
        $this->travel(3)->seconds();
        $this->send($this->room())->assertCreated();
        $this->assertSame(0, DB::table('chat_outbox')->where('kind', 'message_push')->count());
        $this->travel(3)->seconds();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('user')->where('uid', 'bob')->update(['token' => 'bob-push-token']);
        $this->postJson("/api/v4/chat/rooms/$room/messages", $this->identity([
            'body' => 'Secret ending', 'spoiler' => true, 'request_id' => (string) Str::uuid(),
        ]))->assertCreated();
        $fcm = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $fcm->shouldReceive('sendToToken')->once()->with('bob-push-token', 'Friends', 'Alice: Sent a spoiler message.', '', 'chat_message', \Mockery::type('array'))
            ->andReturn(['success' => true]);
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $fcm);
        app(\App\Support\Chat\ChatMessageNotifications::class)->deliver(DB::table('chat_outbox')->where('kind', 'message_push')->first());
    }

    public function test_push_delivery_rechecks_read_membership_block_and_room_state(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('user')->where('uid', 'bob')->update(['token' => 'bob-push-token']);
        $id = $this->send($room)->assertCreated()->json('data.id');
        $row = DB::table('chat_outbox')->where('kind', 'message_push')->first();
        $service = app(\App\Support\Chat\ChatMessageNotifications::class);
        $fcm = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $fcm->shouldNotReceive('sendToToken');
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $fcm);
        DB::table('chat_blocks')->insert(['user_id' => 'bob', 'blocked_user_id' => 'alice']);
        $service->deliver($row);
        DB::table('chat_blocks')->delete();
        DB::table('chat_room_reads')->insert(['room_id' => $room, 'user_id' => 'bob', 'last_read_message_id' => $id]);
        $service->deliver($row);
        DB::table('chat_room_reads')->delete();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'declined']);
        $service->deliver($row);
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('chat_messages')->where('id', $id)->update(['deleted_at' => now()]);
        $service->deliver($row);
        DB::table('chat_messages')->where('id', $id)->update(['deleted_at' => null]);
        DB::table('chat_invite_rooms')->where('room_id', $room)->update(['active' => false]);
        $service->deliver($row);
    }

    public function test_push_failures_retry_without_rebroadcasting_the_message(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('user')->where('uid', 'bob')->update(['token' => 'bob-push-token']);
        $this->send($room)->assertCreated();
        $fcm = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $fcm->shouldReceive('sendToToken')->twice()->andReturn(['success' => false], ['success' => true]);
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $fcm);
        Http::fake();
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertNull(DB::table('chat_outbox')->where('kind', 'message_push')->value('delivered_at'));
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'message_push')->value('attempts'));
        $broadcasts = count(Http::recorded());
        $this->travel(10)->seconds();
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertSame($broadcasts, count(Http::recorded()));
        $this->assertNotNull(DB::table('chat_outbox')->where('kind', 'message_push')->value('delivered_at'));
    }

    public function test_foreground_watch_presence_survives_closing_chat_on_the_same_device(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        $chat = (string) Str::uuid(); $watch = (string) Str::uuid();
        $identity = ['auth_user_id' => 'bob', 'auth_device_id' => 'bob-device'];
        foreach ([$chat, $watch] as $source) {
            $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $identity + ['visible' => true,
                'visibility_id' => $source, 'visibility_sequence' => 1])->assertOk();
        }
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $identity + ['visible' => false,
            'visibility_id' => $chat, 'visibility_sequence' => 2])->assertOk();
        $visible = app(\App\Support\Chat\ChatVisibility::class);
        $this->assertTrue($visible->visible((int) $room, 'bob'));
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $identity + ['visible' => false,
            'visibility_id' => $watch, 'visibility_sequence' => 2])->assertOk();
        $this->assertFalse($visible->visible((int) $room, 'bob'));
    }

    private function socketSession(string $user, string $device = 'device-a'): int
    {
        if (! Schema::hasTable('session_tokens')) {
            Schema::create('session_tokens', function (Blueprint $table) {
                $table->id(); $table->string('user_id'); $table->string('device_id');
                $table->string('access_token'); $table->timestamp('access_expires_at');
            });
        }
        return DB::table('session_tokens')->insertGetId(['user_id' => $user, 'device_id' => $device,
            'access_token' => 'stored-'.$user, 'access_expires_at' => now()->addHour()]);
    }

    private function socketAuth(string $room, string $user, int $session, string $socket = '1.2'): array
    {
        $request = new \Illuminate\Http\Request(['auth_user_id' => $user, 'auth_device_id' => 'device-a',
            'socket_id' => $socket, 'protocol' => 2, 'messages' => true]);
        $request->attributes->set('auth_session_token_id', $session);
        return app(\App\Http\Controllers\Api\V4\ChatController::class)->auth($request, (int) $room)->getData(true)['data'];
    }

    public function test_socket_feed_is_private_and_rechecks_revocation_bans_blocks_and_membership(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        $session = $this->socketSession('bob');
        $auth = $this->socketAuth($room, 'bob', $session);
        $this->assertArrayHasKey('control_token', $auth);
        $this->assertStringStartsWith('private-chat-feed.', $auth['feed_channel']);
        $this->send($room)->assertCreated();
        $row = DB::table('chat_outbox')->where('kind', 'feed_created')->first();
        $deliver = fn () => app(\App\Support\Chat\ChatLiveDelivery::class)->deliver($row, fn () => null);
        Http::fake(); $deliver();
        Http::assertSent(function ($request) use ($auth) {
            $body = json_decode($request->body(), true);
            return $body['name'] === 'chat.message' && $body['channels'] === [$auth['feed_channel']]
                && json_decode($body['data'], true)['message']['body'] === 'Hello';
        });
        foreach (['revoked', 'banned', 'blocked', 'removed', 'closed'] as $state) {
            Http::fake();
            if ($state === 'revoked') DB::table('session_tokens')->where('id', $session)->update(['access_token' => 'rotated']);
            if ($state === 'banned') DB::table('chat_bans')->insert(['room_id' => $room, 'user_id' => 'bob', 'kind' => 'ban']);
            if ($state === 'blocked') DB::table('chat_blocks')->insert(['user_id' => 'bob', 'blocked_user_id' => 'alice']);
            if ($state === 'removed') DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'declined']);
            if ($state === 'closed') DB::table('chat_rooms')->where('id', $room)->update(['closed' => true]);
            $deliver(); Http::assertNothingSent();
            DB::table('session_tokens')->where('id', $session)->update(['access_token' => 'stored-bob']);
            DB::table('chat_bans')->delete(); DB::table('chat_blocks')->delete();
            DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        }
    }

    public function test_socket_presence_is_bound_to_authenticated_socket_and_performs_no_sql(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        $auth = $this->socketAuth($room, 'bob', $this->socketSession('bob'));
        $application = new \Laravel\Reverb\Application('chat', 'key', 'secret', 60, 30, [], 2048);
        $this->assertMatchesRegularExpression('/^private-chat-control\.'.preg_quote((string) $room, '/').'\.[a-f0-9]{32}\.lease\.\d+$/', $auth['control_channel']);
        $connection = new class($application) extends \Laravel\Reverb\Contracts\Connection {
            public array $sent = [];
            public string $socket = '1.2';
            public function __construct(private \Laravel\Reverb\Application $testApp) {}
            public function identifier(): string { return $this->socket; }
            public function id(): string { return $this->socket; }
            public function app(): \Laravel\Reverb\Application { return $this->testApp; }
            public function send(string $message): void { $this->sent[] = $message; }
            public function control(string $type = "ping"): void {}
            public function terminate(): void {}
        };
        $manager = \Mockery::mock(\Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager::class);
        $manager->shouldReceive('for')->with($application)->andReturnSelf();
        $channel = \Mockery::mock(\Laravel\Reverb\Protocols\Pusher\Channels\Channel::class);
        $channel->shouldReceive('find')->with($connection)->andReturn(new \Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection($connection));
        $manager->shouldReceive('find')->with($auth['control_channel'])->andReturn($channel);
        $this->app->instance(\Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager::class, $manager);
        $data = ['visible' => true, 'visibility_sequence' => 1,
            'visibility_id' => (string) Str::uuid(), 'auth_user_id' => 'mallory'];
        DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        app(\App\Support\Chat\ChatSocketPresence::class)->handle(new \Laravel\Reverb\Events\MessageReceived($connection,
            json_encode(['event' => 'chat:presence', 'channel' => $auth['control_channel'], 'data' => $data])));
        $this->assertCount(0, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        $visible = app(\App\Support\Chat\ChatVisibility::class);
        $this->assertTrue($visible->visible((int) $room, 'bob'));
        $this->assertFalse($visible->visible((int) $room, 'mallory'));
        $this->assertCount(1, $connection->sent);
        $connection->socket = '9.9';
        app(\App\Support\Chat\ChatSocketPresence::class)->handle(new \Laravel\Reverb\Events\MessageReceived($connection,
            json_encode(['event' => 'chat:presence', 'channel' => $auth['control_channel'], 'data' => $data])));
        $this->assertCount(1, $connection->sent);

    }

    public function test_five_thousand_live_readers_use_batched_sql_and_bounded_publish_requests(): void
    {
        $room = $this->room();
        $this->socketSession('reader-0');
        $live = app(\App\Support\Chat\ChatLiveChannels::class);
        for ($i = 0; $i < 5000; $i++) {
            $session = $i === 0 ? 1 : $this->socketSession('reader-'.$i);
            $request = new \Illuminate\Http\Request(['messages' => true]);
            $request->attributes->set('auth_session_token_id', $session);
            $live->issue($request, (int) $room, '1.'.$i, \App\Support\Chat\ChatChannels::authorization('1.'.$i, 'private-chat.'.$room));
        }
        $this->send($room)->assertCreated();
        $row = DB::table('chat_outbox')->where('kind', 'feed_created')->first();
        Http::fake();
        DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        app(\App\Support\Chat\ChatLiveDelivery::class)->deliver($row, fn () => null);
        $queries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        $this->assertLessThanOrEqual(20, $queries, 'Live delivery must batch recipient authorization SQL.');
        Http::assertSentCount(10);
        $channels = [];
        foreach (Http::recorded() as [$request]) {
            $payload = json_decode($request->body(), true);
            $this->assertLessThan(131072, strlen($request->body()));
            array_push($channels, ...$payload['channels']);
        }
        $this->assertCount(5000, array_unique($channels));
    }

    public function test_worker_lanes_and_claims_keep_push_failure_off_the_realtime_lane(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('user')->where('uid', 'bob')->update(['token' => 'bob-token']);
        $this->send($room)->assertCreated();
        $fcm = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $fcm->shouldNotReceive('sendToToken');
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $fcm);
        DB::table('chat_outbox')->where('kind', 'created')->update(['claim_token' => (string) Str::uuid(), 'claimed_until' => now()->addMinute()]);
        Http::fake();
        $this->artisan('chat:deliver', ['--once' => true, '--lane' => 'realtime'])->assertSuccessful();
        $this->assertNull(DB::table('chat_outbox')->where('kind', 'created')->value('delivered_at'));
        $this->assertNull(DB::table('chat_outbox')->where('kind', 'message_push')->value('delivered_at'));
        $this->travel(61)->seconds();
        $this->artisan('chat:deliver', ['--once' => true, '--lane' => 'realtime'])->assertSuccessful();
        $this->assertNotNull(DB::table('chat_outbox')->where('kind', 'created')->value('delivered_at'));
    }

    public function test_badge_authorization_batch_rejects_another_accounts_channel(): void
    {
        $channel = \App\Support\Chat\ChatReadService::userChannel('alice');
        $this->postJson('/api/v4/chat/unread/auth-batch', $this->identity(['socket_id' => '1.2', 'channels' => [$channel]]))
            ->assertOk()->assertJsonPath('data.authorizations.'.$channel.'.expires_in', 300 - now()->timestamp % 300);
        $this->postJson('/api/v4/chat/unread/auth-batch', $this->identity(['socket_id' => '1.2',
            'channels' => [\App\Support\Chat\ChatReadService::userChannel('bob')]]))->assertForbidden();
    }

    public function test_live_private_watch_survives_idle_deadline_in_discovery_links_and_notifications(): void
    {
        $room = $this->privateWatchFixture();
        $this->sendPrivateWatch($room)->assertCreated();
        DB::table('chat_invite_rooms')->where('room_id', $room)->update(['idle_expires_at' => now()->subMinute()]);
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=bob')->assertOk()
            ->assertJsonPath('data.rooms.0.room_id', $room);
        $this->getJson('/api/v4/chat/unread?auth_user_id=bob')->assertOk()
            ->assertJsonPath('data.rooms.0.room_id', $room)->assertJsonPath('data.rooms.0.unread_count', 1);
        $token = str_repeat('x', 64);
        DB::table('chat_room_invites')->where('room_id', $room)->update([
            'status' => 'pending', 'link_token_hash' => hash('sha256', $token),
        ]);
        $this->getJson('/api/v4/chat/invites?auth_user_id=bob')->assertOk()->assertJsonCount(1, 'data.invites');
        $this->getJson("/api/v4/chat/invitation-links/$token?auth_user_id=bob")->assertOk();
        $job = new \App\Jobs\SendChatInvitationWhatsApp(DB::table('chat_room_invites')->where('room_id', $room)->value('id'), $token);
        config(['chat.whatsapp_enabled' => true]);
        DB::table('user')->where('uid', 'bob')->update(['auth_phone' => '9876543210', 'country_code' => '91']);
        $whatsapp = \Mockery::mock(\App\Services\WhatsAppCloudService::class);
        $whatsapp->shouldReceive('sendTemplate')->once();
        $job->handle($whatsapp);
        $this->postJson("/api/v4/chat/invitation-links/$token/respond", ['auth_user_id' => 'bob', 'accept' => true])->assertOk();
        DB::table('n_active_streams')->update(['stream_token' => 'replaced']);
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=bob')->assertOk()->assertJsonCount(0, 'data.rooms');
        $this->getJson('/api/v4/chat/unread?auth_user_id=bob')->assertOk()->assertJsonCount(0, 'data.rooms');
        $this->getJson("/api/v4/chat/invitation-links/$token?auth_user_id=bob")->assertStatus(410);
    }

    public function test_closed_room_cannot_accept_numeric_invite_and_responses_reflect_persisted_status(): void
    {
        $room = $this->createPrivateRoom();
        $invite = DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        DB::table('chat_rooms')->where('id', $room)->update(['closed' => true]);
        $this->postJson("/api/v4/chat/invites/$invite/respond", ['auth_user_id' => 'bob', 'accept' => true])->assertNotFound();
        $this->assertSame('pending', DB::table('chat_room_invites')->where('id', $invite)->value('status'));
        DB::table('chat_rooms')->where('id', $room)->update(['closed' => false]);
        $this->postJson("/api/v4/chat/invites/$invite/respond", ['auth_user_id' => 'bob', 'accept' => false])->assertOk();
        $this->postJson("/api/v4/chat/invites/$invite/respond", ['auth_user_id' => 'bob', 'accept' => true])->assertNotFound();
        $this->assertSame('declined', DB::table('chat_room_invites')->where('id', $invite)->value('status'));
    }

    public function test_disabled_chat_rejects_all_viewer_endpoints_but_preserves_admin_review(): void
    {
        $room = $this->createPrivateRoom();
        $invite = DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        config(['chat.enabled' => false]);
        foreach (['blocks', 'invites', 'invite-rooms', 'unread'] as $path) {
            $this->getJson('/api/v4/chat/'.$path.'?auth_user_id=alice')->assertStatus(503)->assertJsonPath('error.code', 'CHAT_UNAVAILABLE');
        }
        foreach (["invites/$invite/respond", 'blocks', 'invitees/lookup', "rooms/$room/leave", 'rooms/join'] as $path) {
            $this->postJson('/api/v4/chat/'.$path, $this->identity())->assertStatus(503);
        }
        $this->assertSame('pending', DB::table('chat_room_invites')->where('id', $invite)->value('status'));
        $this->getJson('/api/v4/admin/chat/rooms')->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/moderate", $this->identity([
            'action' => 'close', 'target' => $room, 'reason' => 'maintenance',
        ]))->assertOk();
    }

    public function test_erasure_cleans_membership_watch_data_and_invalidates_other_block_caches(): void
    {
        $room = $this->privateWatchFixture();
        $sent = $this->sendPrivateWatch($room)->assertCreated();
        $session = $sent->json('data.watch_invite.session_id');
        DB::table('chat_watch_participants')->insert(['session_id' => $session, 'user_id' => 'bob', 'joined_at' => now()]);
        DB::table('chat_blocks')->insert(['user_id' => 'bob', 'blocked_user_id' => 'alice']);
        $chat = app(\App\Support\Chat\ChatService::class);
        $this->assertSame(['alice'], $chat->blocked('bob'));
        $chat->eraseUser('alice');
        $this->assertSame([], $chat->blocked('bob'));
        $this->assertFalse((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
        $this->assertNotSame('alice', DB::table('chat_invite_rooms')->where('room_id', $room)->value('host_user_id'));
        $this->assertSame(0, DB::table('chat_room_invites')->count());
        $this->assertSame(0, DB::table('chat_watch_participants')->count());
        $this->assertFalse((bool) DB::table('chat_watch_sessions')->value('active'));
        $this->assertNotSame('alice', DB::table('chat_watch_sessions')->value('host_user_id'));
        $this->assertSame('', DB::table('chat_watch_sessions')->value('stream_token_hash'));
        $this->assertNull(DB::table('chat_messages')->where('id', $sent->json('data.id'))->value('watch_invite'));
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])->assertForbidden();
        $this->assertTrue(DB::table('chat_outbox')->where('room_id', $room)->where('kind', 'ended')->exists());
    }

    public function test_admin_resolves_episode_numeric_fallback_and_private_room_names(): void
    {
        Schema::create('episodes', function (Blueprint $t) { $t->string('id'); $t->integer('num'); $t->string('title'); });
        DB::table('episodes')->insert(['id' => 'ep-a', 'num' => 123, 'title' => 'Episode title']);
        foreach (['ep-a', '123'] as $key) {
            $id = DB::table('chat_rooms')->insertGetId(['type' => 'episode', 'room_key' => 'episode:'.$key, 'content_id' => $key]);
            $this->getJson("/api/v4/admin/chat/rooms/$id/messages")->assertOk()->assertJsonPath('data.room.title', 'Episode title');
        }
        $room = $this->createPrivateRoom();
        $this->getJson('/api/v4/admin/chat/rooms?type=invite')->assertOk()
            ->assertJsonPath('data.data.0.title', 'Friends');
    }

    public function test_socket_leases_stop_metadata_for_old_subscriptions_and_deny_renewal_after_ban(): void
    {
        $this->travelTo(now()->startOfDay());
        $room = $this->room();
        $auth = $this->postJson("/api/v4/chat/rooms/$room/auth", $this->identity(['socket_id' => '1.2']))->assertOk();
        $old = $auth->json('data.channel');
        $this->assertSame(300, $auth->json('data.expires_in'));
        $this->assertNotSame('private-chat.'.$room, $old);
        $this->travel(301)->seconds();
        $this->assertContains($old, ChatChannels::publishing(['private-chat.'.$room]));
        $new = $this->postJson("/api/v4/chat/rooms/$room/auth", $this->identity(['socket_id' => '1.2']))->assertOk()->json('data.channel');
        $this->assertNotSame($old, $new);
        $this->travel(60)->seconds();
        $this->assertNotContains($old, ChatChannels::publishing(['private-chat.'.$room]));
        $this->postJson("/api/v4/chat/rooms/$room/moderate", $this->identity([
            'action' => 'ban', 'target' => 'alice', 'reason' => 'restricted',
        ]))->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/auth", $this->identity(['socket_id' => '1.2']))->assertForbidden();
        $this->postJson('/api/v4/chat/unread/auth', $this->identity(['socket_id' => '1.2', 'channel' => $old]))->assertForbidden();
    }

    public function test_disabled_chat_holds_broadcasts_for_retry_without_publishing(): void
    {
        $room = $this->room();
        $this->send($room)->assertCreated();
        config(['chat.enabled' => false]);
        Http::fake();
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull(DB::table('chat_outbox')->value('delivered_at'));
        $this->assertSame(1, DB::table('chat_outbox')->value('attempts'));
        config(['chat.enabled' => true]);
        $this->travel(10)->seconds();
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertNotNull(DB::table('chat_outbox')->value('delivered_at'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'chat_testing', 'database.connections.chat_testing' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'chat.enabled' => true, 'chat.cache_store' => 'array', 'chat.slow_mode_seconds' => 2,
            'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'chat', 'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http']]);
        DB::purge('chat_testing');
        (require database_path('migrations/2026_10_24_000001_create_chat_tables.php'))->up();
        (require database_path('migrations/2026_10_25_000001_create_invite_chat_rooms.php'))->up();
        (require database_path('migrations/2026_10_26_000001_add_room_name_to_chat_invite_rooms.php'))->up();
        (require database_path('migrations/2026_10_26_000002_add_idle_expiry_to_private_chat_rooms.php'))->up();
        (require database_path('migrations/2026_10_26_000003_create_chat_room_reads.php'))->up();
        (require database_path('migrations/2026_10_26_000004_add_chat_invitation_links.php'))->up();
        (require database_path('migrations/2026_10_27_000001_create_chat_watch_sessions.php'))->up();
        (require database_path('migrations/2026_10_27_000002_add_watch_playback_controls.php'))->up();
        (require database_path('migrations/2026_10_27_000003_create_chat_watch_participants.php'))->up();
        (require database_path('migrations/2026_10_27_000004_add_chat_badge_recipient.php'))->up();
        (require database_path('migrations/2026_10_28_000001_bind_dedicated_watch_rooms_to_host_stream.php'))->up();
        (require database_path('migrations/2026_10_28_000002_add_shared_room_invitation_messages.php'))->up();
        (require database_path('migrations/2026_10_28_000003_add_chat_delivery_claims.php'))->up();
        Schema::create('user', function (Blueprint $t) {
            $t->id();
            $t->string('uid');
            $t->string('name');
            $t->string('token')->nullable();
            $t->string('auth_phone')->nullable();
            $t->string('country_code')->nullable();
        });
        Schema::create('n_devices', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('device_token');
        });
        Schema::create('n_active_streams', function (Blueprint $t) {
            $t->id();
            $t->integer('device_id');
            $t->string('stream_token');
            $t->string('status');
            $t->string('content_type');
            $t->string('content_key');
            $t->timestamp('last_ping');
        });
        DB::table('user')->insert(['uid' => 'alice', 'name' => 'Alice']);
        $this->withoutMiddleware([AuthTokenMiddleware::class, AdminTokenMiddleware::class]);
        $setting = \Mockery::mock(AnalyticsCollectionSetting::class);
        $setting->shouldReceive('enabled')->andReturn(false);
        $this->app->instance(AnalyticsCollectionSetting::class, $setting);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::purge('chat_testing');
        parent::tearDown();
    }

    private function identity(array $data = []): array
    {
        return array_merge($data, ['auth_user_id' => 'alice', 'auth_device_id' => 'device-a']);
    }

    private function room(): string
    {
        return $this->postJson('/api/v4/chat/rooms/join', $this->identity(['type' => 'global']))->assertOk()->json('data.room_id');
    }

    private function send(string $room, string $body = 'Hello', ?string $request = null)
    {
        return $this->postJson("/api/v4/chat/rooms/$room/messages", $this->identity(['request_id' => $request ?? (string) Str::uuid(), 'body' => $body]));
    }

    private function message(string $room, string $uid = 'alice', string $body = 'hello'): int
    {
        return DB::table('chat_messages')->insertGetId(['room_id' => $room, 'user_id' => $uid, 'request_id' => (string) Str::uuid(), 'display_name' => $uid, 'body' => $body, 'spoiler' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function liveCreatorStream(string $type, string $content): int
    {
        $device = DB::table('n_devices')->insertGetId(['user_id' => 'alice', 'device_token' => 'device-a']);
        return DB::table('n_active_streams')->insertGetId(['device_id' => $device, 'stream_token' => 'play-a',
            'status' => 'active', 'content_type' => $type, 'content_key' => $content, 'last_ping' => now()]);
    }

    public function test_room_tabs_include_only_active_hosted_and_accepted_rooms(): void
    {
        $create = function (string $host, string $name, ?string $status = null, bool $active = true, bool $closed = false, bool $watch = false): string {
            $id = DB::table('chat_rooms')->insertGetId([
                'room_key' => 'invite:'.Str::uuid(), 'type' => 'invite', 'closed' => $closed,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('chat_invite_rooms')->insert([
                'room_id' => $id, 'host_user_id' => $host, 'room_name' => $name, 'active' => $active,
                'content_type' => $watch ? 'movie' : null, 'content_id' => $watch ? '42' : null,
                'idle_expires_at' => $watch ? null : now()->addHour(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($status) {
                DB::table('chat_room_invites')->insert([
                    'room_id' => $id, 'inviter_user_id' => $host, 'invitee_user_id' => 'alice', 'status' => $status,
                    'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return (string) $id;
        };
        $hosted = $create('alice', 'My room');
        $accepted = $create('bob', 'Joined room', 'accepted');
        $watch = $create('alice', 'Watch room', watch: true);
        $this->liveCreatorStream('movie', '42');
        $create('bob', 'Pending room', 'pending');
        $create('bob', 'Declined room', 'declined');
        $create('bob', 'Someone else');
        $create('alice', 'Inactive room', active: false);
        $create('alice', 'Closed room', closed: true);

        $this->getJson('/api/v4/chat/invite-rooms?'.http_build_query($this->identity()))
            ->assertOk()->assertJsonCount(3, 'data.rooms')
            ->assertJsonPath('data.rooms.0.room_id', $watch)
            ->assertJsonPath('data.rooms.0.content_type', 'movie')
            ->assertJsonPath('data.rooms.0.content_id', '42')
            ->assertJsonPath('data.rooms.1.room_id', $accepted)
            ->assertJsonPath('data.rooms.2.room_id', $hosted)
            ->assertJsonPath('data.rooms.2.room_name', 'My room')
            ->assertJsonPath('data.rooms.2.content_id', null)
            ->assertJsonPath('data.rooms.2.is_host', true)
            ->assertJsonPath('data.rooms.1.is_host', false);

        config(['chat.enabled' => false]);
        $this->getJson('/api/v4/chat/invite-rooms?'.http_build_query($this->identity()))->assertStatus(503);
    }

    private function createPrivateRoom(): string
    {
        DB::table('user')->insertOrIgnore(['uid' => 'bob', 'name' => 'Bob']);
        return $this->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'global', 'room_name' => 'Friends', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
    }

    public function test_creator_can_invite_more_people_to_the_existing_room_and_deliver_a_link(): void
    {
        $room = $this->createPrivateRoom();
        $message = $this->message($room);
        DB::table('user')->insert(['uid' => 'charlie', 'name' => 'Charlie', 'auth_phone' => '9876543211']);
        config(['chat.whatsapp_enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        $response = $this->postJson("/api/v4/chat/invite-rooms/$room/invitees", $this->identity(['invitee_ids' => ['charlie']]))
            ->assertOk()->assertJsonPath('data.room_id', $room)->assertJsonPath('data.invite_count', 1);
        $this->assertSame(1, DB::table('chat_invite_rooms')->count());
        $job = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\SendChatInvitationWhatsApp::class)->sole();
        $this->assertStringNotContainsString($job->token, $response->getContent());
        $this->postJson('/api/v4/chat/invitation-links/'.$job->token.'/respond', ['auth_user_id' => 'charlie', 'accept' => true])
            ->assertOk()->assertJsonPath('data.room_id', $room);
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=charlie")->assertOk()->assertJsonPath('data.messages.0.id', (string) $message);
    }

    public function test_room_picker_reports_recipient_membership_only_for_the_room_creator(): void
    {
        $room = $this->createPrivateRoom();
        $url = '/api/v4/chat/invite-rooms?auth_user_id=alice&recipient_user_id=bob';
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=alice')->assertOk()->assertJsonMissingPath('data.rooms.0.recipient_joined');
        $this->getJson($url)->assertOk()->assertJsonPath('data.rooms.0.room_id', $room)->assertJsonPath('data.rooms.0.recipient_joined', false);
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted', 'expires_at' => now()->subDay()]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.rooms.0.recipient_joined', true);
        // Membership does not expire merely because the original invitation did.
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=bob&recipient_user_id=bob')->assertOk()
            ->assertJsonPath('data.rooms.0.is_host', false)->assertJsonPath('data.rooms.0.recipient_joined', false);
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=alice&recipient_user_id=alice')->assertOk()
            ->assertJsonPath('data.rooms.0.recipient_joined', true);
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=alice&recipient_user_id[]=bob')->assertUnprocessable();
    }

    public function test_in_app_invite_sends_push_without_whatsapp_and_preserves_acceptance_flow(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('user')->insert(['uid' => 'charlie', 'name' => 'Charlie', 'token' => 'push-token']);
        config(['chat.whatsapp_enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        $push = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $push->shouldReceive('sendToToken')->once()->withArgs(function ($token, $title, $body, $image, $key, $data) use ($room) {
            return $token === 'push-token' && $key === 'chat_invite' && $data['room_id'] === $room && isset($data['invite_id']) && strlen($data['invitation_token'] ?? '') === 64;
        })->andReturn([]);
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $push);
        $this->postJson("/api/v4/chat/invite-rooms/$room/invitees", $this->identity(['invitee_ids' => ['charlie'], 'delivery' => 'in_app']))
            ->assertOk()->assertJsonPath('data.invite_count', 1);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        $invite = (string) DB::table('chat_room_invites')->where('invitee_user_id', 'charlie')->value('id');
        $this->getJson('/api/v4/chat/invites?auth_user_id=charlie')->assertOk()->assertJsonPath('data.invites.0.invite_id', $invite);
        $this->postJson("/api/v4/chat/invites/$invite/respond", ['auth_user_id' => 'charlie', 'accept' => true])->assertOk();
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'charlie'])->assertOk();
    }

    public function test_create_room_for_selected_chat_user_sends_in_app_invitation_and_requires_acceptance(): void
    {
        DB::table('user')->insert(['uid' => 'charlie', 'name' => 'Charlie', 'token' => 'push-token']);
        config(['chat.whatsapp_enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        $invitationToken = null;
        $push = \Mockery::mock(\App\Http\Controllers\FCMNotificationController::class);
        $push->shouldReceive('sendToToken')->once()->withArgs(function ($token, $title, $body, $image, $key, $data) use (&$invitationToken) {
            $invitationToken = $data['invitation_token'] ?? null;
            return $token === 'push-token' && $key === 'chat_invite' && strlen($invitationToken ?? '') === 64;
        })->andReturn([]);
        $this->app->instance(\App\Http\Controllers\FCMNotificationController::class, $push);
        $created = $this->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'global', 'room_name' => 'Chat with Charlie', 'invitee_ids' => ['charlie'], 'delivery' => 'in_app',
        ]))->assertCreated()->assertJsonPath('data.invite_count', 1);
        $room = $created->json('data.room_id');
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        $this->assertSame('pending', DB::table('chat_room_invites')->where('room_id', $room)->value('status'));
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'charlie'])->assertForbidden();
        $this->getJson('/api/v4/chat/invites?auth_user_id=charlie')->assertOk()->assertJsonPath('data.invites.0.room_id', $room);
        $this->postJson('/api/v4/chat/invitation-links/'.$invitationToken.'/respond', ['auth_user_id' => 'charlie', 'accept' => true])->assertOk();
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'charlie'])->assertOk();
    }

    public function test_push_registration_binds_token_to_authenticated_account_only(): void
    {
        $token = str_repeat('p', 64);
        DB::table('user')->insert(['uid' => 'bob', 'name' => 'Bob', 'token' => $token]);
        $this->postJson('/api/v4/account/push-token', $this->identity(['fcm_token' => $token, 'user_id' => 'bob']))
            ->assertOk()->assertJsonPath('data.registered', true);
        $this->assertSame($token, DB::table('user')->where('uid', 'alice')->value('token'));
        $this->assertNull(DB::table('user')->where('uid', 'bob')->value('token'));
        $this->postJson('/api/v4/account/push-token', $this->identity(['fcm_token' => '']))->assertUnprocessable();
    }

    private function sharePrivateRoom(string $source, string $target, array $identity = [], ?string $request = null)
    {
        return $this->postJson("/api/v4/chat/rooms/$source/messages", array_merge($this->identity(), $identity, [
            'body' => '/invite', 'request_id' => $request ?? (string) Str::uuid(), 'room_invite' => ['room_id' => $target],
        ]));
    }

    public function test_shared_room_card_creates_pending_invitation_then_uses_existing_accept_flow(): void
    {
        $room = $this->createPrivateRoom();
        $source = $this->room();
        DB::table('user')->insert(['uid' => 'charlie', 'name' => 'Charlie']);
        $request = (string) Str::uuid();
        $card = $this->sharePrivateRoom($source, $room, request: $request)->assertCreated()
            ->assertJsonPath('data.room_invite.room_id', $room)->assertJsonPath('data.room_invite.active', true)
            ->assertJsonPath('data.room_invite.room_name', 'Friends')->json('data.id');
        $this->sharePrivateRoom($source, $room, request: $request)->assertOk()->assertJsonPath('data.id', $card);
        $this->assertSame(1, DB::table('chat_messages')->where('invite_room_id', $room)->count());
        $url = "/api/v4/chat/rooms/$source/messages/$card/invitation";
        $this->postJson($url, ['auth_user_id' => 'charlie'])->assertOk()->assertJsonPath('data.accepted', false);
        $this->postJson($url, ['auth_user_id' => 'charlie'])->assertOk();
        $this->assertSame(1, DB::table('chat_room_invites')->where('invitee_user_id', 'charlie')->count());
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'charlie'])->assertForbidden();
        $invite = (string) DB::table('chat_room_invites')->where('invitee_user_id', 'charlie')->value('id');
        $this->getJson('/api/v4/chat/invites?auth_user_id=charlie')->assertOk()->assertJsonPath('data.invites.0.invite_id', $invite);
        $this->postJson("/api/v4/chat/invites/$invite/respond", ['auth_user_id' => 'charlie', 'accept' => true])->assertOk();
        $this->postJson($url, ['auth_user_id' => 'charlie'])->assertOk()->assertJsonPath('data.accepted', true);
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'charlie'])->assertOk();
    }

    public function test_room_cards_reflect_closure_in_cached_history_status_and_realtime_event(): void
    {
        $room = $this->createPrivateRoom(); $source = $this->room();
        $card = $this->sharePrivateRoom($source, $room)->assertCreated()->json('data.id');
        $history = "/api/v4/chat/rooms/$source/messages?auth_user_id=bob";
        $this->getJson($history)->assertOk()->assertJsonPath('data.messages.0.room_invite.active', true);
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", $this->identity())->assertOk();
        $this->getJson($history)->assertOk()->assertJsonPath('data.messages.0.room_invite.active', false);
        $this->postJson("/api/v4/chat/rooms/$source/room-invites/status", ['auth_user_id' => 'bob', 'room_ids' => [$room]])
            ->assertOk()->assertJsonPath('data.rooms.0.active', false);
        $this->postJson("/api/v4/chat/rooms/$source/messages/$card/invitation", ['auth_user_id' => 'bob'])->assertStatus(410);
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(function ($request) use ($room) {
            $event = json_decode($request->data()['data'] ?? '{}', true);
            return ($event['kind'] ?? null) === 'room_invite_status' && ($event['room_invite']['room_id'] ?? null) === $room && $event['room_invite']['active'] === false;
        });
    }

    public function test_room_share_and_claim_enforce_ownership_visibility_and_source_context(): void
    {
        $room = $this->createPrivateRoom(); $source = $this->room();
        $this->sharePrivateRoom($source, $room, ['auth_user_id' => 'bob'])->assertForbidden();
        Cache::flush();
        $this->sharePrivateRoom($room, $room)->assertUnprocessable();
        Cache::flush();
        $card = $this->sharePrivateRoom($source, $room)->assertCreated()->json('data.id');
        $this->postJson("/api/v4/chat/rooms/$source/messages/$card/invitation", ['auth_user_id' => 'alice'])->assertUnprocessable();
        DB::table('chat_blocks')->insert(['user_id' => 'bob', 'blocked_user_id' => 'alice']);
        $this->postJson("/api/v4/chat/rooms/$source/messages/$card/invitation", ['auth_user_id' => 'bob'])->assertNotFound();
        DB::table('chat_messages')->where('id', $card)->update(['deleted_at' => now()]);
        $this->postJson("/api/v4/chat/rooms/$source/messages/$card/invitation", ['auth_user_id' => 'charlie'])->assertNotFound();
        $this->getJson("/api/v4/chat/rooms/$source/messages?auth_user_id=alice")->assertOk()->assertJsonPath('data.messages.0.room_invite', null);
        $this->postJson("/api/v4/chat/rooms/$source/room-invites/status", $this->identity(['room_ids' => ['not-a-room']]))->assertUnprocessable();
    }

    public function test_movie_chat_supports_room_cards_but_requires_its_playback_access(): void
    {
        $room = $this->privateWatchFixture();
        $source = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/rooms/join', $this->identity(['type' => 'movie', 'content_id' => 'film-a']))
            ->assertOk()->json('data.room_id');
        $card = $this->sharePrivateRoom($source, $room)->assertCreated()->json('data.id');
        $this->postJson("/api/v4/chat/rooms/$source/messages/$card/invitation", ['auth_user_id' => 'bob'])->assertForbidden();
        DB::table('chat_invite_rooms')->where('room_id', $room)->update(['active' => false]);
        Cache::flush();
        $this->sharePrivateRoom($source, $room)->assertForbidden();
    }

    public function test_worker_expires_idle_shared_room_and_updates_public_cards_without_chat_messages(): void
    {
        $this->freezeTime(); $room = $this->createPrivateRoom(); $source = $this->room();
        $this->sharePrivateRoom($source, $room)->assertCreated();
        $this->travel(61)->minutes();
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertFalse((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
        $this->getJson("/api/v4/chat/rooms/$source/messages?auth_user_id=bob")->assertOk()->assertJsonPath('data.messages.0.room_invite.active', false);
    }

    public function test_only_creator_can_add_invitees_and_inactive_rooms_reject_invitations(): void
    {
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('user')->insert(['uid' => 'charlie', 'name' => 'Charlie']);
        $url = "/api/v4/chat/invite-rooms/$room/invitees";
        $this->postJson($url, ['auth_user_id' => 'bob', 'invitee_ids' => ['charlie']])->assertForbidden();
        $this->postJson($url, ['auth_user_id' => 'stranger', 'invitee_ids' => ['charlie']])->assertForbidden();
        $this->postJson($url, $this->identity(['invitee_ids' => ['alice']]))->assertUnprocessable();
        $this->postJson($url, $this->identity(['invitee_ids' => ['missing']]))->assertUnprocessable();
        DB::table('chat_invite_rooms')->where('room_id', $room)->update(['active' => false]);
        $this->postJson($url, $this->identity(['invitee_ids' => ['charlie']]))->assertForbidden();
        $this->assertSame(1, DB::table('chat_room_invites')->count());
    }

    public function test_repeated_add_invites_preserve_members_and_pending_links_but_refresh_declined_invites(): void
    {
        $room = $this->createPrivateRoom();
        config(['chat.whatsapp_enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        $url = "/api/v4/chat/invite-rooms/$room/invitees";
        $hash = DB::table('chat_room_invites')->value('link_token_hash');
        $this->postJson($url, $this->identity(['invitee_ids' => ['bob']]))->assertOk()->assertJsonPath('data.invite_count', 0);
        $this->assertSame($hash, DB::table('chat_room_invites')->value('link_token_hash'));
        DB::table('chat_room_invites')->update(['status' => 'accepted']);
        $this->postJson($url, $this->identity(['invitee_ids' => ['bob']]))->assertOk()->assertJsonPath('data.already_invited_count', 1);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        DB::table('chat_room_invites')->update(['status' => 'declined', 'whatsapp_sent_at' => now(), 'responded_at' => now()]);
        $this->postJson($url, $this->identity(['invitee_ids' => ['bob']]))->assertOk()->assertJsonPath('data.invite_count', 1);
        $this->assertSame(1, DB::table('chat_room_invites')->count());
        $this->assertNotSame($hash, DB::table('chat_room_invites')->value('link_token_hash'));
        $this->assertNull(DB::table('chat_room_invites')->value('whatsapp_sent_at'));
        $this->assertNull(DB::table('chat_room_invites')->value('responded_at'));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendChatInvitationWhatsApp::class, 1);
    }

    public function test_add_invitees_to_premium_watch_room_checks_the_new_users_subscription(): void
    {
        $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        Schema::create('n_plans', function (Blueprint $t) { $t->id(); });
        Schema::table('n_subscriptions', function (Blueprint $t) { $t->integer('plan_id')->nullable(); });
        DB::table('n_plans')->insert(['id' => 1]);
        DB::table('n_subscriptions')->insert(['user_id' => 'alice', 'plan_id' => 1, 'is_active' => true, 'end_at' => now()->addDay()]);
        DB::table('user')->insert(['uid' => 'charlie', 'name' => 'Charlie']);
        DB::table('movie')->update(['isPremium' => true]);
        Cache::flush();
        $url = "/api/v4/chat/invite-rooms/$room/invitees";
        $this->postJson($url, $this->identity(['invitee_ids' => ['charlie']]))->assertForbidden();
        DB::table('n_subscriptions')->insert(['user_id' => 'charlie', 'plan_id' => 1, 'is_active' => true, 'end_at' => now()->addDay()]);
        $this->postJson($url, $this->identity(['invitee_ids' => ['charlie']]))->assertOk()->assertJsonPath('data.invite_count', 1);
    }

    public function test_private_room_idle_deadline_moves_only_for_new_messages(): void
    {
        $this->freezeTime();
        $room = $this->createPrivateRoom();
        $createdDeadline = DB::table('chat_invite_rooms')->where('room_id', $room)->value('idle_expires_at');
        $this->assertSame(now()->addHour()->toDateTimeString(), $createdDeadline);
        $this->travel(59)->minutes();
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", $this->identity())->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/heartbeat", $this->identity())->assertOk();
        $this->assertSame($createdDeadline, DB::table('chat_invite_rooms')->where('room_id', $room)->value('idle_expires_at'));
        $request = (string) Str::uuid();
        $this->send($room, 'Still here', $request)->assertCreated();
        $messageDeadline = DB::table('chat_invite_rooms')->where('room_id', $room)->value('idle_expires_at');
        $this->assertSame(now()->addHour()->toDateTimeString(), $messageDeadline);
        $this->travel(30)->minutes();
        $this->send($room, 'Still here', $request)->assertOk();
        $this->assertSame($messageDeadline, DB::table('chat_invite_rooms')->where('room_id', $room)->value('idle_expires_at'));
        $this->travel(30)->minutes();
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", $this->identity())->assertForbidden();
        $this->send($room)->assertForbidden();
        $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertForbidden();
        $this->getJson('/api/v4/chat/invite-rooms?'.http_build_query($this->identity()))->assertJsonCount(0, 'data.rooms');
    }

    public function test_expired_private_room_cannot_be_accepted_and_pending_invite_disappears(): void
    {
        $this->freezeTime();
        $room = $this->createPrivateRoom();
        $invite = DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        $bob = ['auth_user_id' => 'bob', 'auth_device_id' => 'device-b'];
        $this->getJson('/api/v4/chat/invites?'.http_build_query($bob))->assertJsonCount(1, 'data.invites');
        $this->travel(60)->minutes();
        $this->getJson('/api/v4/chat/invites?'.http_build_query($bob))->assertJsonCount(0, 'data.invites');
        $this->postJson("/api/v4/chat/invites/$invite/respond", $bob + ['accept' => true])->assertNotFound();
    }

    public function test_invitation_list_and_episode_accept_use_ios_compatible_json_types(): void
    {
        $room = $this->createPrivateRoom();
        $invite = (string) DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        $bob = ['auth_user_id' => 'bob', 'auth_device_id' => 'device-b'];
        $this->getJson('/api/v4/chat/invites?'.http_build_query($bob))->assertOk()
            ->assertJsonPath('data.invites.0.invite_id', $invite)
            ->assertJsonPath('data.invites.0.room_id', $room)
            ->assertJsonPath('data.invites.0.watch_position_ms', 0)
            ->assertJsonPath('data.invites.0.inviter_name', 'Alice');
        Schema::create('seasons', function (Blueprint $t) {
            $t->id();
            $t->string('season_number');
        });
        Schema::create('episodes', function (Blueprint $t) {
            $t->string('num'); $t->string('id')->nullable();
            $t->string('title');
            $t->integer('season_id');
            $t->string('episode_number');
        });
        DB::table('seasons')->insert(['id' => 7, 'season_number' => '2']);
        DB::table('episodes')->insert(['num' => '42', 'title' => 'Episode', 'season_id' => 7, 'episode_number' => '3']);
        $this->liveCreatorStream('episode', '42');
        DB::table('chat_invite_rooms')->where('room_id', $room)->update([
            'content_type' => 'episode', 'content_id' => '42', 'idle_expires_at' => null,
        ]);
        $this->getJson('/api/v4/chat/invites?'.http_build_query($bob))->assertOk()
            ->assertJsonPath('data.invites.0.season_id', '7')
            ->assertJsonPath('data.invites.0.season_number', 2)
            ->assertJsonPath('data.invites.0.episode_number', 3);
        $this->postJson("/api/v4/chat/invites/$invite/respond", $bob + ['accept' => true])->assertOk()
            ->assertJsonPath('data.room_id', $room)
            ->assertJsonPath('data.season_id', '7')
            ->assertJsonPath('data.season_number', 2)
            ->assertJsonPath('data.episode_number', 3)
            ->assertJsonPath('data.watch_position_ms', 0);
    }

    public function test_only_creator_can_end_private_room_and_both_members_lose_access(): void
    {
        $room = $this->createPrivateRoom();
        $invite = DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        $bob = ['auth_user_id' => 'bob', 'auth_device_id' => 'device-b'];
        $this->postJson("/api/v4/chat/invites/$invite/respond", $bob + ['accept' => true])->assertOk();
        foreach ([$this->identity(), $bob] as $member) {
            // Reopening the sheet restores the room for both host and accepted invitee.
            $this->getJson('/api/v4/chat/invite-rooms?'.http_build_query($member))->assertJsonPath('data.rooms.0.room_id', $room);
        }
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", $bob)->assertForbidden();
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", ['auth_user_id' => 'stranger'])->assertForbidden();
        $this->send($room)->assertCreated();
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", $this->identity())->assertOk()->assertJsonPath('data.ended', true);
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", $this->identity())->assertOk();
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'ended')->count());
        foreach ([$this->identity(), $bob] as $member) {
            $this->getJson('/api/v4/chat/invite-rooms?'.http_build_query($member))->assertJsonCount(0, 'data.rooms');
            $this->postJson("/api/v4/chat/invite-rooms/$room/join", $member)->assertForbidden();
            $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($member))->assertForbidden();
            $this->postJson("/api/v4/chat/rooms/$room/messages", $member + ['request_id' => (string) Str::uuid(), 'body' => 'Late'])->assertForbidden();
        }
    }

    public function test_expiry_migration_uses_existing_last_message_and_room_creation(): void
    {
        $this->freezeTime();
        $first = $this->createPrivateRoom();
        $this->travel(10)->minutes();
        $second = $this->createPrivateRoom();
        $this->message($first);
        $migration = require database_path('migrations/2026_10_26_000002_add_idle_expiry_to_private_chat_rooms.php');
        $migration->down();
        $migration->up();
        foreach ([$first, $second] as $room) {
            $this->assertSame(now()->addHour()->toDateTimeString(), DB::table('chat_invite_rooms')->where('room_id', $room)->value('idle_expires_at'));
        }
    }

    public function test_watch_rooms_do_not_use_private_idle_expiry_or_private_end_action(): void
    {
        $this->freezeTime();
        $room = $this->createPrivateRoom();
        DB::table('chat_invite_rooms')->where('room_id', $room)->update([
            'content_type' => 'movie', 'content_id' => '42', 'idle_expires_at' => null,
        ]);
        $this->travel(2)->hours();
        $this->liveCreatorStream('movie', '42');
        $this->getJson('/api/v4/chat/invite-rooms?'.http_build_query($this->identity()))
            ->assertOk()->assertJsonPath('data.rooms.0.room_id', $room);
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", $this->identity())->assertStatus(422);
        $this->assertTrue((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
    }

    private function unread(array $identity = [], array $headers = [])
    {
        return $this->withHeaders($headers)->getJson('/api/v4/chat/unread?'.http_build_query(array_merge($this->identity(), $identity)));
    }

    public function test_badge_channel_auth_is_scoped_to_the_account_and_authorized_rooms(): void
    {
        $room = $this->createPrivateRoom();
        $channel = \App\Support\Chat\ChatReadService::userChannel('alice');
        $this->unread()->assertOk()->assertJsonPath('data.channels.0', $channel)
            ->assertJsonPath('data.channels.1', 'private-chat.'.$room)->assertJsonStructure(['data' => ['event_cursor', 'blocked_user_ids']]);
        $url = '/api/v4/chat/unread/auth';
        $this->postJson($url, $this->identity(['socket_id' => '1.2', 'channel' => $channel]))->assertOk()
            ->assertJsonPath('data.auth', 'test-key:'.hash_hmac('sha256', '1.2:'.ChatChannels::leased($channel), 'test-secret'));
        $this->postJson($url, ['auth_user_id' => 'bob', 'socket_id' => '1.2', 'channel' => $channel])->assertForbidden();
        $this->postJson($url, ['auth_user_id' => 'bob', 'socket_id' => '1.2', 'channel' => 'private-chat.'.$room])->assertForbidden();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        $this->postJson($url, ['auth_user_id' => 'bob', 'socket_id' => '1.2', 'channel' => 'private-chat.'.$room])->assertOk();
        $this->postJson($url, $this->identity(['socket_id' => '1.2', 'channel' => 'private-chat-user-anyone']))->assertForbidden();
    }

    public function test_badges_can_discover_a_new_public_room_without_subscribing_to_other_content(): void
    {
        $global = \App\Support\Chat\ChatReadService::contextChannel('global');
        $this->unread()->assertOk()->assertJsonPath('data.channels.1', $global)->assertJsonCount(0, 'data.rooms');
        $this->postJson('/api/v4/chat/unread/auth', $this->identity(['socket_id' => '1.2', 'channel' => $global]))->assertOk();
        $movie = \App\Support\Chat\ChatReadService::contextChannel('movie', 'not-playing');
        $this->postJson('/api/v4/chat/unread/auth', $this->identity(['socket_id' => '1.2', 'channel' => $movie]))->assertForbidden();
        $room = $this->room();
        $this->send($room)->assertCreated();
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(fn ($request) => in_array(ChatChannels::leased($global), $request->data()['channels'] ?? [], true));
    }

    public function test_badge_read_receipts_push_authoritative_counts_only_to_the_readers_private_channel(): void
    {
        $room = $this->room();
        $first = $this->message($room, 'bob');
        $second = $this->message($room, 'bob');
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $first]))->assertOk()
            ->assertJsonPath('data.badge.unread_count', 1)->assertJsonPath('data.badge.first_unread_id', (string) $second)
            ->assertJsonPath('data.badge.last_message_id', (string) $second);
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $first]))->assertOk();
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'read')->count());
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(function ($request) use ($first, $second) {
            $event = json_decode($request->data()['data'] ?? '{}', true);
            return ($request->data()['name'] ?? null) === 'chat.badge'
                && $request->data()['channels'] === ChatChannels::publishing([\App\Support\Chat\ChatReadService::userChannel('alice')])
                && $event['badge']['read_through'] === (string) $first && $event['badge']['unread_count'] === 1
                && $event['badge']['first_unread_id'] === (string) $second && isset($event['badge']['snapshot_cursor']);
        });
    }

    public function test_message_badge_frames_carry_no_message_body_or_credentials(): void
    {
        $room = $this->room();
        $message = $this->send($room, 'secret message body')->assertCreated()->json('data.id');
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(function ($request) use ($message) {
            $event = json_decode($request->data()['data'] ?? '{}', true);
            return ($event['badge']['kind'] ?? null) === 'created' && $event['badge']['message_id'] === $message
                && $event['badge']['sender_id'] === 'alice' && $event['badge']['eligible'] === true
                && !str_contains($request->data()['data'], 'secret message body');
        });
    }

    public function test_unread_counts_exclude_own_deleted_blocked_and_expired_messages(): void
    {
        $room = $this->room();
        $first = $this->message($room, 'bob');
        $last = $this->message($room, 'bob');
        $this->message($room, 'alice');
        $deleted = $this->message($room, 'bob');
        DB::table('chat_messages')->where('id', $deleted)->update(['deleted_at' => now()]);
        $this->message($room, 'blocked');
        DB::table('chat_blocks')->insert(['user_id' => 'alice', 'blocked_user_id' => 'blocked']);
        $old = $this->message($room, 'bob');
        DB::table('chat_messages')->where('id', $old)->update(['created_at' => now()->subDays(31)]);
        $this->unread()->assertOk()->assertJsonPath('data.total_unread', 2)
            ->assertJsonPath('data.rooms.0.key', 'global')
            ->assertJsonPath('data.rooms.0.first_unread_id', (string) $first);
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $first]))->assertOk();
        $this->unread()->assertJsonPath('data.total_unread', 1)
            ->assertJsonPath('data.rooms.0.first_unread_id', (string) $last);
    }

    public function test_read_watermarks_are_monotonic_shared_between_devices_and_do_not_read_future_messages(): void
    {
        $room = $this->room();
        $first = $this->message($room, 'bob');
        $second = $this->message($room, 'bob');
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $second]))->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $first]))
            ->assertOk()->assertJsonPath('data.last_read_message_id', (string) $second);
        $third = $this->message($room, 'bob');
        $this->unread(['auth_device_id' => 'another-device'])->assertOk()->assertJsonPath('data.total_unread', 1)
            ->assertJsonPath('data.rooms.0.last_read_message_id', (string) $second)
            ->assertJsonPath('data.rooms.0.first_unread_id', (string) $third);
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $third + 1000]))->assertStatus(422);
    }

    public function test_unread_summary_never_exposes_uninvited_pending_closed_or_expired_private_rooms(): void
    {
        $room = $this->createPrivateRoom();
        $first = $this->message($room, 'alice');
        $bob = ['auth_user_id' => 'bob', 'auth_device_id' => 'device-b'];
        $this->unread($bob)->assertOk()->assertJsonCount(0, 'data.rooms');
        $this->postJson("/api/v4/chat/rooms/$room/read", $bob + ['message_id' => $first])->assertForbidden();
        $invite = DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        $this->postJson("/api/v4/chat/invites/$invite/respond", $bob + ['accept' => true])->assertOk();
        $this->unread($bob)->assertJsonPath('data.total_unread', 1)->assertJsonPath('data.rooms.0.key', 'invite:'.$room);
        $deadline = DB::table('chat_invite_rooms')->where('room_id', $room)->value('idle_expires_at');
        $this->postJson("/api/v4/chat/rooms/$room/read", $bob + ['message_id' => $first])->assertOk();
        $this->assertSame($deadline, DB::table('chat_invite_rooms')->where('room_id', $room)->value('idle_expires_at'));
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", $this->identity())->assertOk();
        $this->unread($bob)->assertJsonCount(0, 'data.rooms')->assertJsonPath('data.total_unread', 0);
        $this->postJson("/api/v4/chat/rooms/$room/read", $bob + ['message_id' => $first])->assertForbidden();
    }

    public function test_unread_movie_counts_require_the_matching_playback_context(): void
    {
        DB::table('n_devices')->insert(['id' => 1, 'user_id' => 'alice', 'device_token' => 'device-a']);
        DB::table('n_active_streams')->insert(['device_id' => 1, 'stream_token' => 'play-a', 'status' => 'active', 'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/rooms/join', $this->identity(['type' => 'movie', 'content_id' => 'film-a']))->assertOk()->json('data.room_id');
        $this->message($room, 'bob');
        $this->unread()->assertJsonCount(0, 'data.rooms');
        $this->unread(['type' => 'movie', 'content_id' => 'film-a'], ['X-Playback-Token' => 'wrong'])->assertJsonCount(0, 'data.rooms');
        $this->unread(['type' => 'movie', 'content_id' => 'film-a'], ['X-Playback-Token' => 'play-a'])
            ->assertJsonPath('data.total_unread', 1)->assertJsonPath('data.rooms.0.key', 'movie:film-a');
    }

    public function test_first_unread_history_can_start_before_the_recent_page_and_is_paginated(): void
    {
        $room = $this->room();
        $ids = [];
        for ($i = 0; $i < 155; $i++) { $ids[] = $this->message($room, 'bob'); }
        $this->unread()->assertJsonPath('data.rooms.0.first_unread_id', (string) $ids[0]);
        $page = $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity(['after' => 0])))
            ->assertOk()->assertJsonPath('data.has_more', true)->json('data.messages');
        $this->assertSame((string) $ids[0], $page[0]['id']);
        $this->assertCount(50, $page);
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $ids[49]]))->assertOk();
        $this->unread()->assertJsonPath('data.total_unread', 105)->assertJsonPath('data.rooms.0.first_unread_id', (string) $ids[50]);
    }

    public function test_banned_viewer_cannot_inspect_counts_or_advance_reads(): void
    {
        $room = $this->room();
        $message = $this->message($room, 'bob');
        DB::table('chat_bans')->insert(['room_id' => 0, 'user_id' => 'alice', 'kind' => 'ban']);
        app(\App\Support\Chat\ChatService::class)->invalidateBan('alice', 0);
        $this->unread()->assertOk()->assertJsonCount(0, 'data.rooms')->assertJsonPath('data.total_unread', 0);
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $message]))->assertForbidden();
    }

    public function test_send_retry_is_idempotent_and_preserves_trusted_sender(): void
    {
        $room = $this->room();
        $request = (string) Str::uuid();
        $first = $this->send($room, 'Hello', $request)->assertCreated()->assertJsonPath('data.user_id', 'alice')->json('data.id');
        $this->send($room, 'Hello', $request)->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, DB::table('chat_messages')->count());
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'created')->count());
        $this->send($room)->assertStatus(429);
    }

    public function test_cursor_history_and_reconnect_catchup_are_ordered_without_gaps(): void
    {
        $room = $this->room();
        $ids = [];
        for ($i = 0; $i < 155; $i++) {
            $ids[] = $this->message($room);
        }
        $url = "/api/v4/chat/rooms/$room/messages";
        $latest = $this->getJson($url.'?'.http_build_query($this->identity()))->assertOk()->json('data');
        $this->assertCount(50, $latest['messages']);
        $this->assertTrue($latest['has_more']);
        $this->assertSame((string) $ids[105], $latest['messages'][0]['id']);
        $older = $this->getJson($url.'?'.http_build_query($this->identity(['before' => $ids[105]])))->assertOk()->json('data.messages');
        $this->assertSame((string) $ids[55], $older[0]['id']);
        $catchup = $this->getJson($url.'?'.http_build_query($this->identity(['after' => $ids[0]])))->assertOk()->json('data');
        $this->assertSame((string) $ids[1], $catchup['messages'][0]['id']);
        $this->assertTrue($catchup['has_more']);
    }

    public function test_live_delta_sees_committed_messages_even_when_recent_cache_is_stale(): void
    {
        $room = $this->room();
        $first = $this->message($room, 'bob', 'First');
        $url = "/api/v4/chat/rooms/$room/messages";
        $this->getJson($url.'?'.http_build_query($this->identity()))->assertOk()->assertJsonCount(1, 'data.messages');
        // Another request commits before cache invalidation/broadcast processing.
        $second = $this->message($room, 'bob', 'Arrives while chat is open');
        $this->assertCount(1, app(\App\Support\Chat\ChatService::class)->recent((int) $room));
        $this->getJson($url.'?'.http_build_query($this->identity(['after' => $first])))->assertOk()
            ->assertJsonPath('data.messages.0.id', (string) $second)
            ->assertJsonPath('data.messages.0.body', 'Arrives while chat is open');
    }

    public function test_delivery_invalidates_recent_cache_before_receivers_can_fetch_history(): void
    {
        $room = $this->room();
        $message = $this->send($room, 'First message in open room')->assertCreated()->json('data.id');
        Cache::store(config('chat.cache_store'))->put('chat:recent:'.$room, [], 10);
        $seenDuringPublish = null;
        Http::fake(function () use ($room, &$seenDuringPublish) {
            // A receiving socket can trigger history before the publish HTTP call returns.
            $seenDuringPublish = app(\App\Support\Chat\ChatService::class)->recent((int) $room);

            return Http::response('{}', 200);
        });
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertIsArray($seenDuringPublish);
        $this->assertCount(1, $seenDuringPublish);
        $this->assertSame($message, $seenDuringPublish[0]['id']);
        $this->assertNotNull(DB::table('chat_outbox')->value('delivered_at'));
    }

    public function test_movie_room_requires_current_owned_playback_on_every_read_and_send(): void
    {
        DB::table('n_devices')->insert(['id' => 1, 'user_id' => 'alice', 'device_token' => 'device-a']);
        DB::table('n_active_streams')->insert(['device_id' => 1, 'stream_token' => 'play-a', 'status' => 'active', 'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        $payload = $this->identity(['type' => 'movie', 'content_id' => 'film-a']);
        $this->postJson('/api/v4/chat/rooms/join', $payload)->assertForbidden();
        $room = $this->withHeader('X-Playback-Token', 'play-a')->postJson('/api/v4/chat/rooms/join', $payload)->assertOk()->json('data.room_id');
        $this->postJson('/api/v4/chat/rooms/join', $this->identity(['type' => 'movie', 'content_id' => 'film-b']))->assertForbidden();
        DB::table('n_active_streams')->update(['status' => 'stopped']);
        $this->travel(6)->seconds();
        $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertForbidden();
        $this->send($room)->assertForbidden();
    }

    public function test_block_filters_history_and_mute_blocks_sending_but_allows_reading(): void
    {
        $room = $this->room();
        $this->message($room, 'bob');
        $this->message($room);
        $this->postJson('/api/v4/chat/blocks', $this->identity(['user_id' => 'bob', 'blocked' => true]))->assertOk();
        $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertOk()->assertJsonCount(1, 'data.messages');
        $this->postJson("/api/v4/chat/rooms/$room/moderate", $this->identity(['action' => 'mute', 'target' => 'alice', 'reason' => 'spam']))->assertOk();
        $this->send($room)->assertForbidden();
        $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/moderate", $this->identity(['action' => 'ban', 'target' => 'alice', 'reason' => 'spam']))->assertOk();
        $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertForbidden();
    }

    public function test_private_socket_auth_is_room_scoped(): void
    {
        $room = $this->room();
        $this->postJson("/api/v4/chat/rooms/$room/auth", $this->identity(['socket_id' => '123.456']))->assertOk()
            ->assertJsonPath('data.auth', 'test-key:'.hash_hmac('sha256', "123.456:".ChatChannels::leased("private-chat.$room"), 'test-secret'));
    }

    public function test_delete_tombstones_history_and_report_retries_do_not_duplicate(): void
    {
        $room = $this->room();
        $id = $this->message($room, 'bob');
        for ($i = 0; $i < 2; $i++) {
            $this->postJson("/api/v4/chat/rooms/$room/messages/$id/report", $this->identity(['reason' => 'spam']))->assertOk();
        }
        $this->assertSame(1, DB::table('chat_reports')->count());
        $this->postJson("/api/v4/chat/rooms/$room/moderate", $this->identity(['action' => 'delete', 'target' => (string) $id, 'reason' => 'spam']))->assertOk();
        $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertOk()->assertJsonPath('data.messages.0.deleted', true)->assertJsonPath('data.messages.0.body', null);
        $this->postJson("/api/v4/chat/rooms/$room/moderate", $this->identity(['action' => 'delete', 'target' => (string) $id, 'reason' => 'retry']))->assertOk();
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'deleted')->count());
        $this->assertSame(1, DB::table('chat_moderation_actions')->where('action', 'delete')->count());

    }

    public function test_outbox_retries_failed_delivery_without_losing_saved_message(): void
    {
        $room = $this->room();
        $this->send($room, 'secret text')->assertCreated();
        Http::fakeSequence()->push('', 500)->push('{}', 200);
        $this->artisan('chat:deliver --once')->assertSuccessful();
        $this->assertNull(DB::table('chat_outbox')->value('delivered_at'));
        $this->travel(5)->seconds();
        $this->artisan('chat:deliver --once')->assertSuccessful();
        $this->assertNotNull(DB::table('chat_outbox')->value('delivered_at'));
        Http::assertSent(fn ($request) => str_contains($request->body(), 'chat.changed') && ! str_contains($request->body(), 'secret text'));
    }

    public function test_server_analytics_are_idempotent_and_do_not_copy_message_text(): void
    {
        config(['database.connections.analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('analytics');
        (require database_path('migrations/2026_10_06_000001_create_analytics_tables.php'))->up();
        $setting = \Mockery::mock(AnalyticsCollectionSetting::class);
        $setting->shouldReceive('enabled')->andReturn(true);
        $this->app->instance(AnalyticsCollectionSetting::class, $setting);
        $room = $this->room();
        $session = (string) Str::uuid();
        $request = (string) Str::uuid();
        $payload = $this->identity(['request_id' => $request, 'body' => 'private message text', 'app_session_id' => $session, 'playback_session_id' => (string) Str::uuid()]);
        $this->postJson("/api/v4/chat/rooms/$room/messages", $payload)->assertCreated();
        $this->postJson("/api/v4/chat/rooms/$room/messages", $payload)->assertOk();
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver --once')->assertSuccessful();
        $this->artisan('chat:deliver --once')->assertSuccessful();
        $this->assertSame(1, DB::connection('analytics')->table('analytics_events')->count());
        $event = DB::connection('analytics')->table('analytics_events')->first();
        $this->assertSame('chat_message_sent', $event->name);
        $this->assertStringNotContainsString('private message text', $event->properties_json);
        $this->assertSame($session, $event->app_session_id);
        DB::purge('analytics');
    }

    public function test_account_erasure_tombstones_chat_and_clears_blocks(): void
    {
        $room = $this->room();
        $id = $this->message($room, 'alice', 'remove me');
        $this->postJson("/api/v4/chat/rooms/$room/read", $this->identity(['message_id' => $id]))->assertOk();
        DB::table('chat_blocks')->insert(['user_id' => 'alice', 'blocked_user_id' => 'bob']);
        DB::transaction(fn () => app(\App\Support\Chat\ChatService::class)->eraseUser('alice'));
        $m = DB::table('chat_messages')->first();
        $this->assertSame('', $m->body);
        $this->assertStringStartsWith('deleted:', $m->user_id);
        $this->assertSame('Deleted viewer', $m->display_name);
        $this->assertSame(0, DB::table('chat_blocks')->count());
        $this->assertSame(0, DB::table('chat_room_reads')->count());
        $this->assertSame('deleted', DB::table('chat_outbox')->value('kind'));
    }

    public function test_admin_can_review_closed_movie_rooms_without_viewer_playback(): void
    {
        $room = DB::table('chat_rooms')->insertGetId(['room_key' => 'movie:film-a', 'type' => 'movie', 'content_id' => 'film-a', 'closed' => true, 'created_at' => now(), 'updated_at' => now()]);
        $first = $this->message((string) $room, 'bob', '<script>alert(1)</script>');
        for ($i = 0; $i < 54; $i++) {
            $this->message((string) $room);
        }
        config(['chat.enabled' => false]);
        $result = $this->getJson('/api/v4/admin/chat/rooms?'.http_build_query($this->identity(['state' => 'closed'])))->assertOk()->json('data');
        $this->assertSame(1, $result['total']);
        $this->assertEquals(55, $result['data'][0]['message_count']);
        $history = $this->getJson("/api/v4/admin/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertOk()->json('data');
        $this->assertCount(50, $history['messages']);
        $this->assertTrue($history['has_more']);
        $older = $this->getJson("/api/v4/admin/chat/rooms/$room/messages?".http_build_query($this->identity(['before' => $history['messages'][0]['id']])))->assertOk()->json('data');
        $this->assertCount(5, $older['messages']);
        $this->assertFalse($older['has_more']);
        $this->getJson("/api/v4/admin/chat/rooms/$room/messages?".http_build_query($this->identity(['q' => '<script>'])))->assertOk()->assertJsonPath('data.messages.0.id', (string) $first);
    }

    public function test_admin_report_resolution_and_restriction_removal_are_audited(): void
    {
        $room = $this->room();
        $id = $this->message($room, 'bob');
        $this->postJson("/api/v4/chat/rooms/$room/messages/$id/report", $this->identity(['reason' => 'spam']))->assertOk();
        $report = $this->getJson('/api/v4/admin/chat/reports?'.http_build_query($this->identity()))->assertOk()->assertJsonPath('data.total', 1)->json('data.data.0.id');
        $this->postJson("/api/v4/chat/reports/$report/resolve", $this->identity(['reason' => 'Reviewed']))->assertOk();
        $this->getJson('/api/v4/admin/chat/reports?'.http_build_query($this->identity()))->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/v4/chat/rooms/0/moderate', $this->identity(['action' => 'ban', 'target' => 'bob', 'reason' => 'spam']))->assertOk();
        $this->getJson('/api/v4/admin/chat/restrictions?'.http_build_query($this->identity(['room_id' => 0])))->assertOk()->assertJsonPath('data.total', 1);
        $this->postJson('/api/v4/chat/rooms/0/moderate', $this->identity(['action' => 'unban', 'target' => 'bob', 'reason' => 'Appeal accepted']))->assertOk();
        $this->getJson('/api/v4/admin/chat/restrictions?'.http_build_query($this->identity()))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v4/admin/chat/audit?'.http_build_query($this->identity()))->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_chat_analytics_apply_platform_room_and_date_filters_without_counting_operational_data(): void
    {
        config(['database.connections.analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('analytics');
        (require database_path('migrations/2026_10_06_000001_create_analytics_tables.php'))->up();
        $room = $this->room();
        $other = DB::table('chat_rooms')->insertGetId(['room_key' => 'movie:film', 'type' => 'movie', 'content_id' => 'film', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['chat_opened', 'ios', $room, 'global'], ['chat_message_sent', 'ios', $room, 'global'], ['chat_message_sent', 'android', $other, 'movie'], ['app_opened', 'ios', $room, 'global']] as [$name,$platform,$id,$type]) {
            DB::connection('analytics')->table('analytics_events')->insert(['event_id' => (string) Str::uuid(), 'user_id' => 'alice', 'device_id' => 'device-a', 'app_session_id' => (string) Str::uuid(), 'name' => $name, 'platform' => $platform, 'app_version' => 'test', 'occurred_at' => now(), 'properties_json' => json_encode(['room_id' => (string) $id, 'room_type' => $type]), 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->getJson('/api/v4/admin/chat/metrics?'.http_build_query($this->identity(['from' => now()->toDateString(), 'to' => now()->toDateString(), 'platform' => 'ios', 'room_type' => 'global'])))->assertOk();
        $response->assertJsonPath('data.analytics_available', true)->assertJsonPath('data.summary.events', 2)->assertJsonPath('data.summary.messages', 1)->assertJsonPath('data.summary.unique_chatters', 1)->assertJsonPath('data.operational.rooms', 2);
        $this->assertCount(1, $response->json('data.platforms'));
        $this->assertCount(1, $response->json('data.rooms'));
        $this->getJson('/api/v4/admin/chat/metrics?'.http_build_query($this->identity(['from' => '2025-01-01', 'to' => '2026-10-24'])))->assertStatus(422);
        DB::purge('analytics');
    }

    public function test_linked_playback_metrics_deduplicate_sessions_and_verify_the_viewer(): void
    {
        config(['database.connections.analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('analytics');
        (require database_path('migrations/2026_10_06_000001_create_analytics_tables.php'))->up();
        $room = $this->room();
        $playback = (string) Str::uuid();
        DB::connection('analytics')->table('playback_sessions')->insert(['session_id' => $playback, 'user_id' => 'alice', 'device_id' => 'device-a', 'content_id' => 'film', 'content_type' => 'movie', 'revision' => 1, 'state' => 'final', 'started_at' => now(), 'ended_at' => now(), 'watched_ms' => 3600000, 'completed' => true, 'platform' => 'ios', 'app_version' => 'test', 'metrics_json' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['chat_opened', 'alice'], ['chat_message_sent', 'alice'], ['chat_opened', 'bob']] as [$name,$uid]) {
            DB::connection('analytics')->table('analytics_events')->insert(['event_id' => (string) Str::uuid(), 'user_id' => $uid, 'device_id' => 'device-a', 'app_session_id' => (string) Str::uuid(), 'name' => $name, 'platform' => 'ios', 'app_version' => 'test', 'occurred_at' => now(), 'properties_json' => json_encode(['room_id' => $room, 'room_type' => 'global', 'playback_session_id' => $playback]), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson('/api/v4/admin/chat/metrics?'.http_build_query($this->identity()))->assertOk()->assertJsonPath('data.analytics_available', true)->assertJsonPath('data.linked_playback.sessions', 1);
        DB::purge('analytics');
    }

    public function test_analytics_outage_is_reported_without_hiding_moderation_health(): void
    {
        $this->room();
        config(['database.connections.analytics' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('analytics');
        $this->getJson('/api/v4/admin/chat/metrics?'.http_build_query($this->identity()))->assertOk()->assertJsonPath('data.analytics_available', false)->assertJsonPath('data.summary', null)->assertJsonPath('data.operational.rooms', 1);
        DB::purge('analytics');
    }

    public function test_admin_chat_routes_reject_non_admins_and_anonymous_visitors(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('admin_uid');
        });
        config(['services.admin_qr.allowed_uids' => [], 'services.admin_whatsapp.allowed_numbers' => []]);
        $this->withMiddleware(AdminTokenMiddleware::class);
        $this->getJson('/api/v4/admin/chat/rooms?'.http_build_query($this->identity()))->assertForbidden();
        $this->withMiddleware(AuthTokenMiddleware::class);
        $this->getJson('/api/v4/admin/chat/metrics')->assertUnauthorized();
        foreach (collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v4/admin/chat/')) as $route) {
            $this->assertContains('auth.token', $route->gatherMiddleware());
            $this->assertContains('admin.token', $route->gatherMiddleware());
        }
    }

    public function test_routes_require_auth_and_moderation_requires_admin(): void
    {
        foreach (collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v4/chat/')) as $route) {
            $this->assertContains('auth.token', $route->gatherMiddleware());
            if (str_ends_with($route->uri(), '/moderate') || str_contains($route->uri(), 'chat/reports')) {
                $this->assertContains('admin.token', $route->gatherMiddleware());
            }
        }
    }

    public function test_retention_excludes_old_messages_but_keeps_pending_reports_for_review(): void
    {
        $room = $this->room();
        $id = $this->message($room);
        DB::table('chat_messages')->where('id', $id)->update(['created_at' => now()->subDays(31)]);
        $this->postJson("/api/v4/chat/rooms/$room/messages/$id/report", $this->identity(['reason' => 'spam']))->assertOk();
        $this->artisan('chat:prune')->assertSuccessful();
        $this->assertSame(1, DB::table('chat_messages')->count());
        $this->getJson("/api/v4/chat/rooms/$room/messages?".http_build_query($this->identity()))->assertOk()->assertJsonCount(0, 'data.messages');
        DB::table('chat_messages')->update(['created_at' => now()->subDays(91)]);
        $this->artisan('chat:prune')->assertSuccessful();
        $this->assertSame(0, DB::table('chat_messages')->count());
    }
    private function linkedInvitation(): array
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['chat.whatsapp_enabled' => true]);
        DB::table('user')->insert(['uid' => 'bob', 'name' => 'Bob', 'auth_phone' => '9876543210', 'country_code' => '+91']);
        $response = $this->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'global', 'room_name' => 'Friends Room', 'invitee_ids' => ['bob'],
        ]))->assertCreated();
        $job = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\SendChatInvitationWhatsApp::class)->first();
        $this->assertNotNull($job);
        $this->assertStringNotContainsString($job->token, $response->getContent());
        return [$job, DB::table('chat_room_invites')->where('id', $job->inviteId)->first()];
    }

    public function test_invitation_link_checks_account_before_revealing_details(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        $this->assertSame(hash('sha256', $job->token), $invite->link_token_hash);
        $this->getJson('/api/v4/chat/invitation-links/'.$job->token.'?auth_user_id=alice')
            ->assertForbidden()->assertJsonPath('error.code', 'INVITE_WRONG_ACCOUNT')->assertJsonPath('data', null);
        $this->getJson('/api/v4/chat/invitation-links/'.$job->token.'?auth_user_id=bob')
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.room_name', 'Friends Room');
        $this->getJson('/api/v4/chat/invitation-links/'.str_repeat('a', 64).'?auth_user_id=bob')
            ->assertNotFound()->assertJsonPath('error.code', 'INVITE_INVALID');
    }

    public function test_invitation_link_accept_is_idempotent_and_accepted_room_can_reopen_after_invite_expiry(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        $url = '/api/v4/chat/invitation-links/'.$job->token;
        $this->postJson($url.'/respond', ['auth_user_id' => 'alice', 'accept' => true])->assertForbidden();
        $this->postJson($url.'/respond', ['auth_user_id' => 'bob', 'accept' => true])->assertOk()->assertJsonPath('data.accepted', true);
        $this->postJson($url.'/respond', ['auth_user_id' => 'bob', 'accept' => true])->assertOk()->assertJsonPath('data.accepted', true);
        DB::table('chat_room_invites')->where('id', $invite->id)->update(['expires_at' => now()->subDay()]);
        $this->getJson($url.'?auth_user_id=bob')->assertOk()->assertJsonPath('data.accepted', true);
        DB::table('chat_rooms')->where('id', $invite->room_id)->update(['closed' => true]);
        $this->getJson($url.'?auth_user_id=bob')->assertStatus(410)->assertJsonPath('error.code', 'ROOM_ENDED');
    }

    public function test_invitation_link_decline_and_pending_expiry_have_clear_errors(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        $url = '/api/v4/chat/invitation-links/'.$job->token;
        DB::table('chat_room_invites')->where('id', $invite->id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson($url.'?auth_user_id=bob')->assertStatus(410)->assertJsonPath('error.code', 'INVITE_EXPIRED');
        DB::table('chat_room_invites')->where('id', $invite->id)->update(['expires_at' => now()->addDay()]);
        $this->postJson($url.'/respond', ['auth_user_id' => 'bob', 'accept' => false])->assertOk()->assertJsonPath('data.accepted', false);
        $this->getJson($url.'?auth_user_id=bob')->assertStatus(410)->assertJsonPath('error.code', 'INVITE_DECLINED');
        $this->postJson($url.'/respond', ['auth_user_id' => 'bob', 'accept' => true])->assertStatus(410);
    }

    public function test_whatsapp_invitation_sends_once_without_a_recipient_preference(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        $service = \Mockery::mock(\App\Services\WhatsAppCloudService::class);
        $service->shouldReceive('sendTemplate')->once()->with('919876543210', 'zostream_room_invitation',
            ['Bob', 'Alice', 'Friends Room', 'Join the conversation in a private chat room.'], $job->token, 'en_US')
            ->andReturn(new \App\Models\WhatsAppMessage());
        $job->handle($service);
        $job->handle($service);
        $this->assertNotNull(DB::table('chat_room_invites')->where('id', $invite->id)->value('whatsapp_sent_at'));
    }

    public function test_whatsapp_invitation_normalizes_the_same_legacy_and_international_accounts_as_lookup(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        $cases = [
            ['9876543210', null, '919876543210'],
            ['+91 (98765) 43210', '+91', '919876543210'],
            ['00919876543210', '+91', '919876543210'],
            ['919876543210', '+91', '919876543210'],
            ['09876543210', '+91', '919876543210'],
            ['07700900123', '+44', '447700900123'],
        ];
        foreach ($cases as [$number, $country, $expected]) {
            DB::table('user')->where('uid', 'bob')->update(['auth_phone' => $number, 'country_code' => $country]);
            DB::table('chat_room_invites')->where('id', $invite->id)->update(['whatsapp_sent_at' => null]);
            $service = \Mockery::mock(\App\Services\WhatsAppCloudService::class);
            $service->shouldReceive('sendTemplate')->once()->with($expected, 'zostream_room_invitation',
                ['Bob', 'Alice', 'Friends Room', 'Join the conversation in a private chat room.'], $job->token, 'en_US')
                ->andReturn(new \App\Models\WhatsAppMessage());
            $job->handle($service);
            $this->assertNotNull(DB::table('chat_room_invites')->where('id', $invite->id)->value('whatsapp_sent_at'));
        }
    }

    public function test_whatsapp_invitation_logs_an_unusable_phone_without_exposing_it_or_the_token(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        DB::table('user')->where('uid', 'bob')->update(['auth_phone' => '12345', 'country_code' => null]);
        \Illuminate\Support\Facades\Log::shouldReceive('notice')->once()->with('Chat WhatsApp invitation skipped',
            ['invite_id' => $invite->id, 'reason' => 'recipient_phone_unavailable']);
        $service = \Mockery::mock(\App\Services\WhatsAppCloudService::class);
        $service->shouldNotReceive('sendTemplate');
        $job->handle($service);
        $this->assertNull(DB::table('chat_room_invites')->where('id', $invite->id)->value('whatsapp_sent_at'));
    }

    public function test_whatsapp_diagnostics_report_disabled_delivery_without_sending_a_message(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        config(['chat.whatsapp_enabled' => false]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->artisan('chat:whatsapp-status', ['--invite' => $invite->id])
            ->expectsOutputToContain('CHAT_WHATSAPP_ENABLED')
            ->expectsOutputToContain('MISSING - run migrations')
            ->expectsOutputToContain('separate queue:work process')->assertFailed();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_contact_number_lookup_is_normalized_and_rejects_partial_suffixes(): void
    {
        DB::table('user')->insert(['uid' => 'bob', 'name' => 'Bob', 'auth_phone' => '9876543210', 'country_code' => '+91']);
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+91 (98765) 43210']))
            ->assertOk()->assertJsonPath('data.user_id', 'bob');
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '6543210']))->assertNotFound();
    }

    public function test_contact_lookup_supports_legacy_local_and_international_numbers(): void
    {
        DB::table('user')->insert(['uid' => 'bob', 'name' => 'Bob', 'auth_phone' => '9876543210']);
        foreach (['+91 98765 43210', '00919876543210', '9876543210'] as $phone) {
            $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => $phone]))
                ->assertOk()->assertJsonPath('data.user_id', 'bob');
        }
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+44 9876543210']))->assertNotFound();
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '6543210']))->assertNotFound();

        DB::table('user')->where('uid', 'bob')->update(['auth_phone' => '+91 (98765) 43210']);
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+919876543210']))
            ->assertOk()->assertJsonPath('data.user_id', 'bob');
        DB::table('user')->where('uid', 'bob')->update(['auth_phone' => '00919876543210']);
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+919876543210']))
            ->assertOk()->assertJsonPath('data.user_id', 'bob');
    }

    public function test_contact_lookup_respects_country_and_rejects_ambiguous_accounts(): void
    {
        DB::table('user')->insert(['uid' => 'bob', 'name' => 'Bob', 'auth_phone' => '07700900123', 'country_code' => '+44']);
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+447700900123']))
            ->assertOk()->assertJsonPath('data.user_id', 'bob');
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+917700900123']))->assertNotFound();
        DB::table('user')->insert(['uid' => 'charlie', 'name' => 'Charlie', 'auth_phone' => '7700900123', 'country_code' => '+44']);
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+447700900123']))->assertUnprocessable();
    }

    public function test_country_selector_is_not_required_to_find_stored_national_or_international_phone(): void
    {
        DB::table('user')->insert(['uid' => 'bob', 'name' => 'Bob', 'auth_phone' => '+44 7700 900123', 'country_code' => '+44']);
        foreach (['7700900123', '07700900123', '+447700900123', '00447700900123'] as $input) {
            $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => $input]))
                ->assertOk()->assertJsonPath('data.user_id', 'bob');
        }
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '900123']))->assertUnprocessable();
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+917700900123']))->assertNotFound();
        DB::table('user')->where('uid', 'bob')->update(['auth_phone' => '07700900123']);
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '7700900123']))->assertOk()->assertJsonPath('data.user_id', 'bob');
    }

    public function test_national_phone_ambiguity_requires_explicit_full_number_instead_of_guessing_country(): void
    {
        DB::table('user')->insert([
            ['uid' => 'bob', 'name' => 'Bob', 'auth_phone' => '+447700900123', 'country_code' => '+44'],
            ['uid' => 'charlie', 'name' => 'Charlie', 'auth_phone' => '7700900123', 'country_code' => '+91'],
        ]);
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '7700900123']))->assertUnprocessable();
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+447700900123']))->assertOk()->assertJsonPath('data.user_id', 'bob');
        $this->postJson('/api/v4/chat/invitees/lookup', $this->identity(['phone' => '+917700900123']))->assertOk()->assertJsonPath('data.user_id', 'charlie');
    }

    private function privateWatchFixture(): string
    {
        $this->freezeTime();
        $room = $this->createPrivateRoom();
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        Schema::create('movie', function (Blueprint $t) {
            $t->string('num'); $t->string('id')->nullable(); $t->string('title'); $t->boolean('isPremium')->default(false); $t->boolean('isPayPerView')->default(false);
        });
        Schema::create('n_subscriptions', function (Blueprint $t) {
            $t->id(); $t->string('user_id'); $t->boolean('is_active'); $t->timestamp('end_at');
        });
        DB::table('movie')->insert(['num' => '101', 'id' => 'film-a', 'title' => 'Film A']);
        DB::table('n_devices')->insert(['id' => 1, 'user_id' => 'alice', 'device_token' => 'device-a']);
        DB::table('n_active_streams')->insert(['device_id' => 1, 'stream_token' => 'play-a', 'status' => 'active',
            'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        return $room;
    }

    private function sendPrivateWatch(string $room, ?string $request = null)
    {
        return $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson("/api/v4/chat/rooms/$room/messages", $this->identity([
            'request_id' => $request ?? (string) Str::uuid(), 'body' => '/watch',
            'watch' => ['content_type' => 'movie', 'content_id' => 'film-a', 'position_ms' => 12000],
        ]));
    }

    public function test_watch_feed_delivers_card_and_current_session_state_together(): void
    {
        $room = $this->privateWatchFixture();
        $auth = $this->socketAuth($room, 'bob', $this->socketSession('bob'));
        $sent = $this->sendPrivateWatch($room)->assertCreated();
        $session = $sent->json('data.watch_invite.session_id');
        $row = DB::table('chat_outbox')->where('kind', 'feed_created')->first();

        foreach ([true, false] as $active) {
            // A retry after the host leaves must carry ended state, even though
            // the original message was created while the session was active.
            if (! $active) { DB::table('n_active_streams')->update(['status' => 'stopped']); }
            Http::fake();
            app(\App\Support\Chat\ChatLiveDelivery::class)->deliver($row, fn () => null);
            Http::assertSent(function ($request) use ($auth, $row, $room, $session, $active) {
                $body = json_decode($request->body(), true);
                $data = json_decode($body['data'], true);
                return $body['name'] === 'chat.message' && $body['channels'] === [$auth['feed_channel']]
                    && $data['message']['watch_invite']['session_id'] === $session
                    && $data['watch']['room_id'] === $room
                    && $data['watch']['event_id'] === (string) $row->id
                    && $data['watch']['active'] === $active
                    && $data['watch']['session_id'] === ($active ? $session : null);
            });
        }
    }

    public function test_cached_watch_history_and_send_retry_reflect_stopped_host_before_worker_runs(): void
    {
        $room = $this->privateWatchFixture();
        $request = (string) Str::uuid();
        $sent = $this->sendPrivateWatch($room, $request)->assertCreated();
        $session = $sent->json('data.watch_invite.session_id');
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=bob")
            ->assertOk()->assertJsonPath('data.messages.0.watch_invite.active', true);
        DB::table('n_active_streams')->update(['status' => 'stopped']);
        // The cached message body is still present and no stop callback ran.
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=bob")
            ->assertOk()->assertJsonPath('data.messages.0.watch_invite.active', false);
        $this->sendPrivateWatch($room, $request)->assertOk()->assertJsonPath('data.watch_invite.active', false);
        $this->getJson("/api/v4/chat/rooms/$room/watch-status?auth_user_id=bob")
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.latest_session_id', $session);
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])
            ->assertOk()->assertJsonPath('data.watch_session', null);
    }

    public function test_worker_ends_abandoned_private_watch_once_and_stops_bound_guest_without_closing_chat(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        DB::table('n_devices')->insert(['id' => 2, 'user_id' => 'bob', 'device_token' => 'device-b']);
        $guest = DB::table('n_active_streams')->insertGetId(['device_id' => 2, 'stream_token' => 'play-b', 'status' => 'active',
            'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        $this->withHeaders(['X-Playback-Token' => 'play-b'])->postJson("/api/v4/chat/rooms/$room/watch-heartbeat", [
            'auth_user_id' => 'bob', 'auth_device_id' => 'device-b', 'session_id' => $session,
        ])->assertOk();
        DB::table('chat_outbox')->delete();
        Http::fake();
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertTrue((bool) DB::table('chat_watch_sessions')->where('id', $session)->value('active'));
        DB::table('n_active_streams')->where('stream_token', 'play-a')->update(['last_ping' => now()->subMinutes(4)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertFalse((bool) DB::table('chat_watch_sessions')->where('id', $session)->value('active'));
        $this->assertSame('stopped', DB::table('n_active_streams')->where('id', $guest)->value('status'));
        $this->assertTrue((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'watch_status')->whereNotNull('delivered_at')->count());
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'watch_status')->count());
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'bob'])->assertStatus(410);
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=bob")
            ->assertOk()->assertJsonPath('data.messages.0.watch_invite.active', false);
    }

    public function test_worker_ends_replaced_watch_token_and_late_host_ping_cannot_revive_session(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        DB::table('n_active_streams')->update(['stream_token' => 'replacement']);
        app(\App\Support\Chat\PrivateWatchService::class)->endInactiveSessions(\Illuminate\Http\Request::create('/'));
        $this->assertFalse((bool) DB::table('chat_watch_sessions')->where('id', $session)->value('active'));
        DB::table('n_active_streams')->update(['stream_token' => 'play-a', 'last_ping' => now()]);
        $this->getJson("/api/v4/chat/rooms/$room/watch-status?auth_user_id=bob")
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.latest_session_id', $session);
    }

    public function test_new_watch_rooms_resolve_public_playback_ids_instead_of_database_row_numbers(): void
    {
        $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch Film A', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", $this->identity())->assertOk()
            ->assertJsonPath('data.content_title', 'Film A')->assertJsonPath('data.content_id', 'film-a');
        $this->getJson('/api/v4/chat/invites?auth_user_id=bob')->assertOk()->assertJsonPath('data.invites.0.content_title', 'Film A');
        $invite = DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        $this->postJson("/api/v4/chat/invites/$invite/respond", ['auth_user_id' => 'bob', 'accept' => true])->assertOk()
            ->assertJsonPath('data.content_id', 'film-a');
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])->assertOk()
            ->assertJsonPath('data.content_title', 'Film A');
    }

    public function test_watch_command_keeps_private_room_and_joins_at_current_host_position(): void
    {
        $room = $this->privateWatchFixture();
        $id = (string) Str::uuid();
        $sent = $this->sendPrivateWatch($room, $id)->assertCreated()->assertJsonPath('data.watch_invite.content_title', 'Film A');
        $session = $sent->json('data.watch_invite.session_id');
        $this->sendPrivateWatch($room, $id)->assertOk()->assertJsonPath('data.watch_invite.session_id', $session);
        $this->assertSame(1, DB::table('chat_watch_sessions')->count());
        $this->assertNull(DB::table('chat_invite_rooms')->where('room_id', $room)->value('content_type'));
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/position", $this->identity(['position_ms' => 45000]))
            ->assertOk()->assertJsonPath('data.position_ms', 45000);
        $bob = ['auth_user_id' => 'bob', 'auth_device_id' => 'device-b'];
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", $bob)
            ->assertOk()->assertJsonPath('data.watch_position_ms', 45000)->assertJsonPath('data.room_id', $room);
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", $bob)->assertOk()
            ->assertJsonPath('data.content_type', null)->assertJsonPath('data.watch_session.session_id', $session);
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/position", $bob + ['position_ms' => 1])->assertForbidden();
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'stranger'])->assertForbidden();
    }

    public function test_watch_command_requires_creator_and_active_playback_and_rejects_ppv(): void
    {
        $room = $this->privateWatchFixture();
        $payload = ['request_id' => (string) Str::uuid(), 'body' => '/watch',
            'watch' => ['content_type' => 'movie', 'content_id' => 'film-a']];
        $this->postJson("/api/v4/chat/rooms/$room/messages", $payload + ['auth_user_id' => 'bob'])->assertForbidden();
        $this->withHeaders(['X-Playback-Token' => 'wrong'])->postJson("/api/v4/chat/rooms/$room/messages", $this->identity($payload))->assertForbidden();
        Cache::flush();
        DB::table('movie')->update(['isPayPerView' => true]);
        $this->sendPrivateWatch($room)->assertForbidden();
        $this->assertSame(0, DB::table('chat_watch_sessions')->count());
        $this->assertSame(0, DB::table('chat_messages')->count());
    }

    public function test_stale_watch_cards_fail_but_private_chat_remains_available(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        Cache::flush();
        $next = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $bob = ['auth_user_id' => 'bob'];
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=bob")->assertOk()
            ->assertJsonPath('data.messages.0.watch_invite.active', false)
            ->assertJsonPath('data.messages.1.watch_invite.active', true);
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", $bob)->assertStatus(410);
        DB::table('n_active_streams')->update(['stream_token' => 'replacement']);
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$next/join", $bob)->assertStatus(410);
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", $bob)->assertOk();
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=bob")->assertOk()->assertJsonCount(2, 'data.messages');
        Cache::flush();
        $this->send($room, 'Still chatting')->assertCreated();
    }

    public function test_private_watch_join_requires_subscription_for_premium_title(): void
    {
        $room = $this->privateWatchFixture();
        DB::table('movie')->update(['isPremium' => true]);
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'bob'])->assertForbidden();
        DB::table('n_subscriptions')->insert(['user_id' => 'bob', 'is_active' => true, 'end_at' => now()->addDay()]);
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'bob'])->assertOk();
    }

    public function test_private_watch_episode_card_routes_to_the_correct_season_and_expires_with_host_stream(): void
    {
        $room = $this->privateWatchFixture();
        Schema::create('seasons', function (Blueprint $t) { $t->id(); $t->integer('season_number'); });
        Schema::create('episodes', function (Blueprint $t) {
            $t->string('num'); $t->string('id')->nullable(); $t->string('title'); $t->integer('season_id'); $t->integer('episode_number');
        });
        DB::table('seasons')->insert(['id' => 7, 'season_number' => 2]);
        DB::table('episodes')->insert(['num' => '202', 'id' => 'episode-a', 'title' => 'Episode A', 'season_id' => 7, 'episode_number' => 3]);
        DB::table('n_active_streams')->update(['content_type' => 'episode', 'content_key' => 'episode-a']);
        $card = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson("/api/v4/chat/rooms/$room/messages", $this->identity([
            'request_id' => (string) Str::uuid(), 'body' => '/watch',
            'watch' => ['content_type' => 'episode', 'content_id' => 'episode-a'],
        ]))->assertCreated();
        $session = $card->json('data.watch_invite.session_id');
        $url = "/api/v4/chat/rooms/$room/watch-sessions/$session/join";
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertOk()->assertJsonPath('data.season_id', 7)
            ->assertJsonPath('data.season_number', 2)->assertJsonPath('data.episode_number', 3);
        DB::table('chat_messages')->where('id', $card->json('data.id'))->update(['deleted_at' => now()]);
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertStatus(410);
        DB::table('chat_messages')->where('id', $card->json('data.id'))->update(['deleted_at' => null]);
        DB::table('n_active_streams')->update(['last_ping' => now()->subMinutes(4)]);
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertStatus(410);
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])->assertOk();
    }

    public function test_watch_sync_and_rejoin_survive_three_minutes_and_private_chat_idle_deadline(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $url = "/api/v4/chat/rooms/$room/watch-sessions/$session/position";
        $this->travel(4)->minutes();
        $this->postJson($url, $this->identity(['position_ms' => 25000, 'paused' => true]))->assertOk();
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertOk()->assertJsonPath('data.paused', true);
        $this->travel(61)->minutes();
        $this->postJson($url, $this->identity(['position_ms' => 32000, 'paused' => false]))->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'bob'])
            ->assertOk()->assertJsonPath('data.watch_position_ms', 32000);
        DB::table('n_active_streams')->update(['status' => 'stopped']);
        $this->postJson($url, $this->identity(['position_ms' => 1]))->assertStatus(410);
        $this->assertSame('stopped', DB::table('n_active_streams')->value('status'));
    }

    public function test_invitee_cannot_refresh_creator_stream_using_a_copied_playback_token(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $lastPing = DB::table('n_active_streams')->value('last_ping');
        $this->travel(4)->minutes();
        $this->withHeaders(['X-Playback-Token' => 'play-a'])
            ->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/position", ['auth_user_id' => 'bob', 'auth_device_id' => 'device-a'])
            ->assertStatus(410);
        $this->assertSame($lastPing, DB::table('n_active_streams')->value('last_ping'));
    }

    public function test_watch_counts_distinguish_unique_joins_from_active_invitee_players(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $base = "/api/v4/chat/rooms/$room";
        $bob = ['auth_user_id' => 'bob', 'auth_device_id' => 'device-b'];
        $this->postJson("$base/watch-sessions/$session/join", $bob)->assertOk();
        $this->postJson("$base/watch-sessions/$session/join", $bob)->assertOk();
        $this->getJson("$base/watch-status?auth_user_id=alice")->assertOk()
            ->assertJsonPath('data.joined_count', 1)->assertJsonPath('data.streaming_count', 0);
        DB::table('n_devices')->insert(['id' => 2, 'user_id' => 'bob', 'device_token' => 'device-b']);
        DB::table('n_active_streams')->insert(['device_id' => 2, 'stream_token' => 'play-b', 'status' => 'active',
            'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        $this->withHeaders(['X-Playback-Token' => 'play-b'])->postJson("$base/watch-sessions/$session/position", $bob)->assertOk();
        $this->getJson("$base/watch-status?auth_user_id=alice")->assertOk()->assertJsonPath('data.streaming_count', 1);
        DB::table('n_active_streams')->where('device_id', 2)->update(['status' => 'stopped']);
        $this->getJson("$base/watch-status?auth_user_id=alice")->assertOk()
            ->assertJsonPath('data.session_id', $session)->assertJsonPath('data.joined_count', 1)->assertJsonPath('data.streaming_count', 0);
        $this->postJson("$base/watch-sessions/$session/join", $bob)->assertOk();
        DB::table('n_active_streams')->where('device_id', 2)->update(['status' => 'active']);
        $this->travel(151)->seconds();
        $this->getJson("$base/watch-status?auth_user_id=alice")->assertOk()->assertJsonPath('data.streaming_count', 0);
    }

    public function test_realtime_watch_controls_preserve_pause_and_reject_delayed_updates(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $url = "/api/v4/chat/rooms/$room/watch-sessions/$session/position";
        $this->postJson($url, $this->identity(['position_ms' => 10000, 'paused' => false, 'control_sequence' => 10]))
            ->assertOk()->assertJsonPath('data.revision', '10');
        $this->travel(2)->seconds();
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertOk()->assertJsonPath('data.position_ms', 12000);
        $this->postJson($url, $this->identity(['position_ms' => 12000, 'paused' => true, 'control_sequence' => 12]))->assertOk();
        $this->postJson($url, $this->identity(['position_ms' => 20000, 'paused' => false, 'control_sequence' => 11]))
            ->assertOk()->assertJsonPath('data.paused', true)->assertJsonPath('data.revision', '12');
        $this->travel(2)->seconds();
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertOk()->assertJsonPath('data.position_ms', 12000)->assertJsonPath('data.paused', true);
        $this->postJson($url, ['auth_user_id' => 'bob', 'position_ms' => 0, 'paused' => false, 'control_sequence' => 99])->assertForbidden();
        $this->assertSame(2, DB::table('chat_outbox')->where('kind', 'watch_control')->count());
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(fn ($request) => ($request->data()['name'] ?? null) === 'chat.changed'
            && json_decode($request->data()['data'] ?? '{}', true)['kind'] === 'watch_control');
    }

    public function test_watch_heartbeat_keeps_session_alive_without_writing_playback_controls(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $base = "/api/v4/chat/rooms/$room";
        $this->postJson("$base/watch-sessions/$session/position", $this->identity(['position_ms' => 10000, 'paused' => false]))->assertOk();
        $bob = ['auth_user_id' => 'bob', 'auth_device_id' => 'device-b'];
        $this->postJson("$base/watch-sessions/$session/join", $bob)->assertOk();
        DB::table('n_devices')->insert(['id' => 2, 'user_id' => 'bob', 'device_token' => 'device-b']);
        DB::table('n_active_streams')->insert(['device_id' => 2, 'stream_token' => 'play-b', 'status' => 'active',
            'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        for ($i = 0; $i < 5; $i++) {
            $this->travel(60)->seconds();
            $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson("$base/watch-heartbeat", $this->identity(['session_id' => $session]))
                ->assertOk()->assertJsonPath('data.active', true)->assertJsonMissingPath('data.position_ms');
            $this->withHeaders(['X-Playback-Token' => 'play-b'])->postJson("$base/watch-heartbeat", $bob + ['session_id' => $session])->assertOk();
        }
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'watch_control')->count());
        $this->getJson("$base/watch-status?auth_user_id=alice")->assertOk()->assertJsonPath('data.streaming_count', 1);
        $this->postJson("$base/watch-sessions/$session/position", $bob)->assertOk()->assertJsonPath('data.position_ms', 310000);
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(function ($request) use ($session) {
            $event = json_decode($request->data()['data'] ?? '{}', true);
            $watch = $event['watch'] ?? [];
            return ($watch['session_id'] ?? null) === $session && ($watch['streaming_count'] ?? null) === 1
                && ($watch['playback']['position_ms'] ?? null) === 310000 && isset($watch['event_id'])
                && !str_contains(json_encode($watch), 'play-a') && !str_contains(json_encode($watch), 'play-b');
        });
        $this->postJson("$base/watch-heartbeat", ['auth_user_id' => 'stranger', 'session_id' => $session])->assertForbidden();
    }

    public function test_watch_heartbeat_samples_are_host_only_and_reject_delayed_controls(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $url = "/api/v4/chat/rooms/$room/watch-heartbeat";
        $this->postJson($url, $this->identity(['session_id' => $session, 'position_ms' => 10000, 'paused' => true, 'control_sequence' => 20]))
            ->assertOk()->assertJsonPath('data.revision', '20')->assertJsonPath('data.active', true)->assertJsonMissingPath('data.position_ms');
        $this->postJson($url, ['auth_user_id' => 'bob', 'session_id' => $session, 'position_ms' => 999, 'paused' => false, 'control_sequence' => 100])
            ->assertForbidden();
        $this->postJson($url, $this->identity(['session_id' => $session, 'position_ms' => 1, 'paused' => false, 'control_sequence' => 19]))->assertOk();
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/position", ['auth_user_id' => 'bob'])->assertOk()
            ->assertJsonPath('data.position_ms', 10000)->assertJsonPath('data.paused', true)->assertJsonPath('data.revision', '20');
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'watch_control')->count());
    }

    public function test_creator_stream_end_stops_only_the_bound_guest_watch_stream(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        DB::table('n_devices')->insert(['id' => 2, 'user_id' => 'bob', 'device_token' => 'device-b']);
        $guest = DB::table('n_active_streams')->insertGetId(['device_id' => 2, 'stream_token' => 'play-b', 'status' => 'active',
            'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        $this->withHeaders(['X-Playback-Token' => 'play-b'])->postJson("/api/v4/chat/rooms/$room/watch-heartbeat", [
            'auth_user_id' => 'bob', 'auth_device_id' => 'device-b', 'session_id' => $session,
        ])->assertOk();
        DB::table('n_active_streams')->where('stream_token', 'play-a')->update(['status' => 'stopped']);
        app(\App\Support\Chat\PrivateWatchService::class)->streamChanged(\Illuminate\Http\Request::create('/', 'POST', ['auth_user_id' => 'alice']), 1);
        $this->assertSame('stopped', DB::table('n_active_streams')->where('id', $guest)->value('status'));
        $this->assertFalse((bool) DB::table('chat_watch_sessions')->where('id', $session)->value('active'));
        $this->assertFalse(app(\App\Support\Chat\PrivateWatchService::class)->snapshot((int) $room)['active']);
        $this->postJson("/api/v4/chat/rooms/$room/watch-sessions/$session/join", ['auth_user_id' => 'bob'])->assertStatus(410);
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=bob")->assertOk();
    }

    public function test_creator_end_does_not_stop_a_guests_replaced_independent_stream_token(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        DB::table('n_devices')->insert(['id' => 2, 'user_id' => 'bob', 'device_token' => 'device-b']);
        $guest = DB::table('n_active_streams')->insertGetId(['device_id' => 2, 'stream_token' => 'play-b', 'status' => 'active',
            'content_type' => 'movie', 'content_key' => 'film-a', 'last_ping' => now()]);
        $this->withHeaders(['X-Playback-Token' => 'play-b'])->postJson("/api/v4/chat/rooms/$room/watch-heartbeat", [
            'auth_user_id' => 'bob', 'auth_device_id' => 'device-b', 'session_id' => $session,
        ])->assertOk();
        DB::table('n_active_streams')->where('id', $guest)->update(['stream_token' => 'new-independent-token']);
        DB::table('n_active_streams')->where('stream_token', 'play-a')->update(['status' => 'stopped']);
        app(\App\Support\Chat\PrivateWatchService::class)->streamChanged(\Illuminate\Http\Request::create('/', 'POST', ['auth_user_id' => 'alice']), 1);
        $this->assertSame('active', DB::table('n_active_streams')->where('id', $guest)->value('status'));
        $this->assertFalse((bool) DB::table('chat_watch_sessions')->where('id', $session)->value('active'));
    }

    public function test_muted_watch_guest_can_resync_but_cannot_publish_creator_position(): void
    {
        $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch room', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('chat_bans')->insert(['room_id' => $room, 'user_id' => 'bob', 'kind' => 'mute']);
        $this->postJson("/api/v4/chat/invite-rooms/$room/position", ['auth_user_id' => 'bob'])->assertOk()
            ->assertJsonPath('data.is_host', false);
        $this->postJson("/api/v4/chat/invite-rooms/$room/position", ['auth_user_id' => 'bob', 'position_ms' => 0])->assertForbidden();
    }

    public function test_stream_stop_pushes_updated_counts_and_host_session_end(): void
    {
        $room = $this->privateWatchFixture();
        $session = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        DB::table('n_active_streams')->update(['status' => 'stopped']);
        app(\App\Support\Chat\PrivateWatchService::class)->streamChanged(\Illuminate\Http\Request::create('/', 'POST'), 1);
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'watch_status')->count());
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(function ($request) {
            $event = json_decode($request->data()['data'] ?? '{}', true);
            return ($event['kind'] ?? null) === 'watch_status' && array_key_exists('session_id', $event['watch'] ?? [])
                && $event['watch']['session_id'] === null && $event['watch']['playback'] === null;
        });
    }

    public function test_live_card_status_follows_stream_lifecycle_and_replacement(): void
    {
        $room = $this->privateWatchFixture();
        $first = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $url = "/api/v4/chat/rooms/$room/watch-status?auth_user_id=bob";
        $this->getJson($url)->assertOk()->assertJsonPath('data.session_id', $first);
        Cache::flush();
        $next = $this->sendPrivateWatch($room)->assertCreated()->json('data.watch_invite.session_id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.session_id', $next);
        DB::table('n_active_streams')->update(['status' => 'stopped']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.session_id', null);
        $this->getJson("/api/v4/chat/rooms/$room/watch-status?auth_user_id=stranger")->assertForbidden();
    }

    public function test_new_watch_rooms_also_publish_play_pause_seek_controls(): void
    {
        $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        $url = "/api/v4/chat/invite-rooms/$room/position";
        $statusUrl = "/api/v4/chat/rooms/$room/watch-status?auth_user_id=bob";
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.session_id', null);
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])->assertOk()
            ->assertJsonPath('data.content_type', 'movie')->assertJsonPath('data.is_host', false)->assertJsonPath('data.watch_session', null);
        $this->postJson($url, $this->identity(['position_ms' => 8000, 'paused' => true, 'control_sequence' => 3]))->assertOk();
        $this->travel(5)->seconds();
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertOk()->assertJsonPath('data.position_ms', 8000)->assertJsonPath('data.paused', true);
        $this->postJson($url, ['auth_user_id' => 'bob', 'paused' => false, 'position_ms' => 0])->assertForbidden();
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'watch_control')->count());
        DB::table('n_active_streams')->update(['status' => 'stopped']);
        app(\App\Support\Chat\PrivateWatchService::class)->streamChanged(\Illuminate\Http\Request::create('/', 'POST'), 1);
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        Http::assertSent(function ($request) use ($room) {
            $event = json_decode($request->data()['data'] ?? '{}', true);
            return ($event['kind'] ?? null) === 'ended' && ($event['watch']['room_id'] ?? null) === $room
                && $event['watch']['active'] === false && $event['watch']['playback'] === null;
        });
    }

    public function test_dedicated_room_ends_permanently_with_creator_stream_and_revokes_all_chat_access(): void
    {
        $private = $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        $invite = DB::table('chat_room_invites')->where('room_id', $room)->value('id');
        $token = str_repeat('w', 64);
        DB::table('chat_room_invites')->where('id', $invite)->update(['status' => 'accepted', 'link_token_hash' => hash('sha256', $token)]);
        $this->assertSame(1, (int) DB::table('chat_invite_rooms')->where('room_id', $room)->value('host_stream_id'));
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=bob')->assertOk()->assertJsonCount(2, 'data.rooms');
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])->assertOk();
        $other = $this->liveCreatorStream('movie', 'film-a');
        DB::table('n_active_streams')->where('id', $other)->update(['stream_token' => 'other-device-play']);
        DB::table('n_active_streams')->where('id', 1)->update(['status' => 'stopped']);
        $service = app(\App\Support\Chat\PrivateWatchService::class);
        $service->streamChanged(\Illuminate\Http\Request::create('/'), 1);
        $service->streamChanged(\Illuminate\Http\Request::create('/'), 1);
        $this->assertFalse((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
        $this->assertTrue((bool) DB::table('chat_invite_rooms')->where('room_id', $private)->value('active'));
        $this->assertSame(1, DB::table('chat_outbox')->where('room_id', $room)->where('kind', 'ended')->count());
        // Another player watching the same title cannot revive or authorize the old room.
        $this->liveCreatorStream('movie', 'film-a');
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=bob')->assertOk()->assertJsonCount(1, 'data.rooms')
            ->assertJsonPath('data.rooms.0.room_id', $private);
        foreach (["invite-rooms/$room/join", "invite-rooms/$room/position", "rooms/$room/auth", "rooms/$room/watch-heartbeat"] as $path) {
            $this->postJson('/api/v4/chat/'.$path, ['auth_user_id' => 'bob'])->assertForbidden();
        }
        $this->getJson("/api/v4/chat/rooms/$room/messages?auth_user_id=bob")->assertForbidden();
        $this->postJson("/api/v4/chat/rooms/$room/messages", ['auth_user_id' => 'bob', 'body' => 'hello', 'request_id' => (string) Str::uuid()])->assertForbidden();
        $this->getJson("/api/v4/chat/invitation-links/$token?auth_user_id=bob")->assertStatus(410);
        DB::table('chat_room_invites')->where('id', $invite)->update(['status' => 'pending']);
        $this->getJson('/api/v4/chat/invites?auth_user_id=bob')->assertOk()->assertJsonCount(0, 'data.invites');
        $this->postJson("/api/v4/chat/invites/$invite/respond", ['auth_user_id' => 'bob', 'accept' => true])->assertNotFound();
    }

    public function test_dedicated_room_hides_on_heartbeat_expiry_and_worker_closes_it_without_chat_traffic(): void
    {
        $private = $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        DB::table('chat_room_invites')->where('room_id', $room)->update(['status' => 'accepted']);
        DB::table('chat_outbox')->delete();
        $this->travel(181)->seconds();
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=bob')->assertOk()->assertJsonCount(1, 'data.rooms');
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", ['auth_user_id' => 'bob'])->assertForbidden();
        Http::fake(['*' => Http::response('{}', 200)]);
        $this->artisan('chat:deliver', ['--once' => true])->assertSuccessful();
        $this->assertFalse((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
        Http::assertSent(function ($request) use ($room) {
            $event = json_decode($request->data()['data'] ?? '{}', true);
            return ($event['kind'] ?? null) === 'ended' && ($event['watch']['room_id'] ?? null) === $room
                && $event['watch']['active'] === false;
        });
    }

    public function test_dedicated_room_binding_rejects_replaced_token_even_if_title_is_unchanged(): void
    {
        $this->privateWatchFixture();
        $room = $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        DB::table('n_active_streams')->update(['stream_token' => 'replacement']);
        $this->getJson('/api/v4/chat/invite-rooms?auth_user_id=alice')->assertOk()->assertJsonCount(1, 'data.rooms');
        $this->postJson("/api/v4/chat/invite-rooms/$room/join", $this->identity())->assertForbidden();
        app(\App\Support\Chat\PrivateWatchService::class)->streamChanged(\Illuminate\Http\Request::create('/'), 1);
        $this->assertFalse((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
    }

    public function test_dedicated_room_migration_binds_live_legacy_rooms_and_ends_abandoned_ones(): void
    {
        $private = $this->privateWatchFixture();
        $create = fn () => $this->withHeaders(['X-Playback-Token' => 'play-a'])->postJson('/api/v4/chat/invite-rooms', $this->identity([
            'type' => 'movie', 'content_id' => 'film-a', 'room_name' => 'Watch', 'invitee_ids' => ['bob'],
        ]))->assertCreated()->json('data.room_id');
        $live = $create();
        $stale = $create();
        DB::table('chat_invite_rooms')->where('room_id', $stale)->update(['content_id' => 'old-title']);
        $migration = require database_path('migrations/2026_10_28_000001_bind_dedicated_watch_rooms_to_host_stream.php');
        $migration->down();
        $migration->up();
        $bound = DB::table('chat_invite_rooms')->where('room_id', $live)->first();
        $this->assertSame(1, (int) $bound->host_stream_id);
        $this->assertSame(hash('sha256', 'play-a'), $bound->stream_token_hash);
        $this->assertTrue((bool) $bound->active);
        $this->assertFalse((bool) DB::table('chat_invite_rooms')->where('room_id', $stale)->value('active'));
        $this->assertTrue(DB::table('chat_outbox')->where('room_id', $stale)->where('kind', 'ended')->exists());
        $this->assertTrue((bool) DB::table('chat_invite_rooms')->where('room_id', $private)->value('active'));
        $this->assertNull(DB::table('chat_invite_rooms')->where('room_id', $private)->value('host_stream_id'));
    }

    public function test_public_invitation_page_never_exposes_private_room_data(): void
    {
        $this->get('/invite/'.str_repeat('a', 64))->assertOk()->assertSee('Open in Zo Stream')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('Friends Room')
            ->assertSee('intent://app.zostream.in/invite/', false)
            ->assertSee('package=com.buannel.studio.pvt.ltd.zostream', false)
            ->assertSee('zostream://invite/', false);
    }

    public function test_watch_invitation_link_opens_the_correct_episode_and_preserves_entitlement_checks(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        Schema::create('seasons', function (Blueprint $t) { $t->id(); $t->integer('season_number'); });
        Schema::create('episodes', function (Blueprint $t) {
            $t->string('num'); $t->string('id')->nullable(); $t->string('title'); $t->integer('season_id'); $t->integer('episode_number'); $t->boolean('isPremium')->default(false);
        });
        Schema::create('n_subscriptions', function (Blueprint $t) {
            $t->id(); $t->string('user_id'); $t->boolean('is_active'); $t->timestamp('end_at');
        });
        DB::table('seasons')->insert(['id' => 9, 'season_number' => 2]);
        DB::table('episodes')->insert(['num' => '42', 'title' => 'Episode', 'season_id' => 9, 'episode_number' => 3, 'isPremium' => true]);
        $this->liveCreatorStream('episode', '42');
        DB::table('chat_invite_rooms')->where('room_id', $invite->room_id)->update([
            'content_type' => 'episode', 'content_id' => '42', 'idle_expires_at' => null, 'watch_position_ms' => 123000,
        ]);
        $url = '/api/v4/chat/invitation-links/'.$job->token;
        $this->postJson($url.'/respond', ['auth_user_id' => 'bob', 'accept' => true])->assertForbidden();
        $this->assertSame('pending', DB::table('chat_room_invites')->where('id', $invite->id)->value('status'));
        DB::table('n_subscriptions')->insert(['user_id' => 'bob', 'is_active' => true, 'end_at' => now()->addDay()]);
        $this->postJson($url.'/respond', ['auth_user_id' => 'bob', 'accept' => true])->assertOk()
            ->assertJsonPath('data.content_type', 'episode')->assertJsonPath('data.content_id', '42')
            ->assertJsonPath('data.season_id', '9')->assertJsonPath('data.episode_number', 3)
            ->assertJsonPath('data.subscription_id', 1)->assertJsonPath('data.watch_position_ms', 123000);
        $this->getJson($url.'?auth_user_id=bob')->assertOk()->assertJsonPath('data.accepted', true);
        DB::table('episodes')->delete();
        $this->getJson($url.'?auth_user_id=bob')->assertStatus(410)->assertJsonPath('error.code', 'CONTENT_UNAVAILABLE');
    }

    public function test_whatsapp_failure_can_retry_even_with_an_old_disabled_preference(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        DB::table('chat_invitation_preferences')->insert(['user_id' => 'bob', 'whatsapp_enabled' => false]);
        $service = \Mockery::mock(\App\Services\WhatsAppCloudService::class);
        $service->shouldReceive('sendTemplate')->once()->andThrow(new \RuntimeException('Provider unavailable'));
        try { $job->handle($service); $this->fail('Provider failure must be retryable'); }
        catch (\RuntimeException $e) { $this->assertSame('Provider unavailable', $e->getMessage()); }
        $this->assertNull(DB::table('chat_room_invites')->where('id', $invite->id)->value('whatsapp_sent_at'));
        $service = \Mockery::mock(\App\Services\WhatsAppCloudService::class);
        $service->shouldReceive('sendTemplate')->once()->andReturn(new \App\Models\WhatsAppMessage());
        $job->handle($service);
        $this->assertNotNull(DB::table('chat_room_invites')->where('id', $invite->id)->value('whatsapp_sent_at'));
        $this->assertSame('pending', DB::table('chat_room_invites')->where('id', $invite->id)->value('status'));
    }

    public function test_encoded_template_placeholder_redirects_to_canonical_invitation_without_accepting_it(): void
    {
        [$job, $invite] = $this->linkedInvitation();
        foreach (['{{1}}', '%7B%7B1%7D%7D', '%257B%257B1%257D%257D'] as $prefix) {
            $this->get('/invite/'.$prefix.$job->token)
                ->assertRedirect('/invite/'.$job->token)->assertHeader('Referrer-Policy', 'no-referrer');
        }
        $this->get('/invite/'.$job->token)->assertOk();
        $this->assertSame('pending', DB::table('chat_room_invites')->where('id', $invite->id)->value('status'));
        $this->get('/invite/%7B%7B1%7D%7Dbad')->assertNotFound();
    }

}
