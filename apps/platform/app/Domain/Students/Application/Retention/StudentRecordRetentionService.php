<?php

namespace App\Domain\Students\Application\Retention;

use App\Domain\Documents\Application\Retention\DocumentParentRetention;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21-D7 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): the Students domain's own retention. Only
 * `platform:student-retention-prune` calls it, never a request. Every
 * purge runs through StudentRetentionEligibility::purgeExitedBefore()
 * (final exit, lock, recheck, one transaction per Student).
 *
 * OPERATIONAL, 7 years after final exit: enrollment rollover items. They
 * are per-Student workflow lines of a School rollover plan; the plan and
 * its grade mappings are School configuration and stay.
 *
 * CORE, 25 years after final exit: the formal academic record. That is the
 * Student identity (number, names, date of birth) with its placements
 * (`student_enrollments`, cancelled ones included as the administrative
 * record) and subject enrollments, which are the academic outcomes
 * implemented today. No marks, results, report cards or transcripts exist.
 * It goes as one unit, and only when nothing else still needs the Student:
 * - every referencing row in every other table blocks it (ReferencingRows,
 *   read from the FK catalog). That covers Finance, Communications,
 *   Admissions, processing authorizations, Library, Transport, Hostel,
 *   Canteen and invitations, and also operational rows not yet expired.
 *   The Student is then `dependency_blocked` and kept; nothing is cascaded;
 * - a Student account link must be revoked and older than the D6 authority
 *   period (`retention.authority_history_years`); otherwise it blocks;
 * - the Student's Documents go with it (DocumentParentRetention), bytes
 *   after commit.
 * The root is deleted LAST, after the rows above, so its remaining
 * cascades reach only the verified, approved set (revoked old account
 * links). It is never a raw `DELETE FROM students WHERE ...`.
 */
final class StudentRecordRetentionService
{
    /**
     * The operational rows the operational phase clears for an exited
     * Student. A dry run of both phases treats them as cleared when it
     * evaluates the core record, because the destructive run removes them
     * first. A destructive core run always checks the real rows.
     */
    public const OPERATIONAL_TABLES = ['attendance_records', 'enrollment_rollover_items', 'student_guardian_relationships'];

    /** Dependents the core purge removes itself (or that it verified above). */
    private const CORE_HANDLED = ['student_enrollments', 'student_subject_enrollments', 'documents', 'student_guardian_account_links'];

    public function __construct(
        private readonly StudentRetentionEligibility $students,
        private readonly ReferencingRows $references,
        private readonly DocumentParentRetention $documents,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function pruneRolloverItems(School $school, string $cutoffDate, int $batch, bool $dryRun): array
    {
        $items = fn (string $studentId): Builder => DB::table('enrollment_rollover_items')->where('student_id', $studentId);

        return $this->students->purgeExitedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $students) => $students->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('enrollment_rollover_items as i')->whereColumn('i.student_id', 'students.id')),
            fn (string $studentId): array => array_filter([$this->references->first('enrollment_rollover_items', $school->id, $items($studentId)->pluck('id')->all())]),
            fn (string $studentId): ?array => $items($studentId)->delete() > 0 ? [] : null,
        );
    }

    /**
     * @param  CarbonInterface|null  $authorityCutoff  UTC: an account link revoked before it is past D6; null = D6 unset, links block
     * @param  bool  $afterOperational  counting only: the operational phase of the same run clears OPERATIONAL_TABLES first
     * @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function pruneCore(School $school, string $cutoffDate, ?CarbonInterface $authorityCutoff, int $batch, bool $dryRun, bool $afterOperational = false): array
    {
        $cleared = $dryRun && $afterOperational ? self::OPERATIONAL_TABLES : [];

        return $this->students->purgeExitedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            null,
            fn (string $studentId): array => array_filter([$this->coreBlocker($school->id, $studentId, $authorityCutoff, $cleared)]),
            function (string $studentId): array {
                $objects = $this->documents->purgeWithOwner('student', $studentId);
                DB::table('student_subject_enrollments')->where('student_id', $studentId)->delete();
                DB::table('student_enrollments')->where('student_id', $studentId)->delete();
                DB::table('students')->where('id', $studentId)->delete();

                return $objects;
            },
        );
    }

    /**
     * The first retained dependent that keeps this Student, or null. Student
     * references are checked first (most are indexed by `(school_id,
     * student_id)` and catch Finance at once), then placements, subjects
     * and Documents.
     */
    /** @param  list<string>  $cleared */
    private function coreBlocker(string $schoolId, string $studentId, ?CarbonInterface $authorityCutoff, array $cleared): ?string
    {
        $blocker = $this->references->first('students', $schoolId, [$studentId], [...self::CORE_HANDLED, ...$cleared]);
        if ($blocker !== null) {
            return $blocker;
        }

        $liveLink = DB::table('student_guardian_account_links')->where('school_id', $schoolId)->where('student_id', $studentId)
            ->where(fn (Builder $q) => $authorityCutoff === null
                ? $q->whereRaw('true')
                : $q->where('status', '!=', 'revoked')->orWhereNull('unlinked_at')->orWhere('unlinked_at', '>=', $authorityCutoff->format('Y-m-d H:i:s')))
            ->exists();
        if ($liveLink) {
            return 'student_guardian_account_links';
        }

        return $this->references->first('student_enrollments', $schoolId, DB::table('student_enrollments')->where('student_id', $studentId)->pluck('id')->all(), ['student_subject_enrollments', ...$cleared])
            ?? $this->references->first('student_subject_enrollments', $schoolId, DB::table('student_subject_enrollments')->where('student_id', $studentId)->pluck('id')->all())
            ?? $this->references->first('documents', $schoolId, $this->documents->idsOwnedBy('student', $studentId));
    }
}
