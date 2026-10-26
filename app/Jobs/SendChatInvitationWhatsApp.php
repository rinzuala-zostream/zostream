<?php

namespace App\Jobs;

use App\Services\WhatsAppCloudService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendChatInvitationWhatsApp implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $inviteId, public string $token) {}

    public function middleware(): array
    {
        return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('chat-invite:'.$this->inviteId))->releaseAfter(10)->expireAfter(60)];
    }

    public function backoff(): array { return [30, 120, 300]; }

    public function handle(WhatsAppCloudService $whatsapp): void
    {
        if (! config('chat.whatsapp_enabled')) { return; }
        $invite = DB::table('chat_room_invites')->where('id', $this->inviteId)->first();
        if (! $invite || $invite->status !== 'pending' || $invite->whatsapp_sent_at ||
            $invite->expires_at <= now() || ! hash_equals((string) $invite->link_token_hash, hash('sha256', $this->token))) { return; }
        $room = DB::table('chat_invite_rooms as ir')->join('chat_rooms as r', 'r.id', '=', 'ir.room_id')
            ->where('ir.room_id', $invite->room_id)->where('ir.active', true)->where('r.closed', false)
            ->where(fn ($q) => $q->whereNotNull('ir.content_type')->orWhere('ir.idle_expires_at', '>', now()))
            ->first(['ir.*']);
        if (! $room || ! DB::table('chat_invitation_preferences')->where('user_id', $invite->invitee_user_id)
            ->where('whatsapp_enabled', true)->exists()) { return; }
        $user = DB::table('user')->where('uid', $invite->invitee_user_id)->first();
        if (! $user || empty($user->auth_phone)) { return; }
        $phone = preg_replace('/\D+/', '', $user->auth_phone);
        $country = preg_replace('/\D+/', '', $user->country_code ?? '');
        // A local number needs its stored country code. Never guess the recipient.
        if ($country !== '' && ! (str_starts_with($phone, $country) && strlen($phone) > 10)) { $phone = $country.$phone; }
        if ($country === '' && strlen($phone) <= 10 && ! str_starts_with(trim($user->auth_phone), '+')) { return; }
        if (strlen($phone) < 8 || strlen($phone) > 15) { return; }
        $host = DB::table('user')->where('uid', $invite->inviter_user_id)->value('name') ?: 'A Zo Stream user';
        $detail = 'Join the conversation in a private chat room.';
        if ($room->content_type) {
            $title = DB::table($room->content_type === 'episode' ? 'episodes' : 'movie')->where('num', $room->content_id)->value('title');
            if (! $title) { return; }
            $detail = 'Watch '.$title.' together and chat while you watch.';
        }
        $clean = static fn ($value) => trim(preg_replace('/\s+/', ' ', (string) $value));
        $whatsapp->sendTemplate($phone, config('chat.whatsapp_template'),
            array_map($clean, [$user->name ?: 'there', $host, $room->room_name, $detail]),
            $this->token, config('chat.whatsapp_language'));
        DB::table('chat_room_invites')->where('id', $invite->id)->update(['whatsapp_sent_at' => now()]);
    }
}
