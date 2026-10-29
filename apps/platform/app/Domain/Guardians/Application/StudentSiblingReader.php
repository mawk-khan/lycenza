<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantContext;

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
 * Bounded: three small queries (the Student's active Guardians, at most
 * $limit + 1 sibling ids, their shared Guardians), deterministic order.
 */
final class StudentSiblingReader
{
    public const int LIMIT = 20;

    /** A Student has a handful of Guardians; the read is bounded regardless. */
    private const int MAX_GUARDIANS = 10;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, list<string>> sibling Student id => the shared active Guardian ids (at most $limit + 1 siblings: one more than shown tells the caller the list is truncated)
     */
    public function siblingsOf(Student $student, int $limit = self::LIMIT): array
    {
        return $this->context->withSchool($student->school, function () use ($student, $limit): array {
            // The shared, ACTIVE Guardians of this Student (one bounded query).
            $guardianIds = StudentGuardianRelationship::query()
                ->toBase()
                ->join('guardians', fn ($j) => $j->on('guardians.id', '=', 'student_guardian_relationships.guardian_id')
                    ->on('guardians.school_id', '=', 'student_guardian_relationships.school_id'))
                ->where('student_guardian_relationships.school_id', $student->school_id)
                ->where('student_guardian_relationships.student_id', $student->id)
                ->where('guardians.status', 'active')
                ->orderBy('student_guardian_relationships.guardian_id')
                ->limit(self::MAX_GUARDIANS)
                ->pluck('student_guardian_relationships.guardian_id')
                ->map(fn ($id) => (string) $id)
                ->all();
            if ($guardianIds === []) {
                return [];
            }

            // Their other Students, deterministic, at most $limit + 1 of them.
            $siblingIds = StudentGuardianRelationship::query()
                ->toBase()
                ->where('school_id', $student->school_id)
                ->whereIn('guardian_id', $guardianIds)
                ->where('student_id', '!=', $student->id)
                ->distinct()
                ->orderBy('student_id')
                ->limit($limit + 1)
                ->pluck('student_id')
                ->map(fn ($id) => (string) $id)
                ->all();
            if ($siblingIds === []) {
                return [];
            }

            $siblings = array_fill_keys($siblingIds, []);
            foreach (StudentGuardianRelationship::query()->toBase()
                ->where('school_id', $student->school_id)
                ->whereIn('guardian_id', $guardianIds)
                ->whereIn('student_id', $siblingIds)
                ->orderBy('guardian_id')
                ->get(['student_id', 'guardian_id']) as $row) {
                $siblings[(string) $row->student_id][] = (string) $row->guardian_id;
            }

            return $siblings;
        });
    }
}
