<?php

namespace App\Support\Chat;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;

/** Chat owns its connection; account, entitlement and playback reads stay on the main DB. */
final class ChatDatabase
{
    public static function connection(): Connection
    {
        return DB::connection(config('chat.connection'));
    }

    public static function schema(): Builder
    {
        return self::connection()->getSchemaBuilder();
    }
}
