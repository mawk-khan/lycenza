<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * SIS 3.2: an accepted application whose Applicant's date of birth is later
 * than the School's date (legacy data, written before the rule) is never
 * converted. Raised inside the conversion transaction before anything is
 * written, so no Student, placement, Guardian or fee intent exists.
 */
class ApplicantDateOfBirthInFutureException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'APPLICANT_DATE_OF_BIRTH_IN_FUTURE',
            'This applicant\'s date of birth is in the future, so the application cannot be converted. Correct the applicant\'s details first.',
        );
    }
}
