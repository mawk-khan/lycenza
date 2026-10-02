<?php

namespace App\Domain\Guardians\Application\Retention;

use App\Domain\Students\Application\Retention\StudentCoreParticipant;
use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21-D7 operational Student history (docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): a Student's Guardian
 * relationships are kept 7 calendar years after the Student's final exit,
 * then deleted. Only `platform:student-retention-prune` (and a reviewed
 * erasure case) reach this, through App\Support\Retention\StudentRetention.
 *
 * - The relationship is ordinary SIS history, not part of the formal
 *   academic record: the record (identity, placements, subjects) is
 *   readable without it.
 * - The existing unlink is already a hard delete whose history lives only
 *   in audit (D1). Retention does not change that workflow.
 * - Only the link row goes. The Guardian, its contacts and its Documents
 *   are Guardian personal data (E21.2G G1, mechanism E21.3C), so they are
 *   kept.
 * - Locking and the exit recheck come from
 *   StudentRetentionEligibility::purgeExitedBefore(). Units are Students.
 *
 * E21.3B: a relationship that another retained record references is that
 * record's evidence, not operational history. Today that is a
 * guardian-consent processing authorization, which names the relationship
 * as its consent provider and is kept with the Student core record
 * (E21.2G P1). So:
 * - the OPERATIONAL phase removes only the relationships nothing
 *   references (read from the FK catalog, so a new referencing table keeps
 *   its rows too);
 * - as a CORE participant (StudentCoreParticipant), the remaining
 *   relationships go with the core record, after the core unit has removed
 *   what referenced them. Any other referencing row still blocks.
 */
final class GuardianRelationshipRetentionService implements StudentCoreParticipant
{
    private const TABLE = 'student_guardian_relationships';

    public function __construct(
        private readonly StudentRetentionEligibility $students,
        private readonly ReferencingRows $references,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        $unreferenced = fn (string $studentId): Builder => $this->unreferenced(DB::table(self::TABLE.' as r')->select('r.id')
            ->where('r.school_id', $school->id)->where('r.student_id', $studentId));

        return $this->students->purgeExitedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $students) => $students->whereExists(fn (Builder $q) => $this->unreferenced($q->selectRaw('1')->from(self::TABLE.' as r')->whereColumn('r.student_id', 'students.id'))),
            fn (string $studentId): array => array_filter([$this->references->first(self::TABLE, $school->id, $unreferenced($studentId)->pluck('id')->all())]),
            fn (string $studentId): ?array => DB::table(self::TABLE)->whereIn('id', $unreferenced($studentId)->pluck('id')->all())->delete() > 0 ? [] : null,
            $only,
        );
    }

    public function tables(): array
    {
        return [self::TABLE];
    }

    public function blocker(string $schoolId, string $studentId, array $enrollmentIds, array $cleared, array $unitTables): ?string
    {
        // An unreferenced relationship is operational history: until the
        // operational phase removes it, it keeps the Student.
        if (! in_array(self::TABLE, $cleared, true)
            && $this->unreferenced(DB::table(self::TABLE.' as r')->where('r.school_id', $schoolId)->where('r.student_id', $studentId))->exists()) {
            return self::TABLE;
        }

        $ids = DB::table(self::TABLE)->where('school_id', $schoolId)->where('student_id', $studentId)->pluck('id')->all();

        return $this->references->first(self::TABLE, $schoolId, $ids, $unitTables);
    }

    public function purge(School $school, string $studentId, string $cutoffDate): void
    {
        DB::table(self::TABLE)->where('school_id', $school->id)->where('student_id', $studentId)->delete();
    }

    /** Narrows `r` to relationships no row in any referencing table points at. */
    private function unreferenced(Builder $query): Builder
    {
        foreach ($this->references->to(self::TABLE) as $reference) {
            $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from($reference['table'])->whereColumn($reference['table'].'.'.$reference['column'], 'r.id'));
        }

        return $query;
    }
}
