<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MovieCatalogRevision
{
    public static function current(): int
    {
        $revision = DB::table('movie_catalog_revisions')->where('id', 1)->value('revision');

        if ($revision === null) {
            throw new RuntimeException('Movie catalog revision row is missing. Run the catalog revision migration.');
        }

        return (int) $revision;
    }

    /**
     * Advance the shared public and recommendation catalog generation.
     *
     * Call both before and after non-transactional writes so a concurrent
     * reader cannot publish a stale catalog snapshot under the final revision.
     */
    public static function bump(): void
    {
        $updated = DB::table('movie_catalog_revisions')
            ->where('id', 1)
            ->increment('revision');

        if ($updated !== 1) {
            throw new RuntimeException('Movie catalog revision row is missing. Run the catalog revision migration.');
        }
    }

    /** Keep legacy test/import schemas usable before the revision migration. */
    public static function bumpIfAvailable(): void
    {
        if (Schema::hasTable('movie_catalog_revisions')) {
            self::bump();
        }
    }
}
