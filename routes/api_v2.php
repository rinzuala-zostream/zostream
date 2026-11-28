<?php

use App\Http\Controllers\Api\V4\ChatController;
use Illuminate\Support\Facades\Route;

Route::prefix('v2/chat')
    ->middleware(['api.client', 'api.v2', 'auth.token', 'throttle:chat-api'])
    ->group(function () {
        foreach ([
            ['POST', 'rooms/join', 'join'],
            ['POST', 'rooms/{roomId}/messages', 'send'],
            ['GET', 'rooms/{roomId}/messages', 'history'],
            ['GET', 'rooms/{roomId}/messages/{messageId}', 'message'],
            ['POST', 'rooms/{roomId}/heartbeat', 'heartbeat'],
            ['GET', 'rooms/{roomId}/sync', 'sync'],
            ['POST', 'firebase/token', 'token'],
            ['POST', 'firebase/lease', 'lease'],
            ['GET', 'unread', 'unread'],
            ['POST', 'rooms/{roomId}/read', 'read'],
            ['GET|POST', 'rooms/{roomId}/typing', 'typing'],
            ['POST', 'rooms/{roomId}/messages/{messageId}/reactions', 'react'],
            ['GET', 'rooms/{roomId}/pins', 'pins'],
            ['POST', 'rooms/{roomId}/messages/{messageId}/pin', 'pin'],
            ['POST', 'rooms/{roomId}/messages/{messageId}/unpin', 'unpin'],
            ['POST', 'rooms/{roomId}/messages/{messageId}/report', 'report'],
            ['GET|POST', 'blocks', 'blocks'],
            ['GET', 'rooms/{roomId}/movie-suggestions', 'movies'],
            ['GET|POST', 'invite-rooms', 'rooms'],
            ['POST', 'invite-rooms/{roomId}/join', 'private-join'],
            ['POST', 'invite-rooms/{roomId}/invitees', 'invitees'],
            ['POST', 'invite-rooms/{roomId}/end', 'end'],
            ['POST', 'invitees/lookup', 'lookup'],
            ['GET', 'invites', 'invites'],
            ['POST', 'invites/{inviteId}/respond', 'respond'],
            ['GET', 'invitation-links/{token}', 'invitation-link'],
            ['POST', 'invitation-links/{token}/respond', 'link-respond'],
            ['POST', 'rooms/{roomId}/messages/{messageId}/invitation', 'claim'],
            ['POST', 'rooms/{roomId}/room-invites/status', 'invite-status'],
        ] as [$methods, $path, $action]) {
            $route = Route::match(explode('|', $methods), $path, [ChatController::class, 'handle'])
                ->defaults('chatAction', $action)
                ->where([
                    'roomId' => '[A-Za-z0-9_-]{1,80}',
                    'messageId' => '[0-9]{1,19}',
                    'inviteId' => '[0-9]{1,19}',
                    'token' => '[A-Za-z0-9]{64}',
                ]);
            if (in_array($action, ['send', 'rooms', 'invitees', 'lookup', 'react', 'report'], true)) {
                $route->middleware($action === 'send' ? 'throttle:chat-send' : 'throttle:chat-api');
            }
        }
    });
