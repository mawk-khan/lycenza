<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 1C.1 -- the ONE authoritative "SubjectOffering -> current
 * eligible Student roster" query, the exact integration seam the Phase
 * 5C.1 Communication checkpoint stopped and asked for
 * (docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md
 * "Contract exposed for future Phase 5C.1"). A future Communication
 * resolver calls `currentRosterStudentIds()`/`currentRosterCount()`
 * without knowing anything about REQUIRED-vs-elective offering
 * semantics, StudentSubjectEnrollment's schema, or how compatibility is
 * derived -- it only sees Student ids and a count, matching the
 * dependency direction Communications -> Academic roster read model,
 * never the reverse (this class has zero references to any
 * Communications type).
 *
 * Deliberately set-based (a single query per method, no per-Student
 * loop) -- see the checkpoint's query-count/scale test.
 *
 * Phase 1C.1A: an inactive SubjectOffering always reports an empty
 * current roster ([] / 0), for both required and elective offerings,
 * short-circuiting before any query runs -- an inactive offering must
 * never surface as a Communications audience. This is a READ-time
 * current-eligibility gate only: existing StudentSubjectEnrollment
 * history rows are never touched, and reactivating the offering
 * restores the ordinary roster derivation with no other state change
 * required.
 */
class SubjectOfferingRosterReadService
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<int, string>
     */
    public function currentRosterStudentIds(SubjectOffering $offering): array
    {
        if (! $offering->isActive()) {
            return [];
        }

        return $this->context->withSchool($offering->school, fn () => $offering->is_required
            ? $this->impliedRosterQuery($offering)->pluck('id')->all()
            : $this->explicitRosterQuery($offering)->pluck('student_id')->all());
    }

    public function currentRosterCount(SubjectOffering $offering): int
    {
        if (! $offering->isActive()) {
            return 0;
        }

        return $this->context->withSchool($offering->school, fn () => $offering->is_required
            ? $this->impliedRosterQuery($offering)->count()
            : $this->explicitRosterQuery($offering)->count());
    }

    /**
     * A REQUIRED offering's roster is every Student whose CURRENT active
     * StudentEnrollment matches this offering's AcademicYear/GradeLevel/
     * Campus -- no StudentSubjectEnrollment row is ever consulted. Every
     * Student appears at most once (StudentEnrollment's own partial
     * unique index already guarantees at most one active row per
     * Student per AcademicYear).
     */
    private function impliedRosterQuery(SubjectOffering $offering)
    {
        return StudentEnrollment::query()
            ->select('student_id as id')
            ->where('school_id', $offering->school_id)
            ->where('status', 'active')
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('grade_level_id', $offering->grade_level_id)
            ->where('campus_id', $offering->campus_id)
            ->distinct();
    }

    /**
     * An ELECTIVE offering's roster is every Student with an active
     * StudentSubjectEnrollment row for it, RE-VALIDATED here (not
     * trusted from write time) against a still-compatible current
     * active StudentEnrollment -- this join is exactly what makes a
     * Student who was promoted/transferred out of the compatible
     * placement AFTER enrolling drop out of the current roster
     * automatically, without StudentSubjectEnrollmentService or
     * StudentEnrollmentService needing any knowledge of each other (see
     * the creating migration's docblock, "Lifecycle when Student moves
     * Grade/Section").
     */
    private function explicitRosterQuery(SubjectOffering $offering)
    {
        return StudentSubjectEnrollment::query()
            ->where('student_subject_enrollments.school_id', $offering->school_id)
            ->where('student_subject_enrollments.subject_offering_id', $offering->id)
            ->where('student_subject_enrollments.status', 'active')
            ->whereExists(function ($query) use ($offering) {
                $query->selectRaw('1')
                    ->from('student_enrollments')
                    ->whereColumn('student_enrollments.student_id', 'student_subject_enrollments.student_id')
                    ->where('student_enrollments.school_id', $offering->school_id)
                    ->where('student_enrollments.status', 'active')
                    ->where('student_enrollments.academic_year_id', $offering->academic_year_id)
                    ->where('student_enrollments.grade_level_id', $offering->grade_level_id)
                    ->where('student_enrollments.campus_id', $offering->campus_id);
            })
            ->distinct();
    }
}
