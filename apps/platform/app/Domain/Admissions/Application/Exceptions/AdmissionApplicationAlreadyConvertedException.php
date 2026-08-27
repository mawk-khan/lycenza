<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * Phase 1D.3 idempotency guard: `AdmissionConversionService::convert()`
 * checks the LOCKED row's status inside its own transaction (never a
 * caller-supplied stale model) and refuses to re-run canonical
 * Student/Guardian/Enrollment creation once `converted_student_id` is
 * already set -- a retried conversion attempt (network retry, double
 * click, a second worker) must never create a second Student or
 * Enrollment. Distinct from
 * InvalidAdmissionApplicationTransitionException (which covers every
 * OTHER illegal source status -- `draft`/`submitted`/`rejected`/
 * `withdrawn`): this exception specifically means "this exact
 * conversion already succeeded," mirroring
 * App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException's
 * "clean domain exception for an operation that cannot repeat" shape.
 */
class AdmissionApplicationAlreadyConvertedException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ADMISSION_APPLICATION_ALREADY_CONVERTED',
            'This Admission Application has already been converted.',
        );
    }
}
