<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeaveSetting;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Domain\Leave\Infrastructure\LeaveYearStartChange;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.1 (ADR 0065 §4.3, §22.1): the School's leave-year schedule and its
 * materialized leave years.
 *
 * - The start month (1..12, default April) is a product default, not a
 *   statutory claim, and never reads Finance: a Finance change can never
 *   move a leave year.
 * - A leave year is materialized once, explicitly (`open()`), and keeps its
 *   own frozen bounds and start month. Materialized years are never updated
 *   or deleted (database-enforced).
 * - The start month is PROSPECTIVELY configurable:
 *   - while the School has no leave year, the base month changes directly
 *     (`setStartMonth()`);
 *   - afterwards only through `scheduleStartChange()`. That takes effect on
 *     the first day of the new month, strictly in the School-local future
 *     and strictly after every materialized year and every earlier change.
 *     The year bridging the old schedule to it is an explicit, shorter
 *     transition year.
 *
 *   No historical year, boundary or piece of evidence is reinterpreted.
 * - Every schedule write and every `open()` serializes on the School's
 *   `leave.years:{school}` advisory lock, the same lock the database
 *   triggers take.
 */
class LeaveYearService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
    ) {}

    /** The start month that applies to leave years not yet bounded by a change: the latest configured one. */
    public function startMonth(School $school): int
    {
        return $this->context->withSchool($school, function () use ($school): int {
            $changes = $this->changes($school);

            return $changes === [] ? $this->baseMonth($school) : end($changes)['start_month'];
        });
    }

    public function setStartMonth(School $school, int $month, User $actor): LeaveSetting
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        $this->requireMonth($month);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $month, $actor) {
            $this->guard->requireOperational($school->id);
            $this->lockSchedule($school);
            $setting = LeaveSetting::query()->where('school_id', $school->id)->lockForUpdate()->first()
                ?? new LeaveSetting(['school_id' => $school->id]);
            $before = (int) ($setting->leave_year_start_month ?? LeaveSetting::DEFAULT_START_MONTH);
            if ($setting->exists && $before === $month) {
                return $setting;
            }

            try {
                $setting->forceFill(['leave_year_start_month' => $month, 'updated_by_user_id' => $actor->id])->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'leave_year_locked')) {
                    throw LeaveException::conflict('LEAVE_YEAR_LOCKED', 'Once a leave year exists, the start month changes only prospectively: schedule a change with an effective date.');
                }
                throw $e;
            }
            $this->audit->school($school, 'leave.settings.changed', actor: $actor, subject: $setting, metadata: ['before' => $before, 'after' => $month]);

            return $setting;
        }));
    }

    /**
     * A prospective start-month change, effective on `$effectiveFrom`. That
     * must be the first day of the new month, in the School-local future,
     * and strictly after every materialized year and every earlier change.
     */
    public function scheduleStartChange(School $school, int $month, string $effectiveFrom, User $actor): LeaveYearStartChange
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        $this->requireMonth($month);
        if (preg_match('/^\d{4}-(\d{2})-01$/', $effectiveFrom, $m) !== 1 || (int) $m[1] !== $month) {
            throw LeaveException::invalid('LEAVE_YEAR_EFFECTIVE_FROM_INVALID', 'A start-month change takes effect on the first day of the new start month.');
        }
        if ($effectiveFrom <= CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString()) {
            throw LeaveException::invalid('LEAVE_YEAR_EFFECTIVE_FROM_INVALID', 'A start-month change takes effect in the future.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $month, $effectiveFrom, $actor) {
            $this->guard->requireOperational($school->id);
            $this->lockSchedule($school);

            $changes = $this->changes($school);
            $before = $changes === [] ? $this->baseMonth($school) : end($changes)['start_month'];
            if ($before === $month) {
                throw LeaveException::invalid('LEAVE_YEAR_START_UNCHANGED', 'That start month is already configured.');
            }
            $lastYearEnd = LeaveYear::query()->where('school_id', $school->id)->max('ends_on');
            $lastChange = $changes === [] ? null : end($changes)['effective_from'];
            if (($lastYearEnd !== null && $effectiveFrom <= (string) $lastYearEnd) || ($lastChange !== null && $effectiveFrom <= $lastChange)) {
                throw LeaveException::conflict('LEAVE_YEAR_BOUNDARY_INVALID', 'A start-month change takes effect after every opened leave year and every earlier change.');
            }

            try {
                $change = LeaveYearStartChange::query()->create([
                    'school_id' => $school->id, 'previous_start_month' => $before, 'start_month' => $month,
                    'effective_from' => $effectiveFrom, 'created_by_user_id' => $actor->id,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'leave_year_boundary_invalid') || str_contains($e->getMessage(), 'leave_year_start_unchanged')) {
                    throw LeaveException::conflict('LEAVE_YEAR_BOUNDARY_INVALID', 'A start-month change takes effect after every opened leave year and every earlier change.');
                }
                throw $e;
            }
            $this->audit->school($school, 'leave.year_start.change_scheduled', actor: $actor, subject: $change, metadata: [
                'before' => $before, 'after' => $month, 'effectiveFrom' => $effectiveFrom,
            ]);

            return $change;
        }));
    }

    /**
     * Materializes (or returns) the leave year containing `$date`, cut from
     * the schedule in force for that date. Idempotent: an existing year is
     * returned.
     */
    public function open(School $school, string $date, User $actor): LeaveYear
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $date, $actor) {
            $this->guard->requireOperational($school->id);
            // The schedule lock first: a concurrent change or base edit waits, or committed first.
            $this->lockSchedule($school);

            if (($existing = $this->containing($school, $date)) !== null) {
                return $existing;
            }
            $bounds = LeaveYearBounds::inSchedule($this->baseMonth($school), $this->changes($school), $date);
            try {
                $year = LeaveYear::query()->create([
                    'school_id' => $school->id, 'label' => $bounds->label, 'starts_on' => $bounds->startsOn, 'ends_on' => $bounds->endsOn,
                    'start_month' => $bounds->startMonth, 'is_transition' => $bounds->isTransition,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'may not overlap') || str_contains($e->getMessage(), 'leave_years_school_id')) {
                    throw LeaveException::conflict('LEAVE_YEAR_OVERLAP', 'That date falls inside or across an existing leave year.');
                }
                throw $e;
            }
            $this->audit->school($school, 'leave.year.opened', actor: $actor, subject: $year, metadata: [
                'startsOn' => $bounds->startsOn, 'endsOn' => $bounds->endsOn, 'startMonth' => $bounds->startMonth, 'isTransition' => $bounds->isTransition,
            ]);

            return $year;
        }));
    }

    /** The materialized leave year containing `$date`, if any. */
    public function containing(School $school, string $date): ?LeaveYear
    {
        return $this->context->withSchool($school, fn () => LeaveYear::query()->where('school_id', $school->id)
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first());
    }

    /** @return list<array{id: string, previous_start_month: int, start_month: int, effective_from: string, created_at: string}> ascending */
    public function changes(School $school): array
    {
        return $this->context->withSchool($school, fn () => LeaveYearStartChange::query()->where('school_id', $school->id)->orderBy('effective_from')->get()
            ->map(fn (LeaveYearStartChange $c) => [
                'id' => $c->id, 'previous_start_month' => $c->previous_start_month, 'start_month' => $c->start_month,
                'effective_from' => $c->effective_from->toDateString(), 'created_at' => $c->created_at->toIso8601String(),
            ])->values()->all());
    }

    public function baseMonth(School $school): int
    {
        return $this->context->withSchool($school, fn () => (int) (LeaveSetting::query()->where('school_id', $school->id)->value('leave_year_start_month')
            ?? LeaveSetting::DEFAULT_START_MONTH));
    }

    private function requireMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw LeaveException::invalid('LEAVE_YEAR_MONTH_INVALID', 'The leave-year start month is 1..12.');
        }
    }

    private function lockSchedule(School $school): void
    {
        LeaveLocks::schedule($school);
    }
}
