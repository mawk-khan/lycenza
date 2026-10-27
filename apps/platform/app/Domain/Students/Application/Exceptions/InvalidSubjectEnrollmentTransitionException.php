<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Only `active` -> `withdrawn`/`cancelled`/`transferred` are valid
 * transitions. Every terminal status is final -- mirrors
 * InvalidEnrollmentTransitionException's identical shape.
 */
class InvalidSubjectEnrollmentTransitionException extends StudentException
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
            'INVALID_SUBJECT_ENROLLMENT_TRANSITION',
            "This subject enrollment {$now}, so it can't be {$action}. Refresh the page to see what can be done next.",
        );
    }
}
