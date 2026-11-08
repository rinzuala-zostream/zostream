<?php

namespace App\Support\Chat;

use App\Http\Controllers\FCMNotificationController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ChatInteractionNotifications
{
    public function reply(object $message): ?string
    {
        if (! $message->reply_to_id) { return null; }
        $parent = ChatDatabase::connection()->table('chat_messages')->where('room_id', $message->room_id)->find($message->reply_to_id);
        if (! $parent || $parent->deleted_at || (string) $parent->user_id === (string) $message->user_id) { return null; }
        $this->enqueue($message, (string) $parent->user_id, [
            'kind' => 'reply', 'actor' => (string) $message->user_id, 'parent_id' => (string) $parent->id,
        ], 'reply:'.$message->id);
        return (string) $parent->user_id;
    }

    public function reaction(object $message, string $actor, string $emoji): void
    {
        if ((string) $message->user_id === $actor) { return; }
        $this->enqueue($message, (string) $message->user_id, [
            'kind' => 'reaction', 'actor' => $actor, 'emoji' => $emoji,
        ], 'reaction:'.$message->id.':'.$actor.':'.$emoji);
    }

    private function enqueue(object $message, string $recipient, array $data, string $key): void
    {
        ChatDatabase::connection()->table('chat_outbox')->insertOrIgnore([
            'room_id' => $message->room_id, 'kind' => 'interaction_push', 'message_id' => $message->id,
            'recipient_user_id' => $recipient, 'notification_data' => json_encode($data, JSON_THROW_ON_ERROR),
            'notification_key' => hash('sha256', $key), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function deliver(object $row): void
    {
        if (! config('chat.enabled')) { throw new \RuntimeException('Chat is disabled'); }
        $db = ChatDatabase::connection();
        $data = json_decode($row->notification_data, true, flags: JSON_THROW_ON_ERROR);
        $user = (string) $row->recipient_user_id;
        $actor = (string) $data['actor'];
        $message = $db->table('chat_messages')->where('room_id', $row->room_id)->find($row->message_id);
        $room = $db->table('chat_rooms')->find($row->room_id);
        if (! $message || $message->deleted_at || ! $room || $room->closed || $actor === $user
            || Carbon::parse($row->created_at)->lt(now()->subMinutes(5))
            || Carbon::parse($message->created_at)->lt(now()->subDays(config('chat.retention_days')))) { return; }
        $invite = null;
        if ($room->type === 'invite') {
            $invite = $db->table('chat_invite_rooms')->where('room_id', $room->id)->first();
            if (! app(InviteRoomLifecycle::class)->active($invite)) { return; }
            foreach ([$user, $actor] as $member) {
                if ($member !== (string) $invite->host_user_id && ! $db->table('chat_room_invites')
                    ->where('room_id', $room->id)->where('invitee_user_id', $member)->where('status', 'accepted')->exists()) { return; }
            }
        }
        if ($db->table('chat_blocks')->where(fn ($q) => $q->where('user_id', $user)->where('blocked_user_id', $actor))
            ->orWhere(fn ($q) => $q->where('user_id', $actor)->where('blocked_user_id', $user))->exists()) { return; }
        if ($db->table('chat_bans')->whereIn('user_id', [$user, $actor])->where('kind', 'ban')->whereIn('room_id', [0, $room->id])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) { return; }
        if (app(ChatVisibility::class)->visible((int) $room->id, $user)) { return; }
        if ($data['kind'] === 'reaction') {
            if ((string) $message->user_id !== $user || ! $db->table('chat_reactions')
                ->where('message_id', $message->id)->where('user_id', $actor)->where('emoji', $data['emoji'])->exists()) { return; }
            $body = 'reacted '.$data['emoji'].' to your message.';
        } else {
            $parent = $db->table('chat_messages')->where('room_id', $room->id)->find($data['parent_id']);
            if (! $parent || $parent->deleted_at || (string) $parent->user_id !== $user
                || (string) $message->user_id !== $actor || (string) $message->reply_to_id !== (string) $parent->id) { return; }
            if ($db->table('chat_room_reads')->where('room_id', $room->id)->where('user_id', $user)
                ->where('last_read_message_id', '>=', $message->id)->exists()) { return; }
            // Keep message contents and spoilers out of lock-screen previews.
            $body = 'replied to your message.';
        }
        $token = DB::table('user')->where('uid', $user)->value('token');
        $name = DB::table('user')->where('uid', $actor)->value('name');
        if (! $token || $name === null) { return; }
        $payload = ['notification_id' => (string) $row->id, 'room_id' => (string) $room->id, 'message_id' => (string) $message->id,
            'activity' => $data['kind'], 'room_type' => $room->type, 'content_id' => (string) ($room->content_id ?? '')];
        $content = null;
        if (in_array($room->type, ['movie', 'episode'], true)) {
            $content = ChatContent::find($room->type, (string) $room->content_id);
            if (! $content) { return; }
            $movie = $room->type === 'movie' ? $content : DB::table('movie')->where('num',
                DB::table('seasons')->where('id', $content->season_id)->value('movie_id'))->first();
            if (! $movie) { return; }
            $payload['watch_content_id'] = (string) $movie->id;
        }
        $context = $invite?->room_name ?? $content?->title ?? match ($room->type) {
            'global' => 'Global Chat', 'episode' => 'Episode Chat', default => 'Movie Chat',
        };
        $title = $context.' · '.($data['kind'] === 'reaction' ? 'Reaction' : 'Reply');
        $result = app(FCMNotificationController::class)->sendToToken($token, $title,
            (mb_substr(trim($name), 0, 100) ?: 'Viewer').' '.$body,
            key: $room->type === 'invite' ? 'chat_message' : 'chat_activity', data: $payload);
        if (! ($result['success'] ?? false)) { throw new \RuntimeException('Chat interaction push delivery failed'); }
    }
}
