<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * SR.4 (ADR 0071 §26.6): conversion's `link_existing` mode adds ONE
 * relationship from the new Student to an existing, ACTIVE Guardian of the
 * School -- it never revives or attaches an inactive Guardian record, which
 * stays Guardian administration (`guardians.manage`).
 */
class AdmissionGuardianNotActiveException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ADMISSION_GUARDIAN_NOT_ACTIVE',
            'That Guardian is not active. Choose an active Guardian or create a new one.',
        );
    }
}
