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
        DB::table('chat_watch_sessions')->where('room_id', $inviteRoom->room_id)->update(['active' => false]);
        $session = DB::table('chat_watch_sessions')->insertGetId([
            'room_id' => $inviteRoom->room_id, 'message_id' => $message, 'host_user_id' => $inviteRoom->host_user_id,
            'host_stream_id' => $stream->id, 'stream_token_hash' => hash('sha256', $r->header('X-Playback-Token')),
            'content_type' => $watch['content_type'], 'content_id' => $watch['content_id'],
            'position_ms' => $watch['position_ms'] ?? 0, 'sampled_at_ms' => now()->getTimestampMs(), 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chat_messages')->where('id', $message)->update(['body' => 'Watch '.$content->title.' together', 'spoiler' => false,
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
        $watch = DB::table('chat_watch_sessions')->where('room_id', $room)->where('active', true)->first();
        if (! $watch || ! DB::table('chat_messages')->where('id', $watch->message_id)->whereNull('deleted_at')->exists()) { return null; }
        $stream = DB::table('n_active_streams')->where('id', $watch->host_stream_id)->where('status', 'active')
            ->where('content_type', $watch->content_type)->where('content_key', $watch->content_id)
            ->where('last_ping', '>=', now()->subMinutes(3))->first();
        return $stream && hash_equals($watch->stream_token_hash, hash('sha256', $stream->stream_token)) ? $watch : null;
    }

    public function resolve(Request $r, int $room, int $session): object
    {
        $this->chat->room($r, $room);
        $watch = DB::table('chat_watch_sessions')->where('room_id', $room)->where('id', $session)->first();
        abort_unless($watch, 404);
        abort_unless($watch->active && DB::table('chat_messages')->where('id', $watch->message_id)->whereNull('deleted_at')->exists(), 410, 'This watch session has ended. Ask the creator to send /watch again.');
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

    private function recordJoin(Request $r, object $watch): void
    {
        $uid = (string) $r->input('auth_user_id');
        if ($uid === $watch->host_user_id) { return; }
        DB::table('chat_watch_participants')->insertOrIgnore([
            'session_id' => $watch->id, 'user_id' => $uid, 'joined_at' => now(),
        ]);
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
        $this->recordJoin($r, $watch);
        DB::table('chat_watch_participants')->where('session_id', $watch->id)->where('user_id', $uid)
            ->update(['stream_id' => $stream->id, 'stream_token_hash' => hash('sha256', $r->header('X-Playback-Token')), 'last_seen_at' => now()]);
    }

    // Counts are distinct invitees; the creator is excluded from both totals.
    public function counts(object $watch): array
    {
        $members = DB::table('chat_watch_participants')->where('session_id', $watch->id)->get();
        $streams = DB::table('n_active_streams')->whereIn('id', $members->pluck('stream_id')->filter())
            ->where('status', 'active')->where('content_type', $watch->content_type)->where('content_key', $watch->content_id)
            ->get()->keyBy('id');
        return ['joined_count' => $members->count(), 'streaming_count' => $members->filter(function ($member) use ($streams) {
            $stream = $streams->get($member->stream_id);
            return $stream && $member->last_seen_at && \Illuminate\Support\Carbon::parse($member->last_seen_at)->gte(now()->subSeconds(35))
                && hash_equals($member->stream_token_hash, hash('sha256', $stream->stream_token));
        })->count()];
    }

    public function join(Request $r, int $room, int $session): array
    {
        $watch = $this->resolve($r, $room, $session);
        $this->recordJoin($r, $watch);
        $content = $this->content($watch->content_type, $watch->content_id);
        $season = $watch->content_type === 'episode' ? ($content->season_id ?? null) : null;
        return ['room_id' => (string) $room, 'room_name' => DB::table('chat_invite_rooms')->where('room_id', $room)->value('room_name'),
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
