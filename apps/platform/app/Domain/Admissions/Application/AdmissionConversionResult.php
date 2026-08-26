<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;

/**
 * The outcome of a successful `AdmissionConversionService::convert()`
 * call -- an immutable, typed result (matching
 * `App\Domain\HR\Application\EmployeeImportRowResult`'s established DTO
 * shape) rather than a loosely-structured array, so a future API layer
 * can build a response without re-deriving these relationships.
 * `$guardian`/`$relationship` are null when the conversion command
 * carried no Guardian instruction -- Guardian participation is optional
 * (`docs/modules/ADMISSIONS.md` §11).
 */
final class AdmissionConversionResult
{
    private function __construct(
        public readonly AdmissionApplication $application,
        public readonly Student $student,
        public readonly StudentEnrollment $enrollment,
        public readonly ?Guardian $guardian,
        public readonly ?StudentGuardianRelationship $relationship,
    ) {}

    public static function make(
        AdmissionApplication $application,
        Student $student,
        StudentEnrollment $enrollment,
        ?Guardian $guardian = null,
        ?StudentGuardianRelationship $relationship = null,
    ): self {
        return new self($application, $student, $enrollment, $guardian, $relationship);
    }
}
