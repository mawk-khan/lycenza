<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * SIS 3.1: the record's current state does not allow this correction
 * (docs/modules/ADMISSIONS.md §6, "Correction by state").
 */
class AdmissionCorrectionNotPermittedException extends AdmissionsException
{
    public static function applicantAdmitted(): self
    {
        return new self('This applicant has been admitted. Correct the Student record instead.');
    }

    public static function academicContext(): self
    {
        return new self('The year, Campus and grade can be corrected only while the application is a draft or submitted.');
    }

    public static function decisionNote(): self
    {
        return new self('A decision note can be corrected only on an accepted, rejected or withdrawn application.');
    }

    private function __construct(string $message)
    {
        parent::__construct(422, 'ADMISSION_CORRECTION_NOT_PERMITTED', $message);
    }
}
