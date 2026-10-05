<?php

namespace App\Support\Retention;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * E21-RH.6 (ADR 0066 §14): the row locks a PHP retention unit takes, as the
 * retention identity. A row lock (FOR UPDATE) needs UPDATE privilege, which
 * the retention identity never holds, so it goes through the lock-only
 * definer `retention_lock_rows()` (closed table list, retention identity
 * only). The locks are the same FOR UPDATE locks, held to the end of the
 * caller's transaction; nothing is changed.
 */
final class RetentionLocks
{
    /**
     * Locks the rows of `$ids` (FOR UPDATE, in id order; SKIP LOCKED when
     * `$skipLocked`) and returns the ids it locked.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function lock(string $table, array $ids, bool $skipLocked = false): array
    {
        if ($ids === []) {
            return [];
        }
        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException('Not a uuid.');
            }
        }

        return array_map(
            fn (object $row): string => (string) $row->id,
            DB::select('SELECT id FROM retention_lock_rows(?, ?::uuid[], ?) AS id', [$table, '{'.implode(',', $ids).'}', $skipLocked ? 'true' : 'false']),
        );
    }

    /** Locks one row FOR UPDATE; false when it does not exist (or is not visible in the tenant context). */
    public static function lockOne(string $table, string $id): bool
    {
        return self::lock($table, [$id]) !== [];
    }
}
