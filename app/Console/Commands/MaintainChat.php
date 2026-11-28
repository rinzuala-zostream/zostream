<?php

namespace App\Console\Commands;

use App\Jobs\DeliverChatPush;
use App\Services\Chat\ChatService;
use App\Services\Chat\FirebaseChatStore;
use Illuminate\Console\Command;

class MaintainChat extends Command
{
    protected $signature = 'chat:maintain';

    protected $description = 'Deliver pending chat notifications and expire ordinary private rooms';

    public function handle(ChatService $chat): int
    {
        foreach ($chat->store->query('push_outbox', 'status', 'pending', 'pending', 100, true) as $id => $item) {
            DeliverChatPush::dispatch((string) $id);
        }
        foreach ($chat->store->query('private_expiries', '$value', null, now()->valueOf(), 100, true) as $room => $expires) {
            $chat->locked((string) $room, function () use ($chat, $room) {
                $s = $chat->store->get("rooms/$room/state");
                if (! $s || ! ($s['active'] ?? false)) {
                    $chat->store->update(["private_expiries/$room" => null]);

                    return;
                }
                if (! empty($s['content_type']) || ! empty($s['watch_active']) || ($s['expires_at'] ?? PHP_INT_MAX) > now()->valueOf()) {
                    return;
                }
                $u = ["rooms/$room/state/active" => false, "rooms/$room/state/can_send" => false, "private_expiries/$room" => null, "room_typing/$room" => null];
                $cursor = $chat->store->nextCursor();
                foreach ($chat->store->get("rooms/$room/members") ?? [] as $uid => $m) {
                    $u['user_account_events/'.$uid.'/'.FirebaseChatStore::key($cursor)] = ['kind' => 'room_state_invalidated', 'cursor' => $cursor];
                }
                $chat->store->update($u);
            });
        }

        return self::SUCCESS;
    }
}
