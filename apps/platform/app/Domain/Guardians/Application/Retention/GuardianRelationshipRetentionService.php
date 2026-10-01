<?php

namespace App\Domain\Guardians\Application\Retention;

use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21-D7 operational Student history (docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): a Student's Guardian
 * relationships are kept 7 calendar years after the Student's final exit,
 * then deleted. Only `platform:student-retention-prune` calls this.
 *
 * - The relationship is ordinary SIS history, not part of the formal
 *   academic record: the record (identity, placements, subjects) is
 *   readable without it.
 * - The existing unlink is already a hard delete whose history lives only
 *   in audit (D1). Retention does not change that workflow.
 * - Only the link row goes. The Guardian, its contacts and its Documents
 *   are Guardian personal data: no adopted period exists for them (D10,
 *   E21.2F/G), so they are kept.
 * - A relationship still referenced by a retained record (today: a
 *   Student processing authorization, which cannot be deleted) is kept, and
 *   the Student counts as `dependency_blocked`.
 * - Locking and the exit recheck come from
 *   StudentRetentionEligibility::purgeExitedBefore(). Units are Students.
 */
final class GuardianRelationshipRetentionService
{
    public function __construct(
        private readonly StudentRetentionEligibility $students,
        private readonly ReferencingRows $references,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        $relationships = fn (string $studentId): Builder => DB::table('student_guardian_relationships')->where('student_id', $studentId);

        return $this->students->purgeExitedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $students) => $students->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('student_guardian_relationships as r')->whereColumn('r.student_id', 'students.id')),
            fn (string $studentId): array => array_filter([$this->references->first('student_guardian_relationships', $school->id, $relationships($studentId)->pluck('id')->all())]),
            fn (string $studentId): ?array => $relationships($studentId)->delete() > 0 ? [] : null,
            $only,
        );
    }
}
