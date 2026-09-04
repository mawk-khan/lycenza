<?php

namespace App\Support\Auth;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Replay-security correction (Phase 0H.4D-P1 closure IMPORTANT
 * finding): the shared fail-closed freshness rule for every session-
 * scoped security timestamp in this codebase (`mfa_verified_at`,
 * `password_confirmed_at`). Both previously compared
 * `abs(now()->diffInMinutes($storedAt)) <= $window` -- correct that
 * Carbon 3's diffInMinutes() returns a signed value (so a naive `<=`
 * without abs() would treat an EXPIRED past timestamp as valid), but
 * abs() overcorrects: it also makes a FUTURE stored timestamp within
 * the window pass, which is never legitimate here (both timestamps
 * are written server-side via now() at the moment of establishment --
 * see MfaChallengeService::establishAssurance() /
 * PasswordConfirmationService::confirm() -- so a future value can only
 * mean tampered/corrupted session state or clock skew, never a
 * genuine assurance grant, and must fail closed).
 *
 * Deliberately compares raw Unix timestamps (not a Carbon diff
 * method) so the sign of "age" is unambiguous by construction --
 * exactly the class of confusion that produced the abs() workaround
 * in the first place.
 */
final class AssuranceFreshness
{
    /**
     * @param  string|null  $storedAt  an ISO-8601 string previously written by now()->toIso8601String(), or whatever a tampered/corrupted session might contain
     */
    public static function isFresh(?string $storedAt, int $windowMinutes): bool
    {
        if ($storedAt === null || $storedAt === '') {
            return false;
        }

        try {
            $parsed = Carbon::parse($storedAt);
        } catch (Throwable) {
            // Malformed session state fails closed -- never an
            // uncaught parse exception surfaced to the caller.
            return false;
        }

        $ageSeconds = now()->getTimestamp() - $parsed->getTimestamp();

        if ($ageSeconds < 0) {
            // A future timestamp: age is negative by construction --
            // tampered/corrupted state or clock skew, never valid.
            return false;
        }

        return ($ageSeconds / 60) <= $windowMinutes;
    }
}
