<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;

/**
 * SIS.EXP-1: the canonical sibling derivation -- no household table.
 *
 * Two Students of the SAME School are siblings when they share a linked
 * Guardian whose record is ACTIVE (a relationship row exists only while
 * linked: unlinking deletes it). An inactive Guardian is not a family
 * link for this purpose. Relationship flags (primary, legal, emergency,
 * pickup) do not matter: the fact is the shared Guardian.
 *
 * Authorization-neutral: this answers WHO, never whether the viewer may see
 * them. The caller shows a sibling only to a viewer independently allowed
 * to view that Student (the staff profile is whole-School `students.view`
 * only). Sharing a Guardian never grants staff authority, and this is
 * never used for a Guardian-facing view (Guardians see only their own
 * linked Students, ActingGuardianResolver).
 *
 * SIS.HARDEN-1 (SIS.EXP-R1 finding R-6): the candidate set is a SQL
 * sub-query with NO limit, so the caller applies the viewer's authority
 * first and only then its display limit -- a hidden Student never takes a
 * slot, and nothing counts Students the viewer may not see.
 *
 * Bounded: the sub-query runs inside the caller's one authorized query; the
 * shared Guardians of the shown siblings are one more query.
 */
final class StudentSiblingReader
{
    /** The display limit the caller applies AFTER its authorization. */
    public const int LIMIT = 20;

    /** A Student has a handful of Guardians; the read is bounded regardless. */
    private const int MAX_GUARDIANS = 10;

    public function __construct(private readonly TenantContext $context) {}

    /** The ids of the Student's other-Students-through-an-active-shared-Guardian, as an unexecuted sub-query (no limit, no order). */
    public function siblingIds(Student $student): Builder
    {
        return StudentGuardianRelationship::query()->toBase()
            ->select('student_guardian_relationships.student_id')
            ->where('student_guardian_relationships.school_id', $student->school_id)
            ->whereIn('student_guardian_relationships.guardian_id', $this->activeGuardianIds($student))
            ->where('student_guardian_relationships.student_id', '!=', $student->id);
    }

    /**
     * @param  list<string>  $siblingIds  the siblings the caller will show (already authorized and limited)
     * @return array<string, list<string>> sibling Student id => the shared active Guardian ids
     */
    public function sharedGuardians(Student $student, array $siblingIds): array
    {
        if ($siblingIds === []) {
            return [];
        }

        return $this->context->withSchool($student->school, function () use ($student, $siblingIds): array {
            $shared = array_fill_keys($siblingIds, []);
            foreach (StudentGuardianRelationship::query()->toBase()
                ->where('school_id', $student->school_id)
                ->whereIn('guardian_id', $this->activeGuardianIds($student))
                ->whereIn('student_id', $siblingIds)
                ->orderBy('guardian_id')
                ->get(['student_id', 'guardian_id']) as $row) {
                $shared[(string) $row->student_id][] = (string) $row->guardian_id;
            }

            return $shared;
        });
    }

    /** The Student's linked, ACTIVE Guardians, deterministic and bounded (a sub-query). */
    private function activeGuardianIds(Student $student): Builder
    {
        return StudentGuardianRelationship::query()->toBase()
            ->select('student_guardian_relationships.guardian_id')
            ->join('guardians', fn ($j) => $j->on('guardians.id', '=', 'student_guardian_relationships.guardian_id')
                ->on('guardians.school_id', '=', 'student_guardian_relationships.school_id'))
            ->where('student_guardian_relationships.school_id', $student->school_id)
            ->where('student_guardian_relationships.student_id', $student->id)
            ->where('guardians.status', 'active')
            ->orderBy('student_guardian_relationships.guardian_id')
            ->limit(self::MAX_GUARDIANS);
    }
}
