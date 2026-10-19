<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;

final class NexframeRegistrationDate
{
    public static function sql(ConnectionInterface $connection, string $column = 'created_date'): string
    {
        if ($connection->getDriverName() !== 'mysql') {
            return $column;
        }

        // The legacy user table stores dates such as
        // "Jul 14, 2026 11:29:22 pm" in a string column. Keep accepting native
        // SQL timestamps as well so this remains safe during a future migration.
        return "COALESCE(STR_TO_DATE({$column}, '%b %e, %Y %h:%i:%s %p'), STR_TO_DATE({$column}, '%Y-%m-%d %H:%i:%s'))";
    }
}
