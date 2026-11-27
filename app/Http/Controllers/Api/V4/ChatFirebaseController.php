<?php

namespace App\Http\Controllers\Api\V4;

use App\Http\Controllers\Controller;
use App\Models\SessionTokenModel;
use App\Support\Api\V4Response;
use App\Support\Chat\ChatChannels;
use App\Support\Chat\ChatFirebase;
use App\Support\Chat\ChatFirebaseListeners;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ChatFirebaseController extends Controller
{
    public function renew(Request $request, ChatFirebase $firebase)
    {
        abort_unless(in_array(data_get($request->attributes->get('client_context'), 'platform'), ['android', 'ios'], true),
            403, 'Firebase chat is not available on this client platform.');
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        $validated = $request->validate(['active_room_id' => 'sometimes|integer|min:1']);
        $session = $this->session($request);

        try {
            $expiresAt = $firebase->renewSession($session);
            if (! isset($validated['active_room_id']) && ChatChannels::firebaseAccountEventsAvailable()) {
                try {
                    app(ChatFirebaseListeners::class)->renew((string) $session->user_id, $expiresAt);
                } catch (\Throwable $error) {
                    // Account feed indexing is best effort; room delivery uses its own authorization.
                    report($error);
                }
            }
            $roomRefreshed = false;
            $refreshAfter = 180;
            if (isset($validated['active_room_id'])) {
                $room = \App\Support\Chat\ChatDatabase::connection()->table('chat_rooms')->find((int) $validated['active_room_id']);
                if ($room) {
                    $refreshAfter = in_array($room->type, ['movie', 'episode', 'invite'], true) ? 60 : 180;
                    try {
                        $roomExpiresAt = app(\App\Support\Chat\ChatAuthorizationProjector::class)->projectRoom($request, $room);
                        $roomRefreshed = true;
                        if ($roomExpiresAt !== null) { $refreshAfter = max(1, $roomExpiresAt - now()->getTimestamp() - 15); }
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                        if ($error->getStatusCode() !== 403) { throw $error; }
                    }
                }
            }

            return V4Response::success(['session_expires_at' => now()->setTimestamp($expiresAt)->toIso8601String(),
                'refresh_after_seconds' => min($refreshAfter, max(1, $expiresAt - now()->getTimestamp() - 30)), 'room_projection_refreshed' => $roomRefreshed]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);
            return V4Response::error('CHAT_FIREBASE_UNAVAILABLE', 'Firebase chat authorization is temporarily unavailable.', 503);
        }
    }

    public function token(Request $request, ChatFirebase $firebase)
    {
        abort_unless(in_array(data_get($request->attributes->get('client_context'), 'platform'), ['android', 'ios', 'windows', 'macos'], true),
            403, 'Firebase chat is not available on this client platform.');
        abort_unless(config('chat.enabled'), 503, 'Chat is currently unavailable.');
        $validated = $request->validate(['active_room_id' => 'sometimes|integer|min:1']);

        $session = $this->session($request);

        try {
            $data = $firebase->issueToken($session);
            $data['room_projection_refreshed'] = false;
            if (isset($validated['active_room_id'])) {
                $room = \App\Support\Chat\ChatDatabase::connection()->table('chat_rooms')->find((int) $validated['active_room_id']);
                if ($room) {
                    try {
                        $roomExpiresAt = app(\App\Support\Chat\ChatAuthorizationProjector::class)->projectRoom($request, $room);
                        $data['room_projection_refreshed'] = true;
                        if ($roomExpiresAt !== null) {
                            $data['refresh_after_seconds'] = min($data['refresh_after_seconds'], max(1, $roomExpiresAt - now()->getTimestamp() - 15));
                        }
                    } catch (HttpExceptionInterface $e) {
                        if ($e->getStatusCode() !== 403) { throw $e; }
                    }
                }
            }

            return V4Response::success($data);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return V4Response::error('CHAT_FIREBASE_UNAVAILABLE', 'Firebase chat authentication is temporarily unavailable.', 503);
        }
    }

    private function session(Request $request): SessionTokenModel
    {
        $session = SessionTokenModel::query()->whereKey($request->attributes->get('auth_session_token_id'))
            ->where('user_id', (string) $request->input('auth_user_id'))->first();
        abort_unless($session && $session->access_expires_at?->isFuture(), 401, 'The Zo Stream session has expired.');
        return $session;
    }
}
