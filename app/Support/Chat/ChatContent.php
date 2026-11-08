<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\DB;

final class ChatContent
{
    /** Match the public playback ID, with the same numeric-key fallback as playback. */
    public static function find(string $type, string $key): ?object
    {
        $table = match ($type) {
            'movie' => 'movie',
            'episode' => 'episodes',
            default => null,
        };
        if ($table === null) { return null; }

        return DB::table($table)->where('id', $key)
            ->when(ctype_digit($key), fn ($q) => $q->orWhere('num', (int) $key))->first();
    }
}
