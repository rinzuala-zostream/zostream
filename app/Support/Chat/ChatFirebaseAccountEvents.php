<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Small account-scoped invalidations; SQL remains the canonical unread/room snapshot. */
final class ChatFirebaseAccountEvents
{
    public function publish(object $outbox, ?object $room = null, ?object $message = null): void
    {
        $startedAt = microtime(true);
        if (! config('chat.firebase.enabled') || ! ChatDatabase::schema()->hasTable('chat_firebase_badge_recipients')) { return; }
        $uid = trim((string) ($outbox->recipient_user_id ?? ''));
        $userIds = $uid !== '' ? [$uid] : $this->audience($room, $outbox, $message);
        if ($userIds === []) { return; }

        if ($message && in_array($outbox->kind, ['feed_created', 'feed_deleted'], true)) {
            $blocked = ChatDatabase::connection()->table('chat_blocks')->where('blocked_user_id', (string) $message->user_id)
                ->pluck('user_id')->flip();
            $userIds = array_values(array_filter($userIds, fn (string $userId) => ! $blocked->has($userId)
                && (string) $message->user_id !== $userId));
        }
        if ($userIds === []) { return; }

        $id = str_pad((string) $outbox->id, 20, '0', STR_PAD_LEFT);
        $unreadEvent = in_array($outbox->kind, ['feed_created', 'feed_deleted', 'read'], true)
            || ($outbox->kind === 'badge_sync' && (int) $outbox->room_id === 0);
        $event = ['revision' => (string) $outbox->id,
            'kind' => $unreadEvent ? 'unread_invalidated' : 'room_state_invalidated',
            'server_at' => now()->getTimestampMs()];
        $db = ChatDatabase::connection();
        $batchCount = 0;
        foreach (array_chunk(array_values(array_unique($userIds)), 200) as $batch) {
            $updates = [];
            $rows = [];
            foreach ($batch as $userId) {
                $firebaseUid = ChatFirebase::uid($userId);
                $updates["user_account_events/$firebaseUid/$id"] = $event;
                $rows[] = ['firebase_uid' => $firebaseUid, 'outbox_id' => (int) $outbox->id,
                    'published_at' => now()];
            }
            app(ChatFirebase::class)->publishAccountEvents($updates);
            $batchCount++;
            $db->table('chat_firebase_badge_recipients')->insertOrIgnore($rows);
        }
        Log::info('Firebase chat account invalidation published', [
            'outbox_id' => (int) $outbox->id, 'kind' => $event['kind'],
            'recipient_count' => count(array_unique($userIds)), 'changed_path_count' => count(array_unique($userIds)),
            'payload_bytes' => strlen(json_encode($event, JSON_THROW_ON_ERROR)), 'admin_batch_count' => $batchCount,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /** @return list<string> */
    private function audience(?object $room, object $outbox, ?object $message): array
    {
        $db = ChatDatabase::connection();
        if (! $room) { return []; }
        $userIds = match ($room->type) {
            'global' => DB::table('session_tokens')->where('access_expires_at', '>', now())->distinct()->pluck('user_id')->all(),
            'invite' => $this->inviteMembers((int) $room->id, $message !== null),
            'movie', 'episode' => DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
                ->join('session_tokens as st', 'st.user_id', '=', 'd.user_id')
                ->where('s.status', 'active')->where('s.content_type', $room->type)->where('s.content_key', $room->content_id)
                ->where('s.last_ping', '>=', now()->subMinutes(3))->where('st.access_expires_at', '>', now())
                ->distinct()->pluck('d.user_id')->all(),
            default => [],
        };

        if ($userIds === []) { return []; }
        $banned = $db->table('chat_bans')->whereIn('room_id', [0, (int) $room->id])->where('kind', 'ban')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('user_id')->flip();
        return array_values(array_filter(array_map('strval', $userIds), fn (string $id) => ! $banned->has($id)
            && (! $message || (string) $message->user_id !== $id)));
    }

    private function inviteMembers(int $roomId, bool $messageAudience): array
    {
        $db = ChatDatabase::connection();
        $invite = $db->table('chat_invite_rooms')->where('room_id', $roomId)->first();
        if (! $invite) { return []; }
        if ($messageAudience && ! app(InviteRoomLifecycle::class)->active($invite)) { return []; }
        // Include pending invitees only for invalidations. The payload carries no
        // room ID or metadata; Laravel decides their current visible room set.
        $query = $db->table('chat_room_invites')->where('room_id', $roomId)->where('expires_at', '>', now());
        $query->whereIn('status', $messageAudience ? ['accepted'] : ['pending', 'accepted']);
        $members = $query->pluck('invitee_user_id')->push((string) $invite->host_user_id)->unique()->values()->all();
        if ($messageAudience && $invite->content_type && $invite->content_id) {
            $hostWatching = DB::table('n_active_streams as s')->join('n_devices as d', 'd.id', '=', 's.device_id')
                ->where('d.user_id', $invite->host_user_id)->where('s.status', 'active')
                ->where('s.content_type', $invite->content_type)->where('s.content_key', $invite->content_id)
                ->where('s.last_ping', '>=', now()->subMinutes(3))->exists();
            if (! $hostWatching) { return []; }
            $content = ChatContent::find($invite->content_type, $invite->content_id);
            if (! $content || ($content->isPayPerView ?? false)) { return []; }
            if ($content->isPremium ?? false) {
                $subscribers = DB::table('n_subscriptions as s')->join('n_plans as p', 'p.id', '=', 's.plan_id')
                    ->whereIn('s.user_id', $members)->where('s.is_active', true)->where('s.end_at', '>=', now()->startOfDay())
                    ->pluck('s.user_id')->flip();
                $members = array_values(array_filter($members, fn (string $member) => $subscribers->has($member)));
            }
        }
        return $members;
    }
}
