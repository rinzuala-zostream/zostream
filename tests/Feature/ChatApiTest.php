<?php

namespace Tests\Feature;

use App\Http\Controllers\FCMNotificationController;
use App\Jobs\DeliverChatPush;
use App\Models\SessionTokenModel;
use App\Services\Chat\ChatService;
use App\Services\Chat\FirebaseChatStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemoryChatStore extends FirebaseChatStore
{
    public array $data = [];

    private int $cursor = 0;

    public function get(string $path): mixed
    {
        return data_get($this->data, str_replace('/', '.', $path));
    }

    public function update(array $values): void
    {
        foreach ($values as $path => $value) {
            $path = str_replace('/', '.', $path);
            if ($value === null) {
                \Illuminate\Support\Arr::forget($this->data, $path);
            } else {
                data_set($this->data, $path, $value);
            }
        }
    }

    public function nextCursor(): int
    {
        return $this->cursor = max(now()->valueOf() * 1000, $this->cursor + 1);
    }

    public function customToken(string $uid, array $claims): string
    {
        return json_encode(compact('uid', 'claims'));
    }

    public function query(string $path, string $order = 'cursor', int|string|null $start = null, int|string|null $end = null, ?int $limit = null, bool $first = false): array
    {
        $items = $this->get($path) ?? [];
        $value = fn ($r, $key) => match ($order) {
            '$key' => (string) $key, '$value' => $r, default => $r[$order] ?? null
        };
        $items = array_filter($items, fn ($r, $k) => ($start === null || $value($r, $k) >= ($order === '$key' ? self::key($start) : $start)) && ($end === null || $value($r, $k) <= ($order === '$key' ? self::key($end) : $end)), ARRAY_FILTER_USE_BOTH);
        uksort($items, fn ($a, $b) => $value($items[$a], $a) <=> $value($items[$b], $b));

        return $limit === null ? $items : array_slice($items, $first ? 0 : -$limit, $limit, true);
    }
}

class ChatApiTest extends TestCase
{
    private MemoryChatStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Bus::fake();
        config(['cache.default' => 'array', 'services.admin_qr.allowed_uids' => [], 'services.admin_whatsapp.allowed_numbers' => []]);
        Schema::create('user', function (Blueprint $t) {
            $t->id('num');
            $t->string('uid');
            $t->string('name');
            $t->string('auth_phone')->nullable();
            $t->string('country_code')->default('+91');
            $t->string('token')->nullable();
        });
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('admin_uid');
        });
        Schema::create('session_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('user_id');
            $t->string('device_id');
            $t->string('access_token');
            $t->string('refresh_token')->nullable();
            $t->timestamp('access_expires_at');
            $t->timestamps();
        });
        Schema::create('movie', function (Blueprint $t) {
            $t->id('num');
            $t->string('id');
            $t->string('title');
            $t->string('poster')->nullable();
            $t->boolean('isEnable');
            $t->boolean('isChildMode')->default(false);
            $t->boolean('isAgeRestricted')->default(false);
            $t->string('status')->default('Published');
        });
        foreach (['alice', 'bob', 'eve'] as $i => $uid) {
            DB::table('user')->insert(['uid' => $uid, 'name' => ucfirst($uid), 'auth_phone' => '987654321'.$i, 'token' => 'fcm-'.$uid]);
            DB::table('session_tokens')->insert(['user_id' => $uid, 'device_id' => 'device-'.$uid, 'access_token' => SessionTokenModel::digest('token-'.$uid), 'access_expires_at' => now()->addHour()]);
        }
        $this->store = new MemoryChatStore;
        $this->app->instance(FirebaseChatStore::class, $this->store);
    }

    private function asUser(string $uid): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer token-'.$uid, 'Device-Token' => 'device-'.$uid, 'X-Chat-Protocol' => 'rtdb-2']);
    }

    private function join(string $uid): void
    {
        $this->asUser($uid)->postJson('/api/v2/chat/rooms/join', ['type' => 'global'])->assertOk()->assertJsonPath('data.firebase_realtime', true);
    }

    private function send(string $uid, string $room = 'global', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->asUser($uid)->postJson("/api/v2/chat/rooms/$room/messages", array_merge(['body' => 'Hello', 'request_id' => (string) Str::uuid()], $extra));
    }

    public function test_authentication_and_server_owned_sender_permissions(): void
    {
        $this->getJson('/api/v2/chat/unread')->assertUnauthorized();
        $this->join('alice');
        $this->send('eve')->assertForbidden();
        $this->send('alice', extra: ['user_id' => 'eve', 'sender_id' => 'eve'])->assertCreated()->assertJsonPath('data.user_id', 'alice');
        $this->store->update(['rooms/global/members/alice/can_send' => false]);
        $this->send('alice')->assertForbidden();
        $this->store->update(['rooms/global/members/alice/active' => false]);
        $this->asUser('alice')->postJson('/api/v2/chat/rooms/join', ['type' => 'global'])->assertForbidden();
    }

    public function test_idempotent_send_live_projection_new_member_history_and_read_badge(): void
    {
        $this->join('alice');
        $request = (string) Str::uuid();
        $id = $this->send('alice', extra: ['request_id' => $request])->assertCreated()->json('data.id');
        $this->send('alice', extra: ['request_id' => $request])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertCount(1, $this->store->get('rooms/global/messages'));
        $this->assertEmpty($this->store->get('push_outbox'));
        $this->join('bob');
        $this->assertNull($this->store->get("user_messages/bob/global/$id"), 'Joining must not prefetch unread history.');
        $this->asUser('bob')->getJson('/api/v2/chat/rooms/global/messages')->assertOk()->assertJsonPath('data.messages.0.id', $id);
        $snapshot = $this->getJson('/api/v2/chat/unread')->assertOk()->assertJsonPath('data.total_unread', 1)->json('data.event_cursor');
        $read = $this->postJson('/api/v2/chat/rooms/global/read', ['message_id' => $id])->assertOk()->assertJsonPath('data.badge.unread_count', 0)->assertJsonPath('data.badge.read_through', $id);
        $this->assertGreaterThan((int) $snapshot, (int) $read->json('data.badge.snapshot_cursor'));
        $this->store->update(['rooms/global/state/active' => false]);
        $this->getJson('/api/v2/chat/rooms/global/messages')->assertGone();
    }

    public function test_committed_send_survives_queue_failure_and_retry_is_idempotent(): void
    {
        $room = $this->privateRoom();
        \Illuminate\Support\Facades\Bus::shouldReceive('dispatch')->once()
            ->andThrow(new \RuntimeException('Queue temporarily unavailable'));
        $request = (string) Str::uuid();
        $id = $this->send('alice', $room, ['request_id' => $request])->assertCreated()->json('data.id');
        $this->send('alice', $room, ['request_id' => $request])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertCount(1, $this->store->get("rooms/$room/messages"));
        $items = $this->store->get('push_outbox');
        $this->assertCount(1, $items);
        $this->assertSame('pending', array_values($items)[0]['status']);
    }

    public function test_push_is_queued_instead_of_executed_after_response(): void
    {
        config(['queue.default' => 'sync']);
        $room = $this->privateRoom();
        $this->send('alice', $room)->assertCreated();
        \Illuminate\Support\Facades\Bus::assertDispatched(DeliverChatPush::class,
            fn ($job) => $job->connection === 'database');
        \Illuminate\Support\Facades\Bus::assertNotDispatchedAfterResponse(DeliverChatPush::class);
    }

    public function test_global_reply_reaction_push_and_spoiler_preview(): void
    {
        $this->join('alice');
        $this->join('bob');
        $id = $this->send('alice', extra: ['spoiler' => true])->json('data.id');
        $this->send('bob', extra: ['reply_to_id' => $id])->assertCreated()->assertJsonPath('data.reply.body', null)->assertJsonPath('data.reply.display_name', 'Alice');
        $this->assertCount(1, $this->store->get('push_outbox'));
        $path = "/api/v2/chat/rooms/global/messages/$id/reactions";
        $this->asUser('bob')->postJson($path, ['emoji' => '❤️', 'active' => true])->assertOk()->assertJsonPath('data.reactions.0.count', 1);
        $this->postJson($path, ['emoji' => '❤️', 'active' => true])->assertOk();
        $this->assertCount(2, $this->store->get('push_outbox'));
        $this->postJson($path, ['emoji' => '❤️', 'active' => false])->assertOk()->assertJsonPath('data.reactions', []);
        $this->assertCount(2, $this->store->get('push_outbox'));
        $this->send('alice', extra: ['reply_to_id' => $id])->assertCreated();
        $this->assertCount(2, $this->store->get('push_outbox'));
        $this->send('bob', extra: ['reply_to_id' => '1'])->assertNotFound();
    }

    private function privateRoom(): string
    {
        $this->join('alice');
        $room = $this->asUser('alice')->postJson('/api/v2/chat/invite-rooms', ['type' => 'global', 'room_name' => 'Friends', 'invitee_ids' => ['bob']])->assertOk()->json('data.room_id');
        $invite = $this->asUser('bob')->getJson('/api/v2/chat/invites')->assertOk()->json('data.invites.0.invite_id');
        $this->postJson("/api/v2/chat/invites/$invite/respond", ['accept' => true])->assertOk();
        $this->store->update(['push_outbox' => null]);

        return $room;
    }

    public function test_private_invitation_access_reply_push_dedup_and_room_expiry(): void
    {
        $room = $this->privateRoom();
        $this->asUser('eve')->postJson("/api/v2/chat/invite-rooms/$room/join")->assertForbidden();
        $id = $this->send('alice', $room)->assertCreated()->json('data.id');
        $this->assertCount(1, $this->store->get('push_outbox'));
        $this->send('bob', $room, ['reply_to_id' => $id])->assertCreated();
        $this->assertCount(2, $this->store->get('push_outbox'));
        $this->travel(61)->minutes();
        DB::table('session_tokens')->update(['access_expires_at' => now()->addHour()]);
        $this->send('bob', $room)->assertGone();
        $this->asUser('bob')->getJson('/api/v2/chat/invite-rooms')->assertJsonPath('data.rooms', []);
    }

    public function test_chat_messages_are_visible_and_delivered_without_user_blocks(): void
    {
        $room = $this->privateRoom();
        $id = $this->send('alice', $room)->json('data.id');
        $this->assertNotNull($this->store->get("user_messages/bob/$room/$id"));
        $this->asUser('bob')->getJson("/api/v2/chat/rooms/$room/messages")->assertJsonCount(1, 'data.messages');
        $this->asUser('bob')->getJson('/api/v2/chat/blocks')->assertNotFound();
        $this->asUser('bob')->postJson('/api/v2/chat/blocks', ['user_id' => 'alice', 'blocked' => true])->assertNotFound();
        $this->store->update(["room_typing/$room/alice_session" => ['user_id' => 'alice', 'name' => 'Alice', 'expires_at' => now()->addSeconds(6)->valueOf()]]);
        $this->getJson("/api/v2/chat/rooms/$room/typing")->assertJsonPath('data.users.0.user_id', 'alice');
    }

    public function test_invitation_links_are_account_bound_and_decline_does_not_grant_access(): void
    {
        $this->join('alice');
        $room = $this->asUser('alice')->postJson('/api/v2/chat/invite-rooms', ['type' => 'global', 'room_name' => 'Friends', 'invitee_ids' => ['bob']])->json('data.room_id');
        $invite = reset($this->store->data['invites']);
        $token = $invite['invitation_token'];
        $this->asUser('eve')->getJson("/api/v2/chat/invitation-links/$token")->assertForbidden();
        $this->asUser('bob')->postJson("/api/v2/chat/invitation-links/$token/respond", ['accept' => false])->assertOk()->assertJsonPath('data.accepted', false);
        $this->postJson("/api/v2/chat/invite-rooms/$room/join")->assertForbidden();
        $this->getJson("/api/v2/chat/invitation-links/$token")->assertGone();
    }

    public function test_pin_report_and_firebase_session_lease_are_authorized(): void
    {
        $this->join('alice');
        $this->join('bob');
        $id = $this->send('alice')->json('data.id');
        $this->asUser('bob')->postJson("/api/v2/chat/rooms/global/messages/$id/pin")->assertForbidden();
        DB::table('admin_users')->insert(['admin_uid' => 'alice']);
        $this->asUser('alice')->postJson("/api/v2/chat/rooms/global/messages/$id/pin")->assertOk()->assertJsonPath('data.pinned', true);
        $this->getJson('/api/v2/chat/rooms/global/pins')->assertJsonCount(1, 'data.pins');
        $this->postJson("/api/v2/chat/rooms/global/messages/$id/report", ['reason' => 'Spam'])->assertOk();
        $token = $this->postJson('/api/v2/chat/firebase/token')->assertOk()->json('data.custom_token');
        $this->assertSame('alice', json_decode($token, true)['uid']);
        $this->postJson('/api/v2/chat/firebase/lease', ['active_room_id' => 'unknown'])->assertOk()->assertJsonPath('data.room_projection_refreshed', false);
        $this->postJson('/api/v2/chat/firebase/lease', ['active_room_id' => 'global'])->assertOk();
        $expiry = reset($this->store->data['access']['alice'])['expires_at'];
        $this->assertLessThanOrEqual(now()->addSeconds(90)->valueOf(), $expiry);
    }

    public function test_push_delivery_does_not_replay_a_claim(): void
    {
        $room = $this->privateRoom();
        $this->send('alice', $room);
        $id = (string) array_key_first($this->store->get('push_outbox'));
        $mock = $this->mock(FCMNotificationController::class);
        $mock->shouldReceive('sendToToken')->once()->withArgs(fn ($token, $title, $body, $image, $key, $data) => $token === 'fcm-bob' && $key === 'chat_message')->andReturn(['success' => true]);
        $job = new DeliverChatPush($id);
        $job->handle(app(ChatService::class), $mock);
        $job->handle(app(ChatService::class), $mock);
        $this->assertSame('sent', $this->store->get("push_outbox/$id/status"));
    }

    public function test_movie_suggestions_are_resolved_on_server_and_moments_match_the_room(): void
    {
        DB::table('movie')->insert(['num' => 1, 'id' => 'movie-one', 'title' => 'Family Movie', 'poster' => 'https://example.test/poster.jpg', 'isEnable' => true, 'isChildMode' => true]);
        DB::table('movie')->insert(['num' => 2, 'id' => 'movie-two', 'title' => 'Adult Movie', 'isEnable' => true, 'isAgeRestricted' => true]);
        $this->join('alice');
        $this->asUser('alice')->getJson('/api/v2/chat/rooms/global/movie-suggestions?q=Movie&isChildMode=1')->assertOk()->assertJsonCount(1, 'data.movies');
        $this->send('alice', extra: ['movie_suggestion' => ['id' => 'movie-one', 'title' => 'Forged']])->assertCreated()->assertJsonPath('data.movie_suggestion.title', 'Family Movie');
        $this->send('alice', extra: ['movie_suggestion' => ['id' => 'movie-two'], 'isChildMode' => true])->assertUnprocessable();
        $this->postJson('/api/v2/chat/rooms/join', ['type' => 'movie', 'content_id' => '1'])->assertOk();
        $this->send('alice', 'movie-1', ['moment' => ['seconds' => 12, 'content_type' => 'movie', 'content_id' => '1']])->assertCreated()->assertJsonPath('data.moment.seconds', 12);
        $this->send('alice', 'movie-1', ['moment' => ['seconds' => 12, 'content_type' => 'movie', 'content_id' => '2']])->assertUnprocessable();
    }

    public function test_contact_lookup_accepts_national_or_explicit_country_numbers_without_guessing(): void
    {
        $this->asUser('alice')->postJson('/api/v2/chat/invitees/lookup', ['phone' => '9876543211'])->assertOk()->assertJsonPath('data.user_id', 'bob');
        $this->postJson('/api/v2/chat/invitees/lookup', ['phone' => '+91 98765 43211'])->assertOk()->assertJsonPath('data.user_id', 'bob');
        $this->postJson('/api/v2/chat/invitees/lookup', ['phone' => '+1 9876543211'])->assertNotFound();
    }

    public function test_creator_can_end_room_and_shared_card_does_not_admit_without_accepting(): void
    {
        $room = $this->privateRoom();
        $id = $this->send('alice', extra: ['room_invite' => ['room_id' => $room]])->assertCreated()->json('data.id');
        $this->join('eve');
        $this->asUser('eve')->postJson("/api/v2/chat/rooms/global/messages/$id/invitation")->assertOk()->assertJsonPath('data.accepted', false);
        $this->postJson("/api/v2/chat/invite-rooms/$room/join")->assertForbidden();
        $this->asUser('bob')->postJson("/api/v2/chat/invite-rooms/$room/end")->assertForbidden();
        $this->asUser('alice')->postJson("/api/v2/chat/invite-rooms/$room/end")->assertOk();
        $this->asUser('bob')->getJson("/api/v2/chat/rooms/$room/messages")->assertGone();
    }

    public function test_legacy_clients_keep_http_and_scheduled_expiry_does_not_touch_watch_rooms(): void
    {
        $room = $this->privateRoom();
        $this->asUser('alice')->withHeader('X-Chat-Protocol', 'legacy')->postJson('/api/v2/chat/rooms/join', ['type' => 'global'])->assertJsonPath('data.firebase_realtime', false);
        $this->store->update(['private_expiries/watch' => now()->subHour()->valueOf(), 'rooms/watch/state' => ['active' => true, 'room_type' => 'invite', 'content_type' => 'movie', 'expires_at' => now()->subHour()->valueOf()]]);
        $this->travel(61)->minutes();
        $this->artisan('chat:maintain')->assertSuccessful();
        $this->assertFalse($this->store->get("rooms/$room/state/active"));
        $this->assertTrue($this->store->get('rooms/watch/state/active'));
    }

    public function test_unread_history_opens_at_first_unread_and_loads_only_one_page_without_marking_read(): void
    {
        $this->join('alice');
        $updates = [];
        for ($id = 100; $id < 175; $id++) {
            $updates["rooms/global/messages/$id"] = ['id' => (string) $id, 'room_id' => 'global',
                'sender_id' => 'alice', 'sender_name' => 'Alice', 'client_op_id' => 'r'.$id,
                'body' => 'Message '.$id, 'spoiler' => false, 'deleted' => false,
                'created_at_iso' => now()->toIso8601String(), 'cursor' => $id];
        }
        $updates['rooms/global/last_message_id'] = '174';
        $this->store->update($updates);
        $this->join('bob');
        $this->assertNull($this->store->get('user_messages/bob/global'));
        $first = $this->asUser('bob')->getJson('/api/v2/chat/rooms/global/messages?initial=1')->assertOk()
            ->assertJsonCount(30, 'data.messages')->assertJsonPath('data.messages.0.id', '100')
            ->assertJsonPath('data.messages.29.id', '129')->assertJsonPath('data.initial_after', '99')
            ->assertJsonPath('data.has_more', true);
        $this->assertNull($this->store->get('reads/bob/global'), 'Loading/caching must never acknowledge a message.');
        $this->getJson('/api/v2/chat/rooms/global/messages?after=129')->assertOk()->assertJsonCount(30, 'data.messages')
            ->assertJsonPath('data.messages.0.id', '130')->assertJsonPath('data.messages.29.id', '159')->assertJsonPath('data.has_more', true);
        $this->getJson('/api/v2/chat/rooms/global/messages?after=129&limit=10')->assertOk()->assertJsonCount(10, 'data.messages')
            ->assertJsonPath('data.messages.0.id', '130')->assertJsonPath('data.messages.9.id', '139')->assertJsonPath('data.has_more', true);
        $this->getJson('/api/v2/chat/rooms/global/messages?after=129&limit=31')->assertUnprocessable();
        $this->getJson('/api/v2/chat/rooms/global/messages?after=129&limit=0')->assertUnprocessable();
        $this->getJson('/api/v2/chat/rooms/global/messages?after=159')->assertOk()->assertJsonCount(15, 'data.messages')->assertJsonPath('data.has_more', false);
        $this->postJson('/api/v2/chat/rooms/global/read', ['message_id' => '109'])->assertOk()->assertJsonPath('data.badge.unread_count', 65);
        $this->getJson('/api/v2/chat/rooms/global/messages?initial=1')->assertOk()->assertJsonPath('data.messages.0.id', '110')->assertJsonPath('data.messages.29.id', '139');
        $this->postJson('/api/v2/chat/rooms/global/read', ['message_id' => '174'])->assertOk();
        $this->getJson('/api/v2/chat/rooms/global/messages?initial=1')->assertOk()->assertJsonPath('data.first_unread_id', null)
            ->assertJsonPath('data.initial_after', null)->assertJsonPath('data.messages.0.id', '145');
    }
}
