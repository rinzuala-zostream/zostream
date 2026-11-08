<?php

namespace App\Support\Chat;

final class ChatChannels
{
    public const LEASE_SECONDS = 300;
    public const GRACE_SECONDS = 60;

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
