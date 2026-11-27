<?php

namespace App\Support\Chat;

use App\Models\SessionTokenModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Mirrors only a decision that ChatService has already authorized in SQL.
 * Leases are session-scoped so playback access on one device cannot authorize
 * another device belonging to the same Zo Stream account.
 */
final class ChatAuthorizationProjector
{
    public function __construct(private readonly ChatFirebase $firebase, private readonly ChatService $chat) {}

    public function projectRoom(Request $request, object $room): ?int
    {
        if (! config('chat.firebase.enabled')) { return null; }

        $userId = (string) $request->input('auth_user_id');
        $sessionId = (string) $request->attributes->get('auth_session_token_id');
        $session = SessionTokenModel::query()->whereKey($sessionId)->where('user_id', $userId)->first();
        abort_unless($session && $session->access_expires_at?->isFuture(), 401, 'The Zo Stream session has expired.');

        // Recheck all read conditions immediately before creating a lease.
        $this->chat->assertRoomAccess($request, $room, false);
        $muted = ChatDatabase::connection()->table('chat_bans')->where('user_id', $userId)
            ->whereIn('room_id', [0, (int) $room->id])->where('kind', 'mute')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
        $canWrite = ! $muted;

        $expiresAt = self::leaseExpiry(
            now(), $session->access_expires_at, (string) $room->type,
            (int) config('chat.firebase.authorization_lease_seconds', 120),
        );
        abort_unless($expiresAt > now()->getTimestamp(), 401, 'The Zo Stream session has expired.');

        $playbackToken = (string) $request->header('X-Playback-Token', '');
        $this->firebase->projectRoomAuthorization($userId, $sessionId, $room, $canWrite, $expiresAt,
            $playbackToken === '' ? null : hash('sha256', $playbackToken));

        return $expiresAt;
    }

    public static function leaseExpiry(Carbon $now, Carbon $sessionExpiry, string $roomType, int $configuredSeconds): int
    {
        $leaseSeconds = min(300, max(30, $configuredSeconds));
        if (in_array($roomType, ['movie', 'episode', 'invite'], true)) {
            // Playback and hosted-room eligibility use short SQL heartbeats.
            $leaseSeconds = min($leaseSeconds, 90);
        }

        return min($now->copy()->addSeconds($leaseSeconds)->getTimestamp(), $sessionExpiry->getTimestamp());
    }

    public function revokeRoom(Request $request, int $roomId): void
    {
        if (! config('chat.firebase.enabled')) { return; }
        $userId = (string) $request->input('auth_user_id');
        $sessionId = (string) $request->attributes->get('auth_session_token_id');
        if ($userId === '' || $sessionId === '') { return; }
        $this->firebase->revokeRoomAuthorization($roomId, $userId, $sessionId);
    }
}
