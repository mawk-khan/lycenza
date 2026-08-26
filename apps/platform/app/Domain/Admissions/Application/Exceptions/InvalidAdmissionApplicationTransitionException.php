<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * Phase 1D.2: the ONLY status values reachable through
 * AdmissionApplicationService are `draft -> submitted`,
 * `submitted -> accepted/rejected/withdrawn`, and
 * `accepted -> withdrawn` (docs/modules/ADMISSIONS.md §6). Every
 * terminal status (`rejected`/`withdrawn`) and `converted` (entered
 * only by a future 1D.3 conversion command, never by this service) is
 * final -- none of them may transition again through this service.
 * Mirrors
 * App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException's
 * identical shape.
 */
class InvalidAdmissionApplicationTransitionException extends AdmissionsException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            422,
            'INVALID_ADMISSION_APPLICATION_TRANSITION',
            "Cannot transition an Admission Application from '{$from}' to '{$to}'.",
        );
    }
}
