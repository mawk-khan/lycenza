<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * Defense-in-depth ahead of the database's own composite-FK rejection
 * (`aa_academic_year_school_foreign`/`aa_campus_school_foreign`/
 * `aa_grade_level_school_foreign`, Phase 1D.1) -- this service never
 * relies on that FK failure alone to catch an Applicant/AcademicYear/
 * Campus/GradeLevel combination spanning two different Schools; it
 * checks first and fails with a clean domain error, mirroring
 * App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException's
 * identical rationale. Checked before any other creation-time
 * validation (root CLAUDE.md rule 10's "reject School mismatches
 * before other status/context-specific checks that could leak
 * foreign-tenant state" principle).
 */
class CrossSchoolAdmissionReferenceException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'CROSS_SCHOOL_ADMISSION_REFERENCE',
            'Applicant, AcademicYear, Campus, and GradeLevel must all belong to the same School to create an Admission Application.',
        );
    }
}
