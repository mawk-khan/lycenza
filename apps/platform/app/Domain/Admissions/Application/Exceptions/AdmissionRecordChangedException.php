<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * SIS 3.1: a correction was prepared against a version of the Applicant or
 * Admission Application that is no longer current (another operator corrected
 * it, or its status moved on -- e.g. it was converted). The correction is
 * refused rather than silently overwriting the newer state.
 */
class AdmissionRecordChangedException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'ADMISSION_RECORD_CHANGED',
            'This record changed after you opened it. Reload the page and make your correction again.',
        );
    }
}
