<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\DB;

final class ChatDisplayNames
{
    public static function name(?object $user, string $fallback = 'Viewer'): string
    {
        $username = trim((string) ($user->username ?? ''));
        $name = $username !== '' ? $username : (trim((string) ($user->name ?? '')) ?: $fallback);

        return self::prefixed($name);
    }

    public static function forUsers(iterable $ids): array
    {
        $names = [];
        foreach (self::users($ids) as $user) {
            $names[(string) $user->uid] = self::name($user);
        }

        return $names;
    }

    /** Refresh old message snapshots too, without replacing legacy names or deleted identities. */
    public static function messages(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            if (! $message['deleted']) { $ids[] = $message['user_id']; }
            if (isset($message['reply']) && ! $message['reply']['deleted']) { $ids[] = $message['reply']['user_id']; }
        }
        $adminIds = app(\App\Support\AdminAccess::class)->adminIds($ids);
        $usernames = [];
        foreach (self::users($ids) as $user) {
            $username = trim((string) ($user->username ?? ''));
            if ($username !== '') { $usernames[(string) $user->uid] = mb_substr($username, 0, 100); }
        }
        foreach ($messages as &$message) {
            if (! $message['deleted']) {
                $message['is_admin'] = in_array((string) $message['user_id'], $adminIds, true);
                $message['display_name'] = $message['is_admin']
                    ? 'Zo Stream'
                    : self::prefixed($usernames[$message['user_id']] ?? $message['display_name']);
            } else {
                $message['is_admin'] = false;
            }
            if (isset($message['reply']) && ! $message['reply']['deleted']) {
                $replyIsAdmin = in_array((string) $message['reply']['user_id'], $adminIds, true);
                $message['reply']['is_admin'] = $replyIsAdmin;
                $message['reply']['display_name'] = $replyIsAdmin
                    ? 'Zo Stream'
                    : self::prefixed($usernames[$message['reply']['user_id']] ?? $message['reply']['display_name']);
            } elseif (isset($message['reply'])) {
                $message['reply']['is_admin'] = false;
            }
        }
        unset($message);

        return $messages;
    }

    private static function prefixed(string $name): string
    {
        return '#'.mb_substr(ltrim($name, '#'), 0, 99);
    }

    private static function users(iterable $ids): array
    {
        $users = [];
        foreach (array_chunk(collect($ids)->filter(fn ($id) => $id !== null && $id !== '')->unique()->values()->all(), 500) as $batch) {
            foreach (DB::table('user')->whereIn('uid', $batch)->get(['uid', 'username', 'name']) as $user) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
