<?php

namespace App\Support\Chat;

use App\Http\Controllers\FCMNotificationController;
use Illuminate\Support\Facades\DB;

final class ChatMessageNotifications
{
    public function queue(object $message, object $room): void
    {
        $members = DB::table('chat_room_invites')->where('room_id', $message->room_id)
            ->where('status', 'accepted')->pluck('invitee_user_id')->push($room->host_user_id)->unique();
        foreach ($members as $user) {
            if ((string) $user === (string) $message->user_id) { continue; }
            DB::table('chat_outbox')->insert(['room_id' => $message->room_id, 'kind' => 'message_push',
                'message_id' => $message->id, 'recipient_user_id' => $user, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function deliver(object $row): void
    {
        if (! config('chat.enabled')) { throw new \RuntimeException('Chat is disabled'); }
        $message = DB::table('chat_messages')->find($row->message_id);
        $room = DB::table('chat_invite_rooms as ir')->join('chat_rooms as cr', 'cr.id', '=', 'ir.room_id')
            ->where('ir.room_id', $row->room_id)->where('cr.type', 'invite')->first(['ir.*', 'cr.closed']);
        $user = (string) $row->recipient_user_id;
        if (! $message || $message->deleted_at || (string) $message->user_id === $user
            || \Illuminate\Support\Carbon::parse($message->created_at)->lt(now()->subMinutes(5))
            || ! app(InviteRoomLifecycle::class)->active($room)) { return; }
        if ($user !== (string) $room->host_user_id && ! DB::table('chat_room_invites')
            ->where('room_id', $row->room_id)->where('invitee_user_id', $user)->where('status', 'accepted')->exists()) { return; }
        if (app(ChatVisibility::class)->visible((int) $row->room_id, $user)) { return; }
        if (DB::table('chat_room_reads')->where('room_id', $row->room_id)->where('user_id', $user)
            ->where('last_read_message_id', '>=', $message->id)->exists()) { return; }
        if (DB::table('chat_blocks')->where(fn ($q) => $q->where('user_id', $user)->where('blocked_user_id', $message->user_id))
            ->orWhere(fn ($q) => $q->where('user_id', $message->user_id)->where('blocked_user_id', $user))->exists()) { return; }
        if (DB::table('chat_bans')->where('user_id', $user)->where('kind', 'ban')->whereIn('room_id', [0, $row->room_id])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) { return; }
        $token = DB::table('user')->where('uid', $user)->value('token');
        if (! $token) { return; }
        $body = $message->spoiler ? 'Sent a spoiler message.' : mb_substr((string) $message->body, 0, 180);
        $result = app(FCMNotificationController::class)->sendToToken($token, $room->room_name,
            $message->display_name.': '.$body, key: 'chat_message',
            data: ['room_id' => (string) $row->room_id, 'message_id' => (string) $message->id]);
        if (! ($result['success'] ?? false)) { throw new \RuntimeException('Chat message push delivery failed'); }
    }
}
