<?php

namespace App\Jobs;

use App\Http\Controllers\FCMNotificationController;
use App\Services\Chat\ChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DeliverChatPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $deliveryId)
    {
        // Chat delivery must never run FCM inline in an API worker.
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            $this->onConnection('database');
        }
    }

    public function handle(ChatService $chat, FCMNotificationController $push): void
    {
        Cache::lock('chat:push:'.$this->deliveryId, 120)->block(5, function () use ($chat, $push) {
            $path = 'push_outbox/'.$this->deliveryId;
            $item = $chat->store->get($path);
            if (! $item || $item['status'] !== 'pending') {
                return;
            }
            $uid = $item['recipient'];
            $cancel = $item['created_at'] < now()->subHour()->timestamp;
            try {
                $chat->room($item['room_id'], $item['kind'] === 'invite' ? null : $uid);
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404, 410])) {
                    throw $e;
                }
                $cancel = true;
            }
            if ($item['kind'] === 'invite' && ($chat->store->get('invites/'.$item['data']['invite_id'].'/status') ?? '') !== 'pending') {
                $cancel = true;
            }
            $token = DB::table('user')->where('uid', $uid)->value('token');
            if ($cancel || ! $token) {
                $chat->store->update([$path.'/status' => 'cancelled']);

                return;
            }
            // Claim before external I/O: ambiguous failures are never automatically replayed.
            $chat->store->update([$path.'/status' => 'sending']);
            $result = $push->sendToToken($token, 'Zo Stream chat', $item['body'], '', $item['data']['key'], $item['data']);
            $chat->store->update([$path.'/status' => ($result['success'] ?? false) ? 'sent' : 'failed']);
        });
    }
}
