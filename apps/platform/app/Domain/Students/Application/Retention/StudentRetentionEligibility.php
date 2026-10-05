<?php

namespace App\Domain\Students\Application\Retention;

use App\Models\School;
use App\Support\Retention\RetentionLocks;
use App\Support\Retention\RetentionUnit;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * E21-D7 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): the ONE canonical answer to "when did this
 * Student leave the School?". Every D7 retention operation asks it: Students
 * (rollover items, the core record), Attendance and Guardians. Documents
 * follow their Student parent. It is used only by maintenance commands,
 * never by a request, and is independent of Student authorization.
 *
 * The repository has no dated "left the School" record. `students.status`
 * is only `active`/`inactive`, has no date and can be reactivated, and
 * re-admission is deferred (docs/students/PHASE-1E-0-STUDENT-LIFECYCLE-ARCHITECTURE.md).
 * The dated departure facts are Enrollment terminal transitions (`ends_on`
 * is the inclusive last day). A Student has EXITED only when ALL hold:
 * - `students.status` is `inactive` (the only person-level "no longer
 *   attending" signal);
 * - no non-cancelled Enrollment is `active` or open (`ends_on` null), and
 *   no Subject Enrollment is `active`;
 * - at least one non-cancelled Enrollment exists, and every Enrollment
 *   ending on the latest `ends_on` is `completed` or `withdrawn`.
 *
 * The exit date is that latest `ends_on`. A `cancelled` Enrollment never
 * ran as a placement, so it never dates a departure. Anything else that is
 * `inactive` is UNRESOLVED and kept, for example:
 * - an inactive Student still holding an active placement;
 * - one who was never placed;
 * - one whose last placement ended `transferred`.
 * Nothing is inferred from `updated_at`, attendance, curriculum or
 * inactivity.
 *
 * Re-entry: a new Enrollment, or reactivation to `active`, makes the Student
 * current again. Eligibility is therefore recomputed on every run, and
 * inside every purge after `lockExit()`. That method locks the Student row
 * FOR UPDATE, which conflicts with the FOR KEY SHARE an Enrollment insert
 * takes on its Student and with any Student UPDATE. So a re-entry either
 * commits first (the recheck sees it, nothing is purged) or waits for the
 * purge. Only the Student row is locked; Enrollment, Section and Attendance
 * locks are left to their owners (Section-before-Enrollment order,
 * StudentEnrollmentService).
 */
final class StudentRetentionEligibility
{
    /** Enrollment terminal statuses that date a departure. */
    public const DEPARTURE_STATUSES = ['completed', 'withdrawn'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The pure rule. The dry run and every purge use it, so they always agree.
     *
     * E21-RH.6 (owner decision 2026-10-05): the exit date is the latest
     * `ends_on`, or -- if later -- the latest date the database recorded an
     * end (`recorded_on`, from `ended_recorded_at`, which no caller can set).
     * A backdated end therefore never shortens the period.
     *
     * @param  list<array{status: string, ends_on: ?string, recorded_on?: ?string}>  $enrollments  every Enrollment of the Student
     */
    public static function resolve(string $studentStatus, array $enrollments, bool $hasActiveSubjectEnrollment): StudentExit
    {
        if ($studentStatus !== 'inactive') {
            return StudentExit::current();
        }

        $placements = array_values(array_filter($enrollments, fn (array $e): bool => $e['status'] !== 'cancelled'));

        if ($placements === [] || $hasActiveSubjectEnrollment) {
            return StudentExit::unresolved();
        }

        foreach ($placements as $placement) {
            if ($placement['status'] === 'active' || $placement['ends_on'] === null) {
                return StudentExit::unresolved();
            }
        }

        $last = max(array_map(fn (array $e): string => (string) $e['ends_on'], $placements));

        foreach ($placements as $placement) {
            if ($placement['ends_on'] === $last && ! in_array($placement['status'], self::DEPARTURE_STATUSES, true)) {
                return StudentExit::unresolved();
            }
        }

        $recorded = array_filter(array_map(fn (array $e): ?string => $e['recorded_on'] ?? null, $placements));

        return StudentExit::exited($recorded === [] ? $last : max($last, max($recorded)));
    }

    /**
     * Walks one School's inactive Students in id order, resolving each in
     * bulk (one Enrollment query per chunk). It calls `$each` for every
     * Student who exited strictly before `$cutoffDate`.
     *
     * @param  (Closure(Builder): mixed)|null  $withRows  narrows to Students that still hold the caller's own rows
     * @param  Closure(string, StudentExit): void  $each
     * @param  string|null  $only  narrows the walk to one Student (an erasure case)
     * @return int the number of walked Students whose exit is unresolved
     */
    public function exitedBefore(School $school, string $cutoffDate, int $batch, ?Closure $withRows, Closure $each, ?string $only = null): int
    {
        return $this->context->withSchool($school, function () use ($school, $cutoffDate, $batch, $withRows, $each, $only): int {
            $unresolved = 0;
            $query = DB::table('students')->where('school_id', $school->id)->where('status', 'inactive')->select('id', 'status');
            if ($only !== null) {
                // E21.2F: one reviewed erasure case's subject only.
                $query->where('id', $only);
            }
            if ($withRows !== null) {
                $withRows($query);
            }

            $query->orderBy('id')->chunkById($batch, function ($students) use ($cutoffDate, $each, &$unresolved): void {
                $ids = $students->pluck('id')->all();
                $enrollments = DB::table('student_enrollments')->whereIn('student_id', $ids)->get(['student_id', 'status', 'ends_on', 'ended_recorded_at'])->groupBy('student_id');
                $activeSubjects = DB::table('student_subject_enrollments')->whereIn('student_id', $ids)->where('status', 'active')->distinct()->pluck('student_id')->flip();

                foreach ($students as $student) {
                    $exit = self::resolve($student->status, $this->rows($enrollments->get($student->id)?->all() ?? []), $activeSubjects->has($student->id));

                    if ($exit->state === StudentExit::UNRESOLVED) {
                        $unresolved++;
                    } elseif ($exit->exitedBefore($cutoffDate)) {
                        $each($student->id, $exit);
                    }
                }
            });

            return $unresolved;
        });
    }

    /**
     * The one per-Student purge loop every D7 operation uses, so the dry run
     * and the destructive run share eligibility, locking and dependency
     * rules. For each Student who exited strictly before `$cutoffDate` (and,
     * with `$withRows`, still holds the caller's rows):
     * - dry run: counts it, and counts `dependency_blocked` when `$blockers`
     *   names any retained dependent;
     * - otherwise, ONE transaction per Student:
     *   1. `lockExit()` and recheck the exit (a re-entry that committed first
     *      keeps everything);
     *   2. recheck `$blockers` under the lock;
     *   3. run `$purge`, which deletes only the caller's own rows and returns
     *      the stored objects to remove, or null when nothing was deleted;
     *   4. delete those bytes after commit (ObjectDeletion).
     *
     * A database failure on one Student (for example a concurrent insert that
     * makes a RESTRICT FK refuse the delete) rolls back only that Student. It
     * is counted as `errors` and retried on the next run.
     *
     * @param  (Closure(Builder): mixed)|null  $withRows
     * @param  Closure(string): list<string>  $blockers  retained dependents (read-only)
     * @param  Closure(string): (list<object{storage_disk: string, storage_path: string}>|null)  $purge
     * @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function purgeExitedBefore(School $school, string $cutoffDate, int $batch, bool $dryRun, ?Closure $withRows, Closure $blockers, Closure $purge, ?string $only = null): array
    {
        $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];

        $result['unresolved'] = $this->exitedBefore($school, $cutoffDate, $batch, $withRows, function (string $studentId) use (&$result, $dryRun, $cutoffDate, $blockers, $purge): void {
            RetentionUnit::purge(
                $result,
                $dryRun,
                fn (): bool => $this->lockExit($studentId)?->exitedBefore($cutoffDate) === true,
                fn (): array => $blockers($studentId),
                fn (): ?array => $purge($studentId),
            );
        }, $only);

        return $result;
    }

    /**
     * E21.2F (closure readiness, read-only): the latest final exit among a
     * School's Students, and how many are still current or unresolved (no
     * date is known while any is).
     *
     * @return array{latest: ?string, pending: int}
     */
    public function latestExit(School $school, int $batch = 500): array
    {
        return $this->context->withSchool($school, function () use ($school, $batch): array {
            $latest = null;
            $pending = 0;

            DB::table('students')->where('school_id', $school->id)->select('id', 'status')->orderBy('id')->chunkById($batch, function ($students) use (&$latest, &$pending): void {
                $ids = $students->pluck('id')->all();
                $enrollments = DB::table('student_enrollments')->whereIn('student_id', $ids)->get(['student_id', 'status', 'ends_on', 'ended_recorded_at'])->groupBy('student_id');
                $activeSubjects = DB::table('student_subject_enrollments')->whereIn('student_id', $ids)->where('status', 'active')->distinct()->pluck('student_id')->flip();

                foreach ($students as $student) {
                    $exit = self::resolve($student->status, $this->rows($enrollments->get($student->id)?->all() ?? []), $activeSubjects->has($student->id));
                    if ($exit->state === StudentExit::EXITED) {
                        $latest = max($latest ?? $exit->exitDate, $exit->exitDate);
                    } else {
                        $pending++;
                    }
                }
            });

            return ['latest' => $latest, 'pending' => $pending];
        });
    }

    /**
     * E21.2F (erasure planning, read-only): one Student's exit, without a lock.
     * Null means the Student is not in this School's context.
     */
    public function exitOf(School $school, string $studentId): ?StudentExit
    {
        return $this->context->withSchool($school, function () use ($studentId): ?StudentExit {
            $student = DB::table('students')->where('id', $studentId)->first(['status']);
            if ($student === null) {
                return null;
            }

            return self::resolve(
                $student->status,
                $this->rows(DB::table('student_enrollments')->where('student_id', $studentId)->get(['status', 'ends_on', 'ended_recorded_at'])->all()),
                DB::table('student_subject_enrollments')->where('student_id', $studentId)->where('status', 'active')->exists(),
            );
        });
    }

    /**
     * Locks the Student row FOR UPDATE and resolves its exit from committed
     * data read after the lock. Call it only inside a transaction, in the
     * Student's tenant context. Null means the Student no longer exists.
     */
    public function lockExit(string $studentId): ?StudentExit
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('lockExit() must run inside the purge transaction.');
        }

        // E21-RH.6: the retention identity locks through the lock-only definer (FOR UPDATE), then reads.
        $student = RetentionLocks::lockOne('students', $studentId) ? DB::table('students')->where('id', $studentId)->first(['status']) : null;
        if ($student === null) {
            return null;
        }

        $enrollments = DB::table('student_enrollments')->where('student_id', $studentId)->get(['status', 'ends_on', 'ended_recorded_at'])->all();
        $activeSubject = DB::table('student_subject_enrollments')->where('student_id', $studentId)->where('status', 'active')->exists();

        return self::resolve($student->status, $this->rows($enrollments), $activeSubject);
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<array{status: string, ends_on: ?string, recorded_on: ?string}>
     */
    private function rows(array $rows): array
    {
        return array_values(array_map(fn (object $row): array => [
            'status' => (string) $row->status,
            'ends_on' => $row->ends_on === null ? null : substr((string) $row->ends_on, 0, 10),
            'recorded_on' => ($row->ended_recorded_at ?? null) === null ? null : substr((string) $row->ended_recorded_at, 0, 10),
        ], $rows));
    }
}
