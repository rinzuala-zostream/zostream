<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ChatService
{
    public function assertInviteRoomActive(?object $room): void
    {
        abort_unless(app(InviteRoomLifecycle::class)->active($room), 403, 'This invite room has ended or expired.');
    }

    public function room(Request $r, int $id, bool $writing = false): object
    {
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        $room = $this->remember('chat:room:'.$id, 30, fn () => DB::table('chat_rooms')->find($id));
        abort_unless($room, 404);
        abort_if($room->closed, 403, 'This room is closed.');
        $uid = (string) $r->input('auth_user_id');
        $ban = false;
        foreach ([0, $id] as $scope) {
            $kind = $this->remember($this->banKey($uid, $scope), 15, fn () => DB::table('chat_bans')
                ->where('user_id', $uid)->where('room_id', $scope)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->value('kind') ?: false);
            $ban = $ban || $kind === 'ban' || ($writing && $kind === 'mute');
        }
        abort_if($ban, 403, 'Chat access is restricted.');
        if (in_array($room->type, ['movie', 'episode'], true)) {
            // A room identifier alone never grants access to premium/PPV content.
            $valid = $this->playbackAllowed($r, $room->type, $room->content_id);
            abort_unless($valid, 403, 'Start online playback to join this movie chat.');
        }
        if ($room->type === 'invite') {
            $inviteRoom = DB::table('chat_invite_rooms')->where('room_id', $id)->first();
            $this->assertInviteRoomActive($inviteRoom);
            $member = (string) $r->input('auth_user_id');
            abort_unless($inviteRoom->host_user_id === $member || DB::table('chat_room_invites')
                ->where('room_id', $id)->where('invitee_user_id', $member)->where('status', 'accepted')->exists(),
                403, 'You were not invited to this room.');
            if ($inviteRoom->content_type && $inviteRoom->content_id) {
                $hostStreamActive = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
                    ->where('d.user_id', $inviteRoom->host_user_id)->where('s.status', 'active')
                    ->where('s.content_type', $inviteRoom->content_type)->where('s.content_key', $inviteRoom->content_id)
                    ->where('s.last_ping', '>=', now()->subMinutes(3))->exists();
                abort_unless($hostStreamActive, 403, 'The host is no longer watching this title.');
                $content = ChatContent::find($inviteRoom->content_type, $inviteRoom->content_id);
                abort_unless($content, 404, 'This content is unavailable.');
                if ((bool) ($content->isPayPerView ?? false)) {
                    abort(403, 'Invite rooms are unavailable for PPV content.');
                }
                if ((bool) ($content->isPremium ?? false)) {
                    abort_unless(DB::table('n_subscriptions as s')->join('n_plans as p', 'p.id', '=', 's.plan_id')
                        ->where('s.user_id', $member)->where('s.is_active', true)->where('s.end_at', '>=', now()->startOfDay())->exists(),
                        403, 'An active subscription is required to watch this title.');
                }
            }
        }

        return $room;
    }

    // Watch players publish minute heartbeats, independently of legacy ping.
    // Never revive a stopped stream or accept another account/device's token.
    public function refreshPlayback(Request $r): void
    {
        $token = (string) $r->header('X-Playback-Token');
        if ($token === '') { return; }
        DB::table('n_active_streams')->where('stream_token', $token)->where('status', 'active')
            ->whereIn('device_id', DB::table('n_devices')->select('id')
                ->where('user_id', (string) $r->input('auth_user_id'))->where('device_token', $r->input('auth_device_id')))
            ->where('last_ping', '<=', now()->subSeconds(45))->update(['last_ping' => now()]);
    }

    public function playbackAllowed(Request $r, string $type, string $contentId): bool
    {
        return $this->remember('chat:playback:'.hash('sha256', json_encode([$r->input('auth_user_id'), $r->input('auth_device_id'), $r->header('X-Playback-Token'), $type, $contentId])), 5, fn () => DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('d.user_id', (string) $r->input('auth_user_id'))->where('d.device_token', $r->input('auth_device_id'))
            ->where('s.stream_token', (string) $r->header('X-Playback-Token'))
            ->where('s.status', 'active')->where('s.content_type', $type)->where('s.content_key', $contentId)
            ->where('s.last_ping', '>=', now()->subMinutes(3))->exists());
    }

    public function presence(Request $r, int $room, bool $leave = false): int
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection('cache');
            $key = 'chat:presence:'.$room;
            $member = hash('sha256', $r->input('auth_user_id').':'.$r->input('auth_device_id'));
            if ($leave) {
                $redis->zrem($key, $member);
            } else {
                $redis->zadd($key, time(), $member);
            }
            $redis->zremrangebyscore($key, '-inf', time() - 90);
            $redis->expire($key, 150);

            return (int) $redis->zcard($key);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function blocked(string $uid): array
    {
        return $this->remember('chat:blocks:'.hash('sha256', $uid), 60, fn () => DB::table('chat_blocks')->where('user_id', $uid)->pluck('blocked_user_id')->all());
    }

    private function remember(string $key, int $ttl, callable $load): mixed
    {
        try {
            return Cache::store(config('chat.cache_store'))->remember($key, $ttl, $load);
        } catch (\Throwable $e) {
            return $load();
        }
    }

    private function banKey(string $uid, int $room): string
    {
        return 'chat:ban:'.$room.':'.hash('sha256', $uid);
    }

    public function invalidateBan(string $uid, int $room): void
    {
        $this->forget($this->banKey($uid, $room));
    }

    public function invalidateBlocks(string $uid): void
    {
        $this->forget('chat:blocks:'.hash('sha256', $uid));
    }

    private function forget(string $key): void
    {
        try {
            Cache::store(config('chat.cache_store'))->forget($key);
        } catch (\Throwable $e) { /* bounded TTL */
        }
    }

    public function recent(int $room): array
    {
        $load = fn () => DB::table('chat_messages')->where('room_id', $room)
            ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))
            ->orderByDesc('id')->limit(config('chat.recent_limit'))->get()->map(fn ($m) => $this->message($m))->all();
        try {
            return Cache::store(config('chat.cache_store'))->remember('chat:recent:'.$room, 10, $load);
        } catch (\Throwable $e) {
            return $load();
        }
    }

    public function invalidate(int $room, bool $metadata = false): void
    {
        $this->forget('chat:recent:'.$room);
        if ($metadata) {
            $this->forget('chat:room:'.$room);
        }
    }

    public function message(object $m): array
    {
        return ['id' => (string) $m->id, 'room_id' => (string) $m->room_id,
            'user_id' => $m->user_id, 'request_id' => $m->request_id, 'display_name' => $m->display_name,
            'room_invite' => ! $m->deleted_at && ($m->invite_room_id ?? null) ? app(RoomInvitationService::class)->metadata((int) $m->invite_room_id) : null,
            'watch_invite' => $m->deleted_at ? null : json_decode($m->watch_invite ?? 'null', true),
            'body' => $m->deleted_at ? null : $m->body, 'spoiler' => (bool) $m->spoiler,
            'deleted' => $m->deleted_at !== null,
            'created_at' => \Illuminate\Support\Carbon::parse($m->created_at, config('app.timezone'))->toIso8601String()];
    }

    public function eraseUser(string $uid): void
    {
        DB::transaction(fn () => $this->eraseUserData($uid));
    }

    private function eraseUserData(string $uid): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('chat_messages')) {
            return;
        }
        $anonymous = 'deleted:'.\Illuminate\Support\Str::uuid();
        DB::table('chat_messages')->where('user_id', $uid)->orderBy('id')->chunkById(500, function ($rows) use ($anonymous) {
            foreach ($rows as $message) {
                DB::table('chat_outbox')->insert(['room_id' => $message->room_id, 'kind' => 'deleted', 'message_id' => $message->id,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('chat_messages')->whereIn('id', $rows->pluck('id'))->update([
                'body' => '', 'display_name' => 'Deleted viewer', 'user_id' => $anonymous, 'deleted_at' => now()]
                + (\Illuminate\Support\Facades\Schema::hasColumn('chat_messages', 'watch_invite') ? ['watch_invite' => null] : []));
            foreach ($rows->pluck('room_id')->unique() as $room) {
                DB::afterCommit(fn () => $this->invalidate($room));
            }
        });
        $blockers = DB::table('chat_blocks')->where('blocked_user_id', $uid)->pluck('user_id');
        DB::table('chat_blocks')->where('user_id', $uid)->orWhere('blocked_user_id', $uid)->delete();
        foreach ($blockers as $blocker) {
            DB::afterCommit(fn () => $this->invalidateBlocks($blocker));
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('chat_invite_rooms')) {
            $hosted = DB::table('chat_invite_rooms')->where('host_user_id', $uid)->lockForUpdate()->pluck('room_id');
            DB::table('chat_invite_rooms')->whereIn('room_id', $hosted)->update([
                'host_user_id' => $anonymous, 'room_name' => 'Ended room', 'active' => false, 'updated_at' => now(),
                'host_stream_id' => null, 'stream_token_hash' => null,
            ]);
            DB::table('chat_rooms')->whereIn('id', $hosted)->update(['closed' => true, 'updated_at' => now()]);
            foreach ($hosted as $room) {
                app(RoomInvitationService::class)->publishEnded(Request::create('/'), (int) $room);
                DB::table('chat_outbox')->insert(['room_id' => $room, 'kind' => 'ended', 'created_at' => now(), 'updated_at' => now()]);
                DB::afterCommit(fn () => $this->invalidate($room, true));
            }
            DB::table('chat_room_invites')->where('invitee_user_id', $uid)->orWhere('inviter_user_id', $uid)->delete();
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('chat_watch_sessions')) {
            $sessions = DB::table('chat_watch_sessions')->where('host_user_id', $uid)->pluck('id');
            if (\Illuminate\Support\Facades\Schema::hasTable('chat_watch_participants')) {
                $rooms = DB::table('chat_watch_sessions')->whereIn('id', DB::table('chat_watch_participants')
                    ->select('session_id')->where('user_id', $uid))->pluck('room_id');
                DB::table('chat_watch_participants')->where('user_id', $uid)->orWhereIn('session_id', $sessions)->delete();
                foreach ($rooms as $room) {
                    DB::table('chat_outbox')->insert(['room_id' => $room, 'kind' => 'watch_status', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('chat_watch_sessions')->whereIn('id', $sessions)->update([
                'host_user_id' => $anonymous, 'active' => false, 'host_stream_id' => 0, 'stream_token_hash' => '', 'updated_at' => now(),
            ]);
        }
        DB::table('chat_moderation_actions')->where('admin_id', $uid)->update(['admin_id' => $anonymous]);
        DB::table('chat_moderation_actions')->whereIn('action', ['mute', 'ban', 'unban'])->where('target', $uid)->update(['target' => $anonymous]);
        DB::table('chat_bans')->where('user_id', $uid)->delete();
        DB::table('chat_reports')->where('user_id', $uid)->delete();
        if (\Illuminate\Support\Facades\Schema::hasTable('chat_room_reads')) {
            DB::table('chat_room_reads')->where('user_id', $uid)->delete();
        }
        DB::table('chat_outbox')->where('analytics->user_id', $uid)->update(['analytics' => null]);
        if (\Illuminate\Support\Facades\Schema::hasColumn('chat_outbox', 'recipient_user_id')) {
            DB::table('chat_outbox')->where('recipient_user_id', $uid)->delete();
        }
        $this->invalidateBlocks($uid);
    }

    public function outbox(Request $r, int $room, string $kind, ?int $message, ?string $event, ?string $recipient = null): void
    {
        $analytics = null;
        if ($event && $r->input('app_session_id') && app(\App\Support\Analytics\AnalyticsCollectionSetting::class)->enabled()) {
            $analytics = json_encode(['event_id' => (string) \Illuminate\Support\Str::uuid(), 'name' => $event,
                'user_id' => (string) $r->input('auth_user_id'), 'device_id' => (string) $r->input('auth_device_id'),
                'app_session_id' => $r->input('app_session_id'), 'playback_session_id' => $r->input('playback_session_id'), 'room_id' => (string) $room,
                'platform' => in_array($r->header('X-Client-Platform'), ['android', 'ios'], true) ? $r->header('X-Client-Platform') : 'unknown', 'app_version' => mb_substr($r->header('X-Client-Version', 'unknown'), 0, 64)]);
        }
        DB::table('chat_outbox')->insert(['room_id' => $room, 'kind' => $kind, 'message_id' => $message,
            'analytics' => $analytics, 'created_at' => now(), 'updated_at' => now()]
            + ($recipient !== null ? ['recipient_user_id' => $recipient] : []));
    }
}
