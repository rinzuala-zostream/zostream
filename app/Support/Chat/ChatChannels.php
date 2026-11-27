<?php

namespace App\Support\Chat;

use Illuminate\Http\Request;

final class ChatChannels
{
    private const WATCH_SOCKET_HEADER = 'X-Zo-Watch-Socket';
    public const LEASE_SECONDS = 300;
    public const GRACE_SECONDS = 60;

    public static function watchOnly(): bool
    {
        return config('chat.reverb_watch_only') && self::firebaseAvailable();
    }

    public static function firebaseAvailable(): bool
    {
        return config('chat.firebase.enabled') && config('chat.firebase.client_access_enabled');
    }

    public static function supportsWatchSocket(Request $request): bool
    {
        return $request->header(self::WATCH_SOCKET_HEADER) === '1';
    }

    public static function roomChannel(int $room): string
    {
        return (self::watchOnly() ? 'private-watch.' : 'private-chat.').$room;
    }

    public static function assertWatchRoom(object $room): void
    {
        if (! self::watchOnly()) { return; }

        abort_unless($room->type === 'invite' && (
            ChatDatabase::connection()->table('chat_invite_rooms')->where('room_id', $room->id)->whereNotNull('content_type')->exists()
            || app(PrivateWatchService::class)->liveSession((int) $room->id) !== null
        ), 403, 'Reverb is only available for Watch Together.');
    }

    public static function leased(string $channel, ?int $epoch = null): string
    {
        return $channel.'.lease.'.($epoch ?? intdiv(now()->timestamp, self::LEASE_SECONDS));
    }

    public static function publishing(array $channels): array
    {
        $timestamp = now()->timestamp;
        $epoch = intdiv($timestamp, self::LEASE_SECONDS);
        $result = [];
        foreach ($channels as $channel) {
            $result[] = self::leased($channel, $epoch);
            // Allow a foreground client's next 30-second renewal across the boundary.
            if ($timestamp % self::LEASE_SECONDS < self::GRACE_SECONDS) {
                $result[] = self::leased($channel, $epoch - 1);
            }
        }
        return $result;
    }

    public static function authorization(string $socket, string $channel): array
    {
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        abort_unless($key && $secret, 503, 'Live chat is not configured.');
        $timestamp = now()->timestamp;
        $channel = self::leased($channel, intdiv($timestamp, self::LEASE_SECONDS));
        return ['channel' => $channel, 'auth' => $key.':'.hash_hmac('sha256', $socket.':'.$channel, $secret),
            'expires_in' => self::LEASE_SECONDS - $timestamp % self::LEASE_SECONDS];
    }
}
