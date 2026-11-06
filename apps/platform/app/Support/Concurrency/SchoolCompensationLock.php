<?php

namespace App\Support\Concurrency;

use Illuminate\Support\Facades\DB;

/**
 * The School's compensation lock, a PostgreSQL transaction-scoped advisory lock.
 * Shared: a calculation, an approval, a posting, an attestation, a review.
 * Exclusive: a compensation write, a reversal, an account-link change.
 *
 * It lives here because HR writes the account link and Payroll reads it, and HR
 * never depends on Payroll. The database triggers take the same key, so the key
 * format below is a contract with them.
 *
 * Whoever writes an account link takes this BEFORE the Employee row. A calculation
 * holds it shared while its foreign-key checks need that row, so row first would
 * invert. The trigger on `employees.user_id` is the backstop for any other writer.
 */
final class SchoolCompensationLock
{
    public const PREFIX = 'payroll.compensation:';

    public static function acquire(string $schoolId, bool $shared): void
    {
        DB::select(
            $shared ? 'SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))' : 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            [self::PREFIX.$schoolId],
        );
    }
}
