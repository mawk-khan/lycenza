<?php

namespace App\Domain\Library\Application\Retention;

use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21.3B (E21.2G O1, E21-D7 operational, project-adopted, pending legal
 * ratification): a Student's Library loans are D7 operational Student
 * history, kept 7 calendar years after the Student's final exit
 * (StudentRetentionEligibility, the one definition of "left the School"),
 * then deleted. Only `platform:student-retention-prune` (and a reviewed
 * erasure case) reach this, through App\Support\Retention\StudentRetention.
 *
 * - Only TERMINAL rows go: `status = 'returned'` (checked in; a returned
 *   loan is never reopened). An unreturned (`active`) loan is a live
 *   relationship: it is never deleted on age, and it keeps the Student (the
 *   core purge sees it as a retained reference).
 * - OPF.4 (ADR 0067 §30): an overdue loan may carry a Library fine, which is
 *   Finance evidence kept with its charge; it references the loan, so that
 *   loan stays (`dependency_blocked`, below) while the fine exists. Titles
 *   and copies are School inventory and stay.
 * - Locking, the exit recheck (a re-entry that committed first keeps
 *   everything) and one transaction per Student come from
 *   StudentRetentionEligibility::purgeExitedBefore(). The predicate is
 *   re-applied at delete time, so a concurrent change is never lost.
 * - A row another table still references is never cascaded away
 *   (`dependency_blocked`). Units are Students. Counts only in logs.
 */
final class LibraryLoanRetentionService
{
    private const TABLE = 'library_loans';

    public function __construct(
        private readonly StudentRetentionEligibility $students,
        private readonly ReferencingRows $references,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('library_loan', $dryRun, $school->id, ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneUnit($school, $cutoffDate, $batch, $dryRun, $only), recordedBefore: $cutoffDate);
    }

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneUnit(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        $terminal = fn (string $studentId): Builder => DB::table(self::TABLE)->where('school_id', $school->id)->where('student_id', $studentId)->where('status', 'returned');

        return $this->students->purgeExitedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $students) => $students->whereExists(fn (Builder $q) => $q->selectRaw('1')->from(self::TABLE.' as t')->whereColumn('t.student_id', 'students.id')->where('t.status', 'returned')),
            fn (string $studentId): array => array_filter([$this->references->first(self::TABLE, $school->id, $terminal($studentId)->pluck('id')->all())]),
            fn (string $studentId): ?array => $terminal($studentId)->delete() > 0 ? [] : null,
            $only,
        );
    }

    /** Whether the Student still holds an unreturned row, which the operational phase never removes. */
    public function hasOpen(string $studentId): bool
    {
        return DB::table(self::TABLE)->where('student_id', $studentId)->where('status', 'active')->exists();
    }
}
