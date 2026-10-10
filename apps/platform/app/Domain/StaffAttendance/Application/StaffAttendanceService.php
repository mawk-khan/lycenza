<?php

namespace App\Domain\StaffAttendance\Application;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\Leave\Application\LeaveCoverageReader;
use App\Domain\Leave\Application\LeaveLocks;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Events\StaffAttendanceCorrected;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceCorrection;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.3 (ADR 0065 §24.7, §24.8): every Staff Attendance write -- one record,
 * the bulk daily register, and the correction. All need
 * `hr.staff_attendance.manage`; there is no manager or self path.
 *
 * Evidence rules:
 * - a half is `present`, `absent` or null (no evidence); leave, holidays and
 *   weekly offs are never written here;
 * - no future date (School timezone); initial recording needs an employment
 *   in force today whose dates contain the date;
 * - a half recorded `present`/`absent` must be working time in today's staff
 *   calendar, and not covered by approved leave (Leave's read contract);
 * - an existing record changes only by correction: compare-and-swap on its
 *   version plus an append-only correction row, which the database requires.
 *
 * It never writes a Leave table, and Leave never writes attendance.
 *
 * Lock order (ADR 0065 §24.6, §26.9): School -> HR rows -> staff employment
 * (shared) -> staff days (ascending; several employments by id) -> calendar
 * (shared) -> the record row.
 */
class StaffAttendanceService
{
    use AuthorizesCapability;

    public const MAX_REGISTER_ITEMS = 1000;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly EmploymentCoverage $coverage,
        private readonly StaffCalendarService $calendar,
        private readonly LeaveCoverageReader $leave,
        private readonly HrSelfAdministrationGuard $selfAdministration,
    ) {}

    /** One EmploymentRecord on one date. An existing record is corrected, never recorded again. */
    public function record(School $school, string $employmentRecordId, string $date, ?string $firstHalf, ?string $secondHalf, User $actor): StaffAttendanceRecord
    {
        $this->authorizeCapabilityFor($actor, StaffAttendanceCapabilities::MANAGE, $school);
        $item = self::item($employmentRecordId, $firstHalf, $secondHalf, null);
        $this->requireRecordableDate($school, $date);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $date, $item, $actor) {
            $this->guard->requireOperational($school->id);
            $this->holdEmployment($school, $item, $date);
            $this->selfAdministration->refuseOwnEmployment($actor, $item['employmentRecordId'], 'staff_attendance.record');
            LeaveLocks::staffEmployment($school, $item['employmentRecordId'], shared: true);
            LeaveLocks::staffDays($school, $item['employmentRecordId'], [$date]);

            return $this->write($school, $date, [$item], 'single', $actor)[0];
        }));
    }

    /**
     * The bulk daily register: several EmploymentRecords on one date, all or
     * nothing. Every item is validated after the locks; one invalid item, a
     * duplicate item or an already-recorded employment refuses the whole
     * register. It is never an implicit correction.
     *
     * @param  list<array{employment_record_id: string, first_half: ?string, second_half: ?string}>  $items
     * @return list<StaffAttendanceRecord>
     */
    public function recordRegister(School $school, string $date, array $items, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, StaffAttendanceCapabilities::MANAGE, $school);
        if ($items === [] || count($items) > self::MAX_REGISTER_ITEMS) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_REGISTER_SIZE', 'A register holds between 1 and '.self::MAX_REGISTER_ITEMS.' employments.');
        }
        $validated = [];
        foreach ($items as $index => $item) {
            $entry = self::item((string) $item['employment_record_id'], $item['first_half'] ?? null, $item['second_half'] ?? null, $index + 1);
            if (isset($validated[$entry['employmentRecordId']])) {
                throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_DUPLICATE_ITEM', "Item {$entry['position']}: that employment is already in this register.");
            }
            $validated[$entry['employmentRecordId']] = $entry;
        }
        ksort($validated, SORT_STRING);
        $validated = array_values($validated);
        $this->requireRecordableDate($school, $date);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $date, $validated, $actor) {
            $this->guard->requireOperational($school->id);
            foreach ($validated as $item) {
                $this->holdEmployment($school, $item, $date);
                // SR.4 (ADR 0071 §26.2): nobody records their own attendance; the register refuses as a whole.
                $this->selfAdministration->refuseOwnEmployment($actor, $item['employmentRecordId'], 'staff_attendance.record');
            }
            foreach ($validated as $item) {
                LeaveLocks::staffEmployment($school, $item['employmentRecordId'], shared: true);
            }
            foreach ($validated as $item) {
                LeaveLocks::staffDays($school, $item['employmentRecordId'], [$date]);
            }

            $records = $this->write($school, $date, $validated, 'bulk', $actor);
            $this->audit->school($school, 'staff_attendance.bulk_recorded', actor: $actor, metadata: ['date' => $date, 'count' => count($records)]);

            return $records;
        }));
    }

    /**
     * Compare-and-swap correction. The append-only correction row is written
     * first (the database checks it starts from the current version and
     * values), then the record moves to `expected_version + 1` by a
     * conditional UPDATE the database accepts only with that evidence.
     */
    public function correct(School $school, string $recordId, int $expectedVersion, ?string $firstHalf, ?string $secondHalf, string $reasonCode, User $actor): StaffAttendanceRecord
    {
        $this->authorizeCapabilityFor($actor, StaffAttendanceCapabilities::MANAGE, $school);
        $after = [1 => self::half($firstHalf), 2 => self::half($secondHalf)];
        if (! in_array($reasonCode, StaffAttendanceCorrection::REASONS, true)) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_REASON_INVALID', 'Choose one of the listed correction reasons.');
        }
        if ($after === [1 => null, 2 => null] && $reasonCode !== 'entered_in_error') {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_CLEAR_REASON', 'Clearing both halves is only for a record entered in error.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $recordId, $expectedVersion, $after, $reasonCode, $actor) {
            $this->guard->requireOperational($school->id);
            // The employment and date never change, so they can be read before the locks.
            $identity = StaffAttendanceRecord::query()->where('school_id', $school->id)->findOrFail($recordId);
            $date = $identity->attendance_date->toDateString();
            $this->holdEmployment($school, ['employmentRecordId' => $identity->employment_record_id, 'position' => null], $date, currentOnly: false);
            $this->selfAdministration->refuseOwnEmployment($actor, $identity->employment_record_id, 'staff_attendance.correct');
            LeaveLocks::staffEmployment($school, $identity->employment_record_id, shared: true);
            LeaveLocks::staffDays($school, $identity->employment_record_id, [$date]);
            LeaveLocks::calendar($school, shared: true);

            $record = StaffAttendanceRecord::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($recordId);
            if ($record->version !== $expectedVersion) {
                throw self::stale();
            }
            $before = $record->halves();
            if ($before === $after) {
                throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_CORRECTION_UNCHANGED', 'The correction changes nothing.');
            }

            $gains = array_keys(array_filter($after, fn (?string $status, int $half) => $status !== null && $status !== $before[$half], ARRAY_FILTER_USE_BOTH));
            if ($gains !== []) {
                $leave = $this->leave->approvedCoverage($school, [$record->employment_record_id], $date, $date)[$record->employment_record_id][$date] ?? [];
                $calendar = null;
                foreach ($gains as $half) {
                    if (isset($leave[$half])) {
                        throw self::onLeave(null);
                    }
                    // Changing existing evidence is allowed whatever today's calendar says; NEW evidence needs working time.
                    if ($before[$half] === null) {
                        $calendar ??= $this->calendar->calculator($school, $date, $date)->halves($date);
                        if ($calendar[$half] !== 'working') {
                            throw self::notWorking(null);
                        }
                    }
                }
            }

            try {
                StaffAttendanceCorrection::query()->create([
                    'school_id' => $school->id, 'staff_attendance_record_id' => $record->id, 'employment_record_id' => $record->employment_record_id,
                    'from_version' => $expectedVersion, 'to_version' => $expectedVersion + 1,
                    'before_first_half_status' => $before[1], 'before_second_half_status' => $before[2],
                    'after_first_half_status' => $after[1], 'after_second_half_status' => $after[2],
                    'reason_code' => $reasonCode, 'corrected_by_user_id' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw self::stale();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'staff_attendance_correction_stale')) {
                    throw self::stale();
                }
                throw $e;
            }
            $updated = StaffAttendanceRecord::query()->where('school_id', $school->id)->whereKey($record->id)->where('version', $expectedVersion)
                ->update(['first_half_status' => $after[1], 'second_half_status' => $after[2], 'version' => $expectedVersion + 1]);
            if ($updated !== 1) {
                throw self::stale();
            }

            $this->audit->school($school, 'staff_attendance.corrected', actor: $actor, subject: $record, metadata: [
                'employmentRecordId' => $record->employment_record_id, 'date' => $date,
                'fromVersion' => $expectedVersion, 'toVersion' => $expectedVersion + 1,
                'before' => ['firstHalf' => $before[1], 'secondHalf' => $before[2]],
                'after' => ['firstHalf' => $after[1], 'secondHalf' => $after[2]],
                'reasonCode' => $reasonCode,
            ]);
            event(new StaffAttendanceCorrected(
                $school->id, $record->id, $record->employment_record_id, $record->employee_id, $date, $expectedVersion, $expectedVersion + 1,
                ['firstHalf' => $before[1], 'secondHalf' => $before[2]], ['firstHalf' => $after[1], 'secondHalf' => $after[2]], $reasonCode,
            ));

            return $record->refresh();
        }));
    }

    /**
     * Validates and inserts new records under the caller's staff-day locks.
     *
     * @param  list<array{employmentRecordId: string, halves: array{1: ?string, 2: ?string}, position: ?int}>  $items
     * @return list<StaffAttendanceRecord>
     */
    private function write(School $school, string $date, array $items, string $source, User $actor): array
    {
        LeaveLocks::calendar($school, shared: true);
        $calendar = $this->calendar->calculator($school, $date, $date)->halves($date);
        $ids = array_column($items, 'employmentRecordId');
        $leave = $this->leave->approvedCoverage($school, $ids, $date, $date);
        $existing = StaffAttendanceRecord::query()->where('school_id', $school->id)->where('attendance_date', $date)
            ->whereIn('employment_record_id', $ids)->pluck('employment_record_id')->all();

        foreach ($items as $item) {
            if (in_array($item['employmentRecordId'], $existing, true)) {
                throw self::alreadyRecorded($item['position']);
            }
            foreach ($item['halves'] as $half => $status) {
                if ($status === null) {
                    continue;
                }
                if (isset($leave[$item['employmentRecordId']][$date][$half])) {
                    throw self::onLeave($item['position']);
                }
                if ($calendar[$half] !== 'working') {
                    throw self::notWorking($item['position']);
                }
            }
        }

        $records = [];
        foreach ($items as $item) {
            try {
                $record = StaffAttendanceRecord::query()->create([
                    'school_id' => $school->id, 'employment_record_id' => $item['employmentRecordId'], 'attendance_date' => $date,
                    'first_half_status' => $item['halves'][1], 'second_half_status' => $item['halves'][2], 'recorded_by_user_id' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw self::alreadyRecorded($item['position']);
            }
            $record->refresh();
            $this->audit->school($school, 'staff_attendance.recorded', actor: $actor, subject: $record, metadata: [
                'employmentRecordId' => $record->employment_record_id, 'date' => $date,
                'firstHalf' => $record->first_half_status, 'secondHalf' => $record->second_half_status, 'source' => $source,
            ]);
            $records[] = $record;
        }

        return $records;
    }

    /** @param  array{employmentRecordId: string, position: ?int}  $item */
    private function holdEmployment(School $school, array $item, string $date, bool $currentOnly = true): void
    {
        match ($this->coverage->holdRecordAttendable($school, $item['employmentRecordId'], $date, $currentOnly)) {
            EmploymentCoverage::COVERED => null,
            EmploymentCoverage::RECORD_NOT_FOUND => $item['position'] === null
                ? throw new ModelNotFoundException('No query results for the employment.')
                : throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_EMPLOYMENT_UNKNOWN', "Item {$item['position']}: choose an employment of this School."),
            default => throw StaffAttendanceException::conflict('STAFF_ATTENDANCE_EMPLOYMENT_NOT_ELIGIBLE', self::prefix($item['position']).'The employment is not in force on that date.'),
        };
    }

    private function requireRecordableDate(School $school, string $date): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_DATE_INVALID', 'Give the date as YYYY-MM-DD.');
        }
        if ($date > CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString()) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_DATE_IN_FUTURE', 'Attendance records what happened; a future date cannot be recorded.');
        }
    }

    /** @return array{employmentRecordId: string, halves: array{1: ?string, 2: ?string}, position: ?int} */
    private static function item(string $employmentRecordId, ?string $firstHalf, ?string $secondHalf, ?int $position): array
    {
        $halves = [1 => self::half($firstHalf), 2 => self::half($secondHalf)];
        if ($halves === [1 => null, 2 => null]) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_EMPTY', self::prefix($position).'Record at least one half as present or absent.');
        }

        return ['employmentRecordId' => $employmentRecordId, 'halves' => $halves, 'position' => $position];
    }

    private static function half(?string $status): ?string
    {
        if ($status !== null && ! in_array($status, StaffAttendanceRecord::HALF_STATUSES, true)) {
            throw StaffAttendanceException::invalid('STAFF_ATTENDANCE_STATUS_INVALID', 'A half is present, absent or left unrecorded.');
        }

        return $status;
    }

    private static function prefix(?int $position): string
    {
        return $position === null ? '' : "Item {$position}: ";
    }

    private static function alreadyRecorded(?int $position): StaffAttendanceException
    {
        return StaffAttendanceException::conflict('STAFF_ATTENDANCE_ALREADY_RECORDED', self::prefix($position).'Attendance is already recorded for that employment and date; correct it instead.');
    }

    private static function onLeave(?int $position): StaffAttendanceException
    {
        return StaffAttendanceException::conflict('STAFF_ATTENDANCE_ON_LEAVE', self::prefix($position).'Approved leave covers that half; cancel the leave first.');
    }

    private static function notWorking(?int $position): StaffAttendanceException
    {
        return StaffAttendanceException::conflict('STAFF_ATTENDANCE_NOT_WORKING_TIME', self::prefix($position).'That half is a holiday or weekly off in the staff calendar.');
    }

    private static function stale(): StaffAttendanceException
    {
        return StaffAttendanceException::conflict('STAFF_ATTENDANCE_VERSION_STALE', 'The record changed since you read it; reload and correct again.');
    }
}
