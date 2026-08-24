<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;

/**
 * Phase 1B.7B: the one shared, pure Roll Number normalization rule --
 * trim, reject blank -- extracted from StudentEnrollmentService's own
 * private method (Phase 1B.2) so EnrollmentRolloverDryRunService can
 * apply the EXACT same rule when evaluating a PROPOSED Roll Number
 * without duplicating subtly different logic. Deliberately has no
 * side effects, no DB access, and no authorization -- pure string
 * validation only; it is not itself a mutation primitive.
 */
class RollNumberNormalizer
{
    public static function normalize(string $rollNumber): string
    {
        $rollNumber = trim($rollNumber);
        if ($rollNumber === '') {
            throw new InvalidEnrollmentRollNumberException;
        }

        return $rollNumber;
    }
}
