<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminTokenMiddleware;
use App\Http\Middleware\AuthTokenMiddleware;
use App\Support\Analytics\AnalyticsCollectionSetting;
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
        $this->getJson('/api/v4/chat/invite-rooms?'.http_build_query($this->identity()))
            ->assertOk()->assertJsonPath('data.rooms.0.room_id', $room);
        $this->postJson("/api/v4/chat/invite-rooms/$room/end", $this->identity())->assertStatus(422);
        $this->assertTrue((bool) DB::table('chat_invite_rooms')->where('room_id', $room)->value('active'));
    }

    private function unread(array $identity = [], array $headers = [])
    {
        return $this->withHeaders($headers)->getJson('/api/v4/chat/unread?'.http_build_query(array_merge($this->identity(), $identity)));
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
        $this->assertSame(1, DB::table('chat_outbox')->count());
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
            ->assertJsonPath('data.auth', 'test-key:'.hash_hmac('sha256', "123.456:private-chat.$room", 'test-secret'));
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
        $this->postJson($url, $this->identity(['position_ms' => 8000, 'paused' => true, 'control_sequence' => 3]))->assertOk();
        $this->travel(5)->seconds();
        $this->postJson($url, ['auth_user_id' => 'bob'])->assertOk()->assertJsonPath('data.position_ms', 8000)->assertJsonPath('data.paused', true);
        $this->postJson($url, ['auth_user_id' => 'bob', 'paused' => false, 'position_ms' => 0])->assertForbidden();
        $this->assertSame(1, DB::table('chat_outbox')->where('kind', 'watch_control')->count());
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
