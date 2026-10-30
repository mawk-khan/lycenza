<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Infrastructure\Guardian;

/**
 * SIS.HARDEN-1 (SIS.EXP-R1 finding R-1): the optimistic-concurrency token of
 * a Guardian's editable identity -- the StudentProfileVersion contract,
 * applied to the fields GuardianService::update() writes.
 *
 * - The edit form carries it; the save recomputes it under the Guardian row
 *   lock and refuses a mismatch (GuardianProfileChangedException), so an old
 *   form never overwrites a newer change.
 * - A digest of the values, not `updated_at` (second precision; a status
 *   change elsewhere must not make a name form stale). No schema change.
 * - KEYED (HMAC, a purpose key derived from the application key, never the
 *   Student key): the token is shown in the page and reveals nothing about
 *   the record it versions.
 */
final class GuardianProfileVersion
{
    public static function of(Guardian $guardian): string
    {
        // JSON, never a separator join: no two field sets share an encoding.
        return hash_hmac('sha256', json_encode([
            $guardian->id,
            $guardian->first_name,
            $guardian->middle_name,
            $guardian->last_name,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), self::key());
    }

    private static function key(): string
    {
        return hash('sha256', 'lycenza:guardian-profile-version:v1|'.(string) config('app.key'), true);
    }

    public static function matches(Guardian $guardian, string $expected): bool
    {
        return hash_equals(self::of($guardian), $expected);
    }
}
