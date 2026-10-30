<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoomInvitationService
{
    public function metadata(int $room): array
    {
        $target = ChatDatabase::connection()->table('chat_invite_rooms as ir')->join('chat_rooms as r', 'r.id', '=', 'ir.room_id')
            ->where('ir.room_id', $room)->first(['ir.*', 'r.closed']);
        return ['room_id' => (string) $room, 'room_name' => $target->room_name ?? 'Private room',
            'active' => $target && $target->content_type === null && app(InviteRoomLifecycle::class)->active($target)];
    }

    public function hydrate(array $messages): array
    {
        $states = [];
        foreach ($messages as &$message) {
            if (! empty($message['room_invite'])) {
                $id = (int) $message['room_invite']['room_id'];
                $message['room_invite'] = $states[$id] ??= $this->metadata($id);
            }
        }
        return $messages;
    }

    public function share(Request $request, object $source, int $target): object
    {
        abort_unless(in_array($source->type, ['global', 'movie', 'episode'], true), 422, 'Share room invitations in Global or Movie chat.');
        $room = ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $target)->lockForUpdate()->first();
        abort_unless($room && $room->host_user_id === (string) $request->input('auth_user_id') && $room->content_type === null,
            403, 'Only the creator can share an active private room.');
        app(ChatService::class)->room($request, $target, true);
        return $room;
    }

    public function claim(Request $request, int $source, int $message): array
    {
        app(ChatService::class)->room($request, $source);
        $uid = (string) $request->input('auth_user_id');
        return ChatDatabase::connection()->transaction(function () use ($request, $source, $message, $uid): array {
            $card = ChatDatabase::connection()->table('chat_messages')->where('id', $message)->where('room_id', $source)
                ->whereNull('deleted_at')->whereNotNull('invite_room_id')
                ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))->first();
            abort_unless($card && ! in_array($card->user_id, app(ChatService::class)->blocked($uid), true), 404, 'This invitation is unavailable.');
            $room = ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $card->invite_room_id)->lockForUpdate()->first();
            $state = $this->metadata((int) $card->invite_room_id);
            abort_unless($state['active'] && $room->host_user_id === $card->user_id, 410, 'This private room has ended.');
            abort_if($uid === $room->host_user_id, 422, 'You are the creator of this room.');
            $membership = ChatDatabase::connection()->table('chat_room_invites')->where('room_id', $room->room_id)->where('invitee_user_id', $uid)->first();
            $accepted = $membership?->status === 'accepted';
            if (! $accepted && (! $membership || $membership->status !== 'pending' || $membership->expires_at <= now())) {
                $values = ['inviter_user_id' => $room->host_user_id, 'status' => 'pending', 'expires_at' => now()->addHours(24),
                    'responded_at' => null, 'whatsapp_sent_at' => null, 'link_token_hash' => hash('sha256', Str::random(64)), 'updated_at' => now()];
                if ($membership) { ChatDatabase::connection()->table('chat_room_invites')->where('id', $membership->id)->update($values); }
                else { ChatDatabase::connection()->table('chat_room_invites')->insert($values + ['room_id' => $room->room_id, 'invitee_user_id' => $uid, 'created_at' => now()]); }
                app(ChatService::class)->outbox($request, (int) $room->room_id, 'badge_sync', null, null, $uid);
            }
            return ['room_id' => (string) $room->room_id, 'room_name' => $room->room_name, 'accepted' => $accepted];
        });
    }

    public function publishEnded(Request $request, int $room): void
    {
        // Notify every public context containing a card, including older pages.
        foreach (ChatDatabase::connection()->table('chat_messages')->where('invite_room_id', $room)->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))->select('room_id', DB::raw('MIN(id) as message_id'))->groupBy('room_id')->get() as $source) {
            app(ChatService::class)->outbox($request, (int) $source->room_id, 'room_invite_status', (int) $source->message_id, null);
            app(ChatService::class)->invalidate((int) $source->room_id);
        }
    }

    public function expireSharedRooms(Request $request): void
    {
        ChatDatabase::connection()->table('chat_invite_rooms')->where('active', true)->whereNull('content_type')->where('idle_expires_at', '<=', now())
            ->whereIn('room_id', ChatDatabase::connection()->table('chat_messages')->select('invite_room_id')->whereNotNull('invite_room_id'))
            ->orderBy('id')->chunkById(200, function ($rooms) use ($request): void {
                foreach ($rooms as $room) {
                    ChatDatabase::connection()->transaction(function () use ($room, $request): void {
                        $current = ChatDatabase::connection()->table('chat_invite_rooms')->where('id', $room->id)->lockForUpdate()->first();
                        if (! $current?->active || app(InviteRoomLifecycle::class)->active($current)) { return; }
                        ChatDatabase::connection()->table('chat_invite_rooms')->where('id', $room->id)->update(['active' => false, 'updated_at' => now()]);
                        app(ChatService::class)->outbox($request, (int) $room->room_id, 'ended', null, null);
                        $this->publishEnded($request, (int) $room->room_id);
                    });
                }
            });
    }

    public function statesForSource(int $source, array $rooms): array
    {
        return ChatDatabase::connection()->table('chat_messages')->where('room_id', $source)->whereIn('invite_room_id', $rooms)->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subDays(config('chat.retention_days')))->distinct()->pluck('invite_room_id')
            ->map(fn ($id) => $this->metadata((int) $id))->all();
    }
}
