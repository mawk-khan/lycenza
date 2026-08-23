<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * A genuine race between two concurrent setPrimary() calls for the same
 * Student -- translates the database's partial unique index
 * (`student_guardian_relationships_one_primary_per_student`, Phase
 * 1A.2) rejecting the losing transaction's final UPDATE, exactly like
 * App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException
 * does for AcademicYear::activate().
 */
class ConcurrentPrimaryGuardianConflictException extends GuardianException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'CONCURRENT_PRIMARY_GUARDIAN_CONFLICT',
            'Another primary Guardian change for this Student was committed at the same time. Please retry.',
        );
    }
}
