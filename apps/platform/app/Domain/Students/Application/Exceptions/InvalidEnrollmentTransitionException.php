<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.3: only `active` -> `completed`/`withdrawn`/`cancelled`/
 * `transferred` are valid transitions. Every terminal status
 * (`completed`/`withdrawn`/`cancelled`/`transferred`) is final -- none
 * of them may transition again through the sanctioned service,
 * including back to `active` (re-enrollment is always a NEW
 * StudentEnrollment row, never a reactivated old one). Mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\InvalidAcademicYearTransitionException's
 * identical shape.
 */
class InvalidEnrollmentTransitionException extends StudentException
{
    public function __construct(string $from, string $to)
    {
        // SIS 9.1: the operator's words, never status keys (same code).
        $now = [
            'active' => 'is still active',
            'completed' => 'is already completed',
            'withdrawn' => 'has been withdrawn',
            'transferred' => 'has already been transferred',
            'cancelled' => 'has been cancelled',
        ][$from] ?? 'has changed';
        $action = [
            'completed' => 'completed',
            'withdrawn' => 'withdrawn',
            'transferred' => 'transferred',
            'cancelled' => 'cancelled',
            'active' => 'reopened',
        ][$to] ?? 'changed this way';

        parent::__construct(
            422,
            'INVALID_ENROLLMENT_TRANSITION',
            "This placement {$now}, so it can't be {$action}. Refresh the page to see what can be done next.",
        );
    }
}
