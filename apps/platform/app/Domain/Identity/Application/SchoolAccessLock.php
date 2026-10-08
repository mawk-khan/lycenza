<?php

namespace App\Domain\Identity\Application;

use Illuminate\Support\Facades\DB;

/**
 * One transaction-scoped advisory lock per School that serializes every
 * change to who holds authority in it: staff off-boarding, reactivation and
 * role changes (ADR 0059), and Guardian activation, account-link revocation
 * and off-boarding (POR.1, ADR 0070 §9). Taken FIRST, before any row lock,
 * so these paths can never deadlock on membership / link / grant rows. The
 * key is School-namespaced (one School never blocks another).
 */
final class SchoolAccessLock
{
    public static function hold(string $schoolId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['staff-access:'.$schoolId]);
    }
}
