<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * Translates the database's partial unique index
 * (`admission_applications_one_open_per_context`, Phase 1D.1)
 * rejecting a second simultaneously OPEN (`draft`/`submitted`/
 * `accepted`) Admission Application for the same Applicant/
 * AcademicYear/Campus/GradeLevel tuple into a predictable domain
 * error -- the database index remains the authoritative concurrency
 * guarantee (this is a translation, not a replacement, of that
 * constraint); see
 * App\Domain\Admissions\Application\AdmissionApplicationService::create().
 */
class OpenAdmissionApplicationExistsException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'OPEN_ADMISSION_APPLICATION_EXISTS',
            'An open Admission Application already exists for this Applicant in this academic context.',
        );
    }
}
