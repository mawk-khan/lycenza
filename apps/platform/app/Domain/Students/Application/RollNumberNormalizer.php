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

    /**
     * Phase 1B.7C: the ONE shared rule for resolving a rollover Item's
     * proposed Roll Number from its `roll_number_strategy`, used
     * identically by EnrollmentRolloverDryRunService (evaluating a
     * PROPOSAL) and EnrollmentRolloverItemExecutionService
     * (re-deriving the SAME value as a revalidation before
     * materializing it) -- extracted here rather than left duplicated
     * so a Roll Number is never resolved two subtly different ways.
     * Never throws -- returns null for an unset/invalid strategy or a
     * blank explicit/source value; the caller decides what an
     * unresolved Roll Number means for its own step.
     */
    public static function resolveForStrategy(?string $strategy, ?string $explicitValue, string $sourceRollNumber): ?string
    {
        try {
            return match ($strategy) {
                'preserve_source' => self::normalize($sourceRollNumber),
                'explicit' => self::normalize((string) $explicitValue),
                default => null,
            };
        } catch (InvalidEnrollmentRollNumberException) {
            return null;
        }
    }
}
