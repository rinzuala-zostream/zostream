<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PrivateWatchService
{
    public function __construct(private readonly ChatService $chat) {}

    public function start(Request $r, object $inviteRoom, int $message, array $watch): void
    {
        abort_unless($inviteRoom->content_type === null && $inviteRoom->host_user_id === (string) $r->input('auth_user_id'),
            403, 'Only the private room creator can send /watch from the player.');
        abort_unless($this->chat->playbackAllowed($r, $watch['content_type'], $watch['content_id']),
            403, 'Start online playback before sending /watch.');
        $content = $this->content($watch['content_type'], $watch['content_id']);
        $stream = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('d.user_id', $inviteRoom->host_user_id)->where('d.device_token', $r->input('auth_device_id'))
            ->where('s.stream_token', $r->header('X-Playback-Token'))->where('s.status', 'active')
            ->where('s.content_type', $watch['content_type'])->where('s.content_key', $watch['content_id'])
            ->where('s.last_ping', '>=', now()->subMinutes(3))->first(['s.id']);
        abort_unless($stream, 403, 'Start online playback before sending /watch.');
        ChatDatabase::connection()->table('chat_watch_sessions')->where('room_id', $inviteRoom->room_id)->update(['active' => false]);
        $session = ChatDatabase::connection()->table('chat_watch_sessions')->insertGetId([
            'room_id' => $inviteRoom->room_id, 'message_id' => $message, 'host_user_id' => $inviteRoom->host_user_id,
            'host_stream_id' => $stream->id, 'stream_token_hash' => hash('sha256', $r->header('X-Playback-Token')),
            'content_type' => $watch['content_type'], 'content_id' => $watch['content_id'],
            'position_ms' => $watch['position_ms'] ?? 0, 'sampled_at_ms' => now()->getTimestampMs(), 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        ChatDatabase::connection()->table('chat_messages')->where('id', $message)->update(['body' => 'Watch '.$content->title.' together', 'spoiler' => false,
            'watch_invite' => json_encode(['session_id' => (string) $session, 'content_type' => $watch['content_type'],
                'content_id' => $watch['content_id'], 'content_title' => $content->title])]);
    }

    private function content(string $type, string $id): object
    {
        $content = ChatContent::find($type, $id);
        abort_unless($content, 404, 'This content is unavailable.');
        abort_if((bool) ($content->isPayPerView ?? false), 403, 'Watch together is unavailable for PPV content.');
        return $content;
    }

    public function metadata(object $session): array
    {
        $content = ChatContent::find($session->content_type, $session->content_id);
        return ['session_id' => (string) $session->id, 'content_type' => $session->content_type,
            'content_id' => $session->content_id, 'content_title' => $content->title ?? 'Unavailable title'];
    }

    public function liveSession(int $room): ?object
    {
        $watch = ChatDatabase::connection()->table('chat_watch_sessions as w')->join('chat_invite_rooms as ir', 'ir.room_id', '=', 'w.room_id')
            ->join('chat_rooms as r', 'r.id', '=', 'w.room_id')->where('w.room_id', $room)->where('w.active', true)
            ->where('ir.active', true)->where('r.closed', false)->first(['w.*']);
        if (! $watch || ! ChatDatabase::connection()->table('chat_messages')->where('id', $watch->message_id)->whereNull('deleted_at')->exists()) { return null; }
        $stream = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('s.id', $watch->host_stream_id)->where('d.user_id', $watch->host_user_id)->where('s.status', 'active')
            ->where('s.content_type', $watch->content_type)->where('s.content_key', $watch->content_id)
            ->where('s.last_ping', '>=', now()->subMinutes(3))->first(['s.stream_token']);
        return $stream && hash_equals($watch->stream_token_hash, hash('sha256', $stream->stream_token)) ? $watch : null;
    }

    /** One liveness read per room, including when recent messages are cached. */
    public function hydrate(array $messages): array
    {
        $live = [];
        foreach ($messages as &$message) {
            if (empty($message['watch_invite'])) { continue; }
            $room = (int) $message['room_id'];
            if (! array_key_exists($room, $live)) { $live[$room] = $this->liveSession($room)?->id; }
            $message['watch_invite']['active'] = $live[$room] !== null
                && (string) $live[$room] === (string) $message['watch_invite']['session_id'];
        }
        return $messages;
    }

    /** Recover missed stops and heartbeat expiry without polling per viewer. */
    public function endInactiveSessions(Request $request): void
    {
        ChatDatabase::connection()->table('chat_watch_sessions as w')
            ->leftJoin('chat_invite_rooms as ir', 'ir.room_id', '=', 'w.room_id')->leftJoin('chat_rooms as r', 'r.id', '=', 'w.room_id')
            ->leftJoin('chat_messages as m', 'm.id', '=', 'w.message_id')->where('w.active', true)
            ->select(['w.*', 'ir.active as room_active', 'r.closed as room_closed',
                'm.id as existing_message', 'm.deleted_at as message_deleted_at'])
            ->chunkById(200, function ($sessions) use ($request): void {
                // Fetch main-DB state once per batch; PostgreSQL cannot join MySQL tables.
                $mainStreams = DB::table('n_active_streams as s')->leftJoin('n_devices as d', 'd.id', '=', 's.device_id')
                    ->whereIn('s.id', $sessions->pluck('host_stream_id'))->get(['s.*', 'd.user_id as stream_owner'])->keyBy('id');
                foreach ($sessions as $watch) {
                    $stream = $mainStreams->get($watch->host_stream_id);
                    $watch->stream_owner = $stream->stream_owner ?? null;
                    $watch->stream_status = $stream->status ?? null;
                    $watch->stream_ping = $stream->last_ping ?? null;
                    $watch->stream_type = $stream->content_type ?? null;
                    $watch->stream_content = $stream->content_key ?? null;
                    $watch->stream_token = $stream->stream_token ?? null;
                }
                $streams = [];
                foreach ($sessions as $watch) {
                    if (! $watch->room_active || $watch->room_closed || $watch->stream_owner !== $watch->host_user_id
                        || $watch->stream_status !== 'active' || $watch->stream_ping === null
                        || \Illuminate\Support\Carbon::parse($watch->stream_ping)->lt(now()->subMinutes(3))
                        || $watch->stream_type !== $watch->content_type || $watch->stream_content !== $watch->content_id
                        || ! hash_equals($watch->stream_token_hash, hash('sha256', (string) $watch->stream_token))
                        || ! $watch->existing_message || $watch->message_deleted_at !== null) {
                        $streams[(int) $watch->host_stream_id] = true;
                    }
                }
                foreach (array_keys($streams) as $stream) { $this->streamChanged($request, $stream); }
            }, 'w.id', 'id');
    }

    public function resolve(Request $r, int $room, int $session): object
    {
        $this->chat->room($r, $room);
        $watch = ChatDatabase::connection()->table('chat_watch_sessions')->where('room_id', $room)->where('id', $session)->first();
        abort_unless($watch, 404);
        abort_unless($watch->active && ChatDatabase::connection()->table('chat_messages')->where('id', $watch->message_id)->whereNull('deleted_at')->exists(), 410, 'This watch session has ended. Ask the creator to send /watch again.');
        $stream = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('s.id', $watch->host_stream_id)->where('d.user_id', $watch->host_user_id)
            ->where('s.status', 'active')->where('s.content_type', $watch->content_type)->where('s.content_key', $watch->content_id)
            ->where('s.last_ping', '>=', now()->subMinutes(3))->first(['s.stream_token']);
        abort_unless($stream && hash_equals($watch->stream_token_hash, hash('sha256', $stream->stream_token)),
            410, 'The creator is no longer watching this title.');
        $content = $this->content($watch->content_type, $watch->content_id);
        if ((bool) ($content->isPremium ?? false)) {
            abort_unless(DB::table('n_subscriptions')->where('user_id', (string) $r->input('auth_user_id'))
                ->where('is_active', true)->where('end_at', '>=', now()->startOfDay())->exists(),
                403, 'An active subscription is required to watch this title.');
        }
        return $watch;
    }

    public function snapshot(int $room): ?array
    {
        $invite = ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room)->first();
        if (! $invite) { return null; }
        $state = app(WatchPlaybackState::class);
        if ($invite->content_type !== null) {
            $live = app(InviteRoomLifecycle::class)->hostActive($invite);
            return ['room_id' => (string) $room, 'session_id' => null, 'active' => $live,
                'playback' => $live ? $state->payload($invite, 'watch_position_ms', false) : null];
        }
        $watch = $invite->active ? $this->liveSession($room) : null;
        $latest = ChatDatabase::connection()->table('chat_watch_sessions')->where('room_id', $room)->max('id');
        return ['room_id' => (string) $room, 'active' => $watch !== null, 'session_id' => $watch ? (string) $watch->id : null,
            'latest_session_id' => $latest !== null ? (string) $latest : null,
            'playback' => $watch ? $state->payload($watch, 'position_ms', false) : null]
            + ($watch ? $this->counts($watch) : ['joined_count' => 0, 'streaming_count' => 0]);
    }

    public function publishStatus(Request $r, int $room, bool $force = false): void
    {
        $cache = \Illuminate\Support\Facades\Cache::store(config('chat.cache_store'));
        try {
            // A large room shares one liveness probe; each viewer need not scan
            // the participant list on every heartbeat.
            if (! $force && ! $cache->add('chat:watch-probe:'.$room, true, 15)) { return; }
            $snapshot = $this->snapshot($room);
            if (! $snapshot) { return; }
            unset($snapshot['playback']);
            $signature = hash('sha256', json_encode($snapshot));
            $key = 'chat:watch-status:'.$room;
            if ($force || $cache->get($key) !== $signature) {
                $this->chat->outbox($r, $room, 'watch_status', null, null);
                $cache->put($key, $signature, 3600);
            }
        } catch (\Throwable $e) {
            // A cache outage must not break player stop/heartbeat requests.
            $this->chat->outbox($r, $room, 'watch_status', null, null);
        }
    }

    public function heartbeat(Request $r, int $room, ?int $session): void
    {
        $this->chat->refreshPlayback($r);
        $this->chat->room($r, $room);
        $this->publishStatus($r, $room);
        if ($session !== null) {
            $watch = $this->resolve($r, $room, $session);
            $this->touchParticipant($r, $watch);
            if ($watch->host_user_id === (string) $r->input('auth_user_id')) {
                ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room)->where('idle_expires_at', '<=', now()->addMinutes(30))
                    ->update(['idle_expires_at' => now()->addHour()]);
            }
        }
        $this->publishStatus($r, $room);
    }

    public function streamChanged(Request $r, int $stream): void
    {
        if (! config('chat.enabled')) { return; }
        app(InviteRoomLifecycle::class)->endInactiveWatchRooms($r, $stream);
        if (! ChatDatabase::schema()->hasTable('chat_watch_participants')) { return; }
        $hosted = ChatDatabase::connection()->table('chat_watch_sessions')->where('active', true)->where('host_stream_id', $stream)->get();
        $endedRooms = [];
        foreach ($hosted as $watch) {
            if ($this->liveSession((int) $watch->room_id)?->id !== $watch->id) {
                $ended = ChatDatabase::connection()->transaction(function () use ($watch, $r) {
                    ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $watch->room_id)->lockForUpdate()->first();
                    $session = ChatDatabase::connection()->table('chat_watch_sessions')->where('id', $watch->id)->lockForUpdate()->first();
                    if (! $session || ! $session->active || $this->liveSession((int) $watch->room_id)?->id === $session->id) { return false; }
                    ChatDatabase::connection()->table('chat_watch_sessions')->where('id', $watch->id)->update(['active' => false, 'updated_at' => now()]);
                    foreach (ChatDatabase::connection()->table('chat_watch_participants')->where('session_id', $watch->id)->get() as $member) {
                        $guest = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
                            ->where('s.id', $member->stream_id)->where('d.user_id', $member->user_id)->where('s.status', 'active')
                            ->where('s.content_type', $watch->content_type)->where('s.content_key', $watch->content_id)->first(['s.id', 's.stream_token']);
                        if (! $guest || ! hash_equals($member->stream_token_hash ?? '', hash('sha256', $guest->stream_token))) { continue; }
                        // A replaced player/token or a different active watch session must survive.
                        $otherWatch = ChatDatabase::connection()->table('chat_watch_participants as p')->join('chat_watch_sessions as w', 'w.id', '=', 'p.session_id')
                            ->where('p.stream_id', $guest->id)->where('p.user_id', $member->user_id)
                            ->where('p.stream_token_hash', $member->stream_token_hash)->where('w.active', true)->exists();
                        if (! $otherWatch) {
                            DB::table('n_active_streams')->where('id', $guest->id)->where('stream_token', $guest->stream_token)
                                ->where('status', 'active')->update(['status' => 'stopped', 'last_ping' => now()]);
                        }
                    }
                    // Commit terminal state and its event together so a failed
                    // publish/stop callback cannot leave viewers stale forever.
                    $this->chat->outbox($r, (int) $watch->room_id, 'watch_status', null, null);
                    return true;
                });
                if ($ended) { $endedRooms[(int) $watch->room_id] = true; }
            }
        }
        $rooms = ChatDatabase::connection()->table('chat_watch_sessions')->where('active', true)
            ->where(fn ($q) => $q->where('host_stream_id', $stream)->orWhereIn('id',
                ChatDatabase::connection()->table('chat_watch_participants')->select('session_id')->where('stream_id', $stream)))
            ->pluck('room_id')->merge($hosted->pluck('room_id'))->unique();
        $owner = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('s.id', $stream)->first(['d.user_id', 's.content_type', 's.content_key']);
        if ($owner) {
            $rooms = $rooms->merge(ChatDatabase::connection()->table('chat_invite_rooms')->where('active', true)->where('host_user_id', $owner->user_id)
                ->whereNotNull('content_type')->pluck('room_id'))->unique();
        }
        foreach ($rooms as $room) {
            if (! isset($endedRooms[(int) $room])) { $this->publishStatus($r, (int) $room, true); }
        }
    }

    private function recordJoin(Request $r, object $watch): void
    {
        $uid = (string) $r->input('auth_user_id');
        if ($uid === $watch->host_user_id) { return; }
        ChatDatabase::connection()->transaction(function () use ($watch, $uid): void {
            $current = ChatDatabase::connection()->table('chat_watch_sessions')->where('id', $watch->id)->lockForUpdate()->first();
            abort_unless($current?->active, 410, 'This watch session has ended.');
            $members = ChatDatabase::connection()->table('chat_watch_participants')->where('session_id', $watch->id)->lockForUpdate()->pluck('user_id');
            if ($members->contains($uid)) { return; }
            $limit = (int) config('chat.watch_member_limit');
            abort_if($members->count() + 1 >= $limit,
                409, "This watch session is full (maximum {$limit} members including the creator).");
            ChatDatabase::connection()->table('chat_watch_participants')->insert([
                'session_id' => $watch->id, 'user_id' => $uid, 'joined_at' => now(),
            ]);
        });
    }

    public function touchParticipant(Request $r, object $watch): void
    {
        $uid = (string) $r->input('auth_user_id');
        if ($uid === $watch->host_user_id) { return; }
        $stream = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
            ->where('d.user_id', $uid)->where('d.device_token', $r->input('auth_device_id'))
            ->where('s.stream_token', (string) $r->header('X-Playback-Token'))->where('s.status', 'active')
            ->where('s.content_type', $watch->content_type)->where('s.content_key', $watch->content_id)->first(['s.id']);
        if (! $stream) { return; }
        $previous = ChatDatabase::connection()->table('chat_watch_participants')->where('session_id', $watch->id)->where('user_id', $uid)->first();
        $changed = ! $previous || (int) $previous->stream_id !== (int) $stream->id || ! $previous->last_seen_at
            || \Illuminate\Support\Carbon::parse($previous->last_seen_at)->lt(now()->subSeconds(150))
            || $previous->stream_token_hash !== hash('sha256', $r->header('X-Playback-Token'));
        $this->recordJoin($r, $watch);
        ChatDatabase::connection()->table('chat_watch_participants')->where('session_id', $watch->id)->where('user_id', $uid)
            ->update(['stream_id' => $stream->id, 'stream_token_hash' => hash('sha256', $r->header('X-Playback-Token')), 'last_seen_at' => now()]);
        if ($changed) { $this->publishStatus($r, (int) $watch->room_id, true); }
    }

    // Counts are distinct invitees; the creator is excluded from both totals.
    public function counts(object $watch): array
    {
        $members = ChatDatabase::connection()->table('chat_watch_participants')->where('session_id', $watch->id)->get();
        $streams = DB::table('n_active_streams')->whereIn('id', $members->pluck('stream_id')->filter())
            ->where('status', 'active')->where('content_type', $watch->content_type)->where('content_key', $watch->content_id)
            ->get()->keyBy('id');
        return ['joined_count' => $members->count(), 'streaming_count' => $members->filter(function ($member) use ($streams) {
            $stream = $streams->get($member->stream_id);
            return $stream && $member->last_seen_at && \Illuminate\Support\Carbon::parse($member->last_seen_at)->gte(now()->subSeconds(150))
                && hash_equals($member->stream_token_hash, hash('sha256', $stream->stream_token));
        })->count()];
    }

    public function join(Request $r, int $room, int $session): array
    {
        $watch = $this->resolve($r, $room, $session);
        $this->recordJoin($r, $watch);
        $this->publishStatus($r, $room, true);
        $content = $this->content($watch->content_type, $watch->content_id);
        $season = $watch->content_type === 'episode' ? ($content->season_id ?? null) : null;
        return ['room_id' => (string) $room, 'room_name' => ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room)->value('room_name'),
            'watch_session_id' => (string) $session,
            'is_host' => $watch->host_user_id === (string) $r->input('auth_user_id'),
            'subscription_id' => (int) (DB::table('n_subscriptions')->where('user_id', (string) $r->input('auth_user_id'))
                ->where('is_active', true)->where('end_at', '>=', now()->startOfDay())->value('id') ?? 0),
            'content_type' => $watch->content_type, 'content_id' => $watch->content_id,
            'content_title' => $content->title, 'watch_position_ms' => app(WatchPlaybackState::class)->payload($watch, 'position_ms', false)['position_ms'],
            'season_id' => $season, 'season_number' => $season ? DB::table('seasons')->where('id', $season)->value('season_number') : null,
            'episode_number' => $content->episode_number ?? null];
    }
}
