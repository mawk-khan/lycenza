<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase 1H.1: a small, new, Students-owned bounded read boundary over
 * `student_subject_enrollments` for ONE SubjectOffering, covering EVERY
 * status (`active`/`withdrawn`/`cancelled`/`transferred`) --
 * deliberately separate from `SubjectOfferingRosterReadService`, which
 * remains the ONLY authority for CURRENT roster membership
 * (docs/students/PHASE-1H-0-ELECTIVE-ADMINISTRATION-UI-ARCHITECTURE.md
 * §5/§19). This class exists purely for lifecycle understanding in the
 * administration workspace -- it is never consulted to decide roster
 * membership, and it carries no transcript/GPA semantics.
 */
class StudentSubjectEnrollmentHistoryReadService
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @return LengthAwarePaginator<int, StudentSubjectEnrollment>
     */
    public function forOffering(SubjectOffering $offering, int $perPage = 25): LengthAwarePaginator
    {
        return $this->context->withSchool($offering->school, fn () => StudentSubjectEnrollment::query()
            ->where('school_id', $offering->school_id)
            ->where('subject_offering_id', $offering->id)
            ->with('student')
            ->orderByDesc('starts_on')
            ->paginate(min($perPage, 100)));
    }
}
