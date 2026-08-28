<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Phase 1H.1: the narrow, workflow-specific read adapter the elective
 * administration workspace needs -- and the JSON API's generic
 * `/app/students` (`students.view`-gated) picker deliberately does NOT
 * serve (docs/students/PHASE-1H-0-ELECTIVE-ADMINISTRATION-UI-ARCHITECTURE.md
 * §8, the "1D Guardian-picker lesson"). Gated only by
 * `academics.subjects.manage` at the controller layer -- this class
 * itself is authorization-neutral, matching every other Application
 * service in this codebase.
 *
 * Deliberately reimplements the SAME simple compatibility predicates
 * `StudentSubjectEnrollmentService::resolveCompatibleEnrollment()`
 * already expresses (School + AcademicYear + GradeLevel + Campus,
 * `StudentEnrollment.status = 'active'`) rather than that method being
 * made `public` merely for a picker (root task §20) -- `enroll()`/
 * `transfer()` remain the sole authoritative validator at submit time
 * regardless of what this read-only convenience returns.
 */
class ElectiveEnrollmentCandidateReadService
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * Students with a CURRENT active StudentEnrollment compatible with
     * `$offering`'s AcademicYear/GradeLevel/Campus, excluding anyone
     * already `active` in this EXACT Offering (a read-only UI
     * convenience -- the backend's own unique index remains the real
     * authority, root task §16). Section is deliberately not filtered.
     *
     * @return array<int, array{student: array<string, mixed>, studentEnrollmentId: string, section: array{id: string, name: string}|null, hasCurrentGroupConflict: bool}>
     */
    public function eligibleStudents(SubjectOffering $offering, ?string $search = null, int $limit = 25): array
    {
        return $this->context->withSchool($offering->school, function () use ($offering, $search, $limit) {
            $enrollments = StudentEnrollment::query()
                ->where('school_id', $offering->school_id)
                ->where('status', 'active')
                ->where('academic_year_id', $offering->academic_year_id)
                ->where('grade_level_id', $offering->grade_level_id)
                ->where('campus_id', $offering->campus_id)
                ->whereNotExists(function ($query) use ($offering) {
                    $query->selectRaw('1')
                        ->from('student_subject_enrollments')
                        ->whereColumn('student_subject_enrollments.student_id', 'student_enrollments.student_id')
                        ->where('student_subject_enrollments.subject_offering_id', $offering->id)
                        ->where('student_subject_enrollments.status', 'active');
                })
                ->when($search !== null && $search !== '', function ($query) use ($search) {
                    $term = '%'.$search.'%';
                    $query->whereExists(function ($sub) use ($term) {
                        $sub->selectRaw('1')
                            ->from('students')
                            ->whereColumn('students.id', 'student_enrollments.student_id')
                            ->where(fn ($q) => $q->where('students.first_name', 'ilike', $term)
                                ->orWhere('students.last_name', 'ilike', $term)
                                ->orWhere('students.student_number', 'ilike', $term));
                    });
                })
                ->with(['student', 'section'])
                ->orderBy('roll_number')
                ->limit(min($limit, 100))
                ->get();

            $conflictingStudentIds = $offering->elective_group_id === null
                ? []
                : StudentSubjectEnrollment::query()
                    ->where('school_id', $offering->school_id)
                    ->where('elective_group_id', $offering->elective_group_id)
                    ->where('status', 'active')
                    ->pluck('student_id')
                    ->all();

            return $enrollments->map(fn (StudentEnrollment $e) => [
                'student' => $this->presentStudent($e->student),
                'studentEnrollmentId' => $e->id,
                'section' => $e->section === null ? null : ['id' => $e->section->id, 'name' => $e->section->name],
                'hasCurrentGroupConflict' => in_array($e->student_id, $conflictingStudentIds, true),
            ])->values()->all();
        });
    }

    /**
     * Compatible target SubjectOfferings for transferring `$source`
     * away from -- same School/AcademicYear/GradeLevel/Campus as the
     * Student's CURRENT active StudentEnrollment (re-derived fresh,
     * never trusted from the source row's own placement anchor, which
     * may be stale -- mirrors `transfer()`'s own re-resolution).
     * Elective-only, active, excludes the source Offering itself.
     * Deliberately does NOT exclude same-ElectiveGroup targets (root
     * task §32) -- same-group transfer is valid and required to work;
     * `transfer()` alone remains authoritative for conflict rejection.
     *
     * @return Collection<int, SubjectOffering>
     */
    public function transferTargets(StudentSubjectEnrollment $source, int $limit = 100): Collection
    {
        return $this->context->withSchool($source->school, function () use ($source, $limit) {
            $currentEnrollment = StudentEnrollment::query()
                ->where('school_id', $source->school_id)
                ->where('student_id', $source->student_id)
                ->where('status', 'active')
                ->where('academic_year_id', $source->academic_year_id)
                ->first();

            if ($currentEnrollment === null) {
                return collect();
            }

            return SubjectOffering::query()
                ->where('school_id', $source->school_id)
                ->where('academic_year_id', $currentEnrollment->academic_year_id)
                ->where('campus_id', $currentEnrollment->campus_id)
                ->where('grade_level_id', $currentEnrollment->grade_level_id)
                ->where('is_required', false)
                ->where('status', 'active')
                ->where('id', '!=', $source->subject_offering_id)
                ->with(['subject', 'campus', 'gradeLevel', 'electiveGroup'])
                ->orderBy('sequence')
                ->limit(min($limit, 100))
                ->get();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStudent(Student $student): array
    {
        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
        ];
    }
}
