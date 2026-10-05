<?php

namespace App\Domain\HR\Application\Retention;

use App\Domain\Documents\Application\Retention\DocumentParentRetention;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionExpiry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21-D9 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): HR's own retention. Only
 * `platform:employee-retention-prune` calls it, never a request. Every
 * purge runs through EmployeeRetentionEligibility::purgeSeparatedBefore()
 * (final separation, lock, recheck, one transaction per Employee).
 *
 * ANCILLARY, 2 years after final separation: the D9-named personal
 * sub-records that are not employment evidence:
 * - addresses, emergency contacts and notes;
 * - qualifications, experience and certifications.
 * They are the sub-records HR may already hard-delete during employment
 * (audited), and nothing references them. Personal details (date of birth,
 * nationality, personal contact) are NOT in D9's ancillary list and stay
 * with the evidence.
 *
 * EVIDENCE, 8 years after final separation: the Employee root (number,
 * name, work contact) with its employment records, assignments, personal
 * details, HR documents (`employee_documents`) and Employee-owned
 * `documents`. It goes as one unit, root LAST, and only when nothing else
 * still needs the Employee:
 * - every referencing row in every other table blocks it (ReferencingRows,
 *   read from the FK catalog). That covers payroll results, adjustments
 *   and LWF charges (until Payroll's own D9 expiry removes them, E21.3F),
 *   Payroll's own compensation and
 *   statutory rows until Payroll's own purge removes them, TeachingAssignments
 *   (D6), Attendance sessions, timetable entries, LMS ownership,
 *   transport and visitor rows, Leave and Staff Attendance evidence (until
 *   their own D9 participants remove it, HRX.6, earlier in the same run;
 *   a leave decision this Employee made as another Employee's manager
 *   stays with that request), and another Employee's assignment that
 *   names one of these assignments as its manager. Nothing is cascaded,
 *   so D6 and TCH history stay interpretable;
 * - a linked User blocks it. Retention never unlinks a User (that is D10,
 *   E21.2F).
 */
final class EmployeeRecordRetentionService
{
    /** The D9 ancillary sub-records (two-year phase). */
    public const ANCILLARY_TABLES = [
        'employee_addresses', 'employee_emergency_contacts', 'employee_notes',
        'employee_qualifications', 'employee_experience_records', 'employee_certifications',
    ];

    /** HR evidence rows the evidence purge removes itself (ancillary ones included: their period is shorter). */
    private const EVIDENCE_HANDLED = ['employment_records', 'employee_personal_details', 'employee_documents', 'documents', ...self::ANCILLARY_TABLES];

    public function __construct(
        private readonly EmployeeRetentionEligibility $employees,
        private readonly ReferencingRows $references,
        private readonly DocumentParentRetention $documents,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function pruneAncillary(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('employee_ancillary', $dryRun, $school->id, ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneAncillaryUnit($school, $cutoffDate, $batch, $dryRun, $only));
    }

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneAncillaryUnit(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        return $this->employees->purgeSeparatedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $q) => $q->where(function (Builder $any): void {
                foreach (self::ANCILLARY_TABLES as $table) {
                    $any->orWhereExists(fn (Builder $e) => $e->selectRaw('1')->from($table)->whereColumn("{$table}.employee_id", 'employees.id'));
                }
            }),
            function (string $employeeId) use ($school): array {
                foreach (self::ANCILLARY_TABLES as $table) {
                    $blocker = $this->references->first($table, $school->id, DB::table($table)->where('employee_id', $employeeId)->pluck('id')->all());
                    if ($blocker !== null) {
                        return [$blocker];
                    }
                }

                return [];
            },
            function (string $employeeId): ?array {
                $deleted = 0;
                foreach (self::ANCILLARY_TABLES as $table) {
                    $deleted += DB::table($table)->where('employee_id', $employeeId)->delete();
                }

                return $deleted > 0 ? [] : null;
            },
            $only,
        );
    }

    /**
     * @param  list<string>  $clearedFirst  counting only: tables an earlier purge of the same run clears first
     * @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function pruneEvidence(School $school, string $cutoffDate, int $batch, bool $dryRun, array $clearedFirst = [], ?string $only = null): array
    {
        // E21-RH.6 (ADR 0066 §14): the whole unit as the retention identity, on its own connection.
        return app(RetentionExpiry::class)->retained('employee_evidence', $dryRun, $school->id, ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], fn (): array => $this->pruneEvidenceUnit($school, $cutoffDate, $batch, $dryRun, $clearedFirst, $only));
    }

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    private function pruneEvidenceUnit(School $school, string $cutoffDate, int $batch, bool $dryRun, array $clearedFirst = [], ?string $only = null): array
    {
        $cleared = $dryRun ? $clearedFirst : [];

        return $this->employees->purgeSeparatedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            null,
            fn (string $employeeId): array => array_filter([$this->evidenceBlocker($school->id, $employeeId, $cleared)]),
            function (string $employeeId): array {
                $objects = DB::table('employee_documents')->where('employee_id', $employeeId)->orderBy('id')->get(['storage_disk', 'storage_path'])->all();
                $objects = [...$objects, ...$this->documents->purgeWithOwner('employee', $employeeId)];
                $employments = DB::table('employment_records')->where('employee_id', $employeeId)->pluck('id')->all();

                foreach ([...self::ANCILLARY_TABLES, 'employee_personal_details', 'employee_documents'] as $table) {
                    DB::table($table)->where('employee_id', $employeeId)->delete();
                }
                DB::table('employee_assignments')->whereIn('employment_record_id', $employments)->delete();
                DB::table('employment_records')->where('employee_id', $employeeId)->delete();
                DB::table('employees')->where('id', $employeeId)->delete();

                return $objects;
            },
            $only,
        );
    }

    /**
     * The first retained dependent that keeps this Employee, or null.
     *
     * @param  list<string>  $cleared
     */
    private function evidenceBlocker(string $schoolId, string $employeeId, array $cleared): ?string
    {
        if (DB::table('employees')->where('id', $employeeId)->whereNotNull('user_id')->exists()) {
            return 'users';
        }

        $blocker = $this->references->first('employees', $schoolId, [$employeeId], [...self::EVIDENCE_HANDLED, ...$cleared]);
        if ($blocker !== null) {
            return $blocker;
        }

        $employments = DB::table('employment_records')->where('employee_id', $employeeId)->pluck('id')->all();
        $assignments = DB::table('employee_assignments')->whereIn('employment_record_id', $employments)->pluck('id')->all();

        return $this->references->first('employment_records', $schoolId, $employments, ['employee_assignments', ...$cleared])
            ?? $this->references->first('employee_assignments', $schoolId, $assignments)
            ?? $this->references->first('employee_personal_details', $schoolId, DB::table('employee_personal_details')->where('employee_id', $employeeId)->pluck('id')->all())
            ?? $this->references->first('employee_documents', $schoolId, DB::table('employee_documents')->where('employee_id', $employeeId)->pluck('id')->all())
            ?? $this->references->first('documents', $schoolId, $this->documents->idsOwnedBy('employee', $employeeId));
    }
}
