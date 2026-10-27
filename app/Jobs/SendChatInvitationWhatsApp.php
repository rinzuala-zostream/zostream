<?php

namespace App\Jobs;

use App\Services\WhatsAppCloudService;
use App\Support\Chat\InvitationPhone;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if (! config('chat.whatsapp_enabled')) { $this->skipped('disabled'); return; }
        $invite = DB::table('chat_room_invites')->where('id', $this->inviteId)->first();
        if (! $invite) { $this->skipped('invitation_missing'); return; }
        if ($invite->whatsapp_sent_at) { return; }
        if ($invite->status !== 'pending') { $this->skipped('invitation_already_responded'); return; }
        if ($invite->expires_at <= now()) { $this->skipped('invitation_expired'); return; }
        if (! hash_equals((string) $invite->link_token_hash, hash('sha256', $this->token))) { $this->skipped('link_replaced'); return; }
        $room = DB::table('chat_invite_rooms as ir')->join('chat_rooms as r', 'r.id', '=', 'ir.room_id')
            ->where('ir.room_id', $invite->room_id)->where('ir.active', true)->where('r.closed', false)
            ->where(fn ($q) => $q->whereNotNull('ir.content_type')->orWhere('ir.idle_expires_at', '>', now()))
            ->first(['ir.*']);
        if (! $room) { $this->skipped('room_unavailable_or_expired'); return; }
        $user = DB::table('user')->where('uid', $invite->invitee_user_id)->first();
        $phone = $user ? InvitationPhone::international($user->auth_phone, $user->country_code ?? null) : null;
        if ($phone === null) { $this->skipped('recipient_phone_unavailable'); return; }
        $host = DB::table('user')->where('uid', $invite->inviter_user_id)->value('name') ?: 'A Zo Stream user';
        $detail = 'Join the conversation in a private chat room.';
        if ($room->content_type) {
            $title = \App\Support\Chat\ChatContent::find($room->content_type, $room->content_id)?->title;
            if (! $title) { $this->skipped('content_unavailable'); return; }
            $detail = 'Watch '.$title.' together and chat while you watch.';
        }
        $clean = static fn ($value) => trim(preg_replace('/\s+/', ' ', (string) $value));
        try {
            $whatsapp->sendTemplate($phone, config('chat.whatsapp_template'),
                array_map($clean, [$user->name ?: 'there', $host, $room->room_name, $detail]),
                $this->token, config('chat.whatsapp_language'));
        } catch (\Throwable $e) {
            Log::warning('Chat WhatsApp invitation delivery failed', ['invite_id' => $this->inviteId,
                'exception' => $e::class, 'provider_code' => $e->getCode(),
                'template' => config('chat.whatsapp_template'), 'language' => config('chat.whatsapp_language')]);
            throw $e;
        }
        DB::table('chat_room_invites')->where('id', $invite->id)->update(['whatsapp_sent_at' => now()]);
    }

    private function skipped(string $reason): void
    {
        Log::notice('Chat WhatsApp invitation skipped', ['invite_id' => $this->inviteId, 'reason' => $reason]);
    }
}
