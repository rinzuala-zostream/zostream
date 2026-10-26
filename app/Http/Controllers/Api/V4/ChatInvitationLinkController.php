<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Support\Api\V4Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatInvitationLinkController extends Controller
{
    public function preference(Request $r)
    {
        if ($r->isMethod('post')) {
            $v = $r->validate(['whatsapp_enabled' => 'required|boolean']);
            DB::table('chat_invitation_preferences')->updateOrInsert(['user_id' => (string) $r->input('auth_user_id')],
                ['whatsapp_enabled' => $v['whatsapp_enabled'], 'created_at' => now(), 'updated_at' => now()]);
        }
        return V4Response::success(['whatsapp_enabled' => (bool) DB::table('chat_invitation_preferences')
            ->where('user_id', (string) $r->input('auth_user_id'))->value('whatsapp_enabled')]);
    }

    public function show(Request $r, string $token)
    {
        return $this->resolve($r, $token, false);
    }

    public function respond(Request $r, string $token)
    {
        $r->validate(['accept' => 'required|boolean']);
        return DB::transaction(fn () => $this->resolve($r, $token, true));
    }

    private function subscriptionId(string $user): ?int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('n_subscriptions')) { return null; }
        $id = DB::table('n_subscriptions')->where('user_id', $user)->where('is_active', true)
            ->where('end_at', '>=', now()->startOfDay())->value('id');
        return $id === null ? null : (int) $id;
    }

    private function resolve(Request $r, string $token, bool $respond)
    {
        if (! config('chat.enabled')) { return V4Response::error('CHAT_UNAVAILABLE', 'Chat is temporarily unavailable. Please try again later.', 503); }
        $query = DB::table('chat_room_invites')->where('link_token_hash', hash('sha256', $token));
        $invite = ($respond ? $query->lockForUpdate() : $query)->first();
        if (! $invite) { return V4Response::error('INVITE_INVALID', 'This invitation link is invalid. Ask the sender for a new invitation.', 404); }
        // Check identity before exposing the room, sender, content or invitation state.
        if ($invite->invitee_user_id !== (string) $r->input('auth_user_id')) {
            return V4Response::error('INVITE_WRONG_ACCOUNT', 'This invitation is for another Zo Stream account. Sign in with the phone number that received it.', 403);
        }
        $room = DB::table('chat_invite_rooms as ir')->join('chat_rooms as r', 'r.id', '=', 'ir.room_id')
            ->where('ir.room_id', $invite->room_id)->first(['ir.*', 'r.closed']);
        if (! $room || ! $room->active || $room->closed || ($room->content_type === null && (! $room->idle_expires_at || $room->idle_expires_at <= now()))) {
            return V4Response::error('ROOM_ENDED', 'This room has ended. Ask the sender to create a new room.', 410);
        }
        if ($invite->status === 'declined') { return V4Response::error('INVITE_DECLINED', 'You declined this invitation. Ask the sender for a new invitation if you want to join.', 410); }
        if ($invite->status !== 'accepted' && $invite->expires_at <= now()) { return V4Response::error('INVITE_EXPIRED', 'This invitation has expired. Ask the sender for a new invitation.', 410); }
        $content = null;
        if ($room->content_type) {
            $content = DB::table($room->content_type === 'episode' ? 'episodes' : 'movie')->where('num', $room->content_id)->first();
            if (! $content) { return V4Response::error('CONTENT_UNAVAILABLE', 'This movie or episode is no longer available.', 410); }
        }
        if ($respond && $invite->status === 'pending') {
            // Reuse the existing entitlement checks and host playback position lookup.
            $response = app(ChatController::class)->respondInvite($r, $invite->id);
            if (! $r->boolean('accept')) { return $response; }
            return V4Response::success(array_merge($response->getData(true)['data'], [
                'subscription_id' => $this->subscriptionId((string) $r->input('auth_user_id')),
            ]));
        }
        if ($respond && ! $r->boolean('accept')) { return V4Response::error('INVITE_ALREADY_ACCEPTED', 'You already accepted this invitation. You can close this screen or open the room.', 409); }
        $seasonId = $room->content_type === 'episode' ? ($content->season_id ?? null) : null;
        return V4Response::success([
            'invite_id' => (string) $invite->id, 'status' => $invite->status,
            'subscription_id' => $room->content_type ? $this->subscriptionId((string) $r->input('auth_user_id')) : null,
            'accepted' => $invite->status === 'accepted', 'room_id' => (string) $room->room_id,
            'room_name' => $room->room_name, 'inviter_name' => DB::table('user')->where('uid', $invite->inviter_user_id)->value('name') ?: 'A Zo Stream user',
            'content_type' => $room->content_type, 'content_id' => $room->content_id,
            'content_title' => $content->title ?? null, 'season_id' => $seasonId === null ? null : (string) $seasonId,
            'season_number' => $seasonId ? (int) DB::table('seasons')->where('id', $seasonId)->value('season_number') : null,
            'episode_number' => isset($content->episode_number) ? (int) $content->episode_number : null,
            'watch_position_ms' => (int) $room->watch_position_ms,
        ]);
    }
}
