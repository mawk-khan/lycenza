<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeaveSetting;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.1 (ADR 0065 §4.3): the School's leave-year start month and its
 * materialized leave years.
 *
 * - The start month (1..12, default April) is a product default, not a
 *   statutory claim, and never reads Finance: a Finance change can never
 *   move a leave year.
 * - A leave year is materialized once, explicitly (`open()`), and keeps its
 *   own frozen bounds and start month. The start month can change only while
 *   the School has no leave year (database trigger), so no historical year
 *   is ever reinterpreted.
 */
class LeaveYearService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
    ) {}

    public function startMonth(School $school): int
    {
        return $this->context->withSchool($school, fn () => (int) (LeaveSetting::query()->where('school_id', $school->id)->value('leave_year_start_month')
            ?? LeaveSetting::DEFAULT_START_MONTH));
    }

    public function setStartMonth(School $school, int $month, User $actor): LeaveSetting
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        if ($month < 1 || $month > 12) {
            throw LeaveException::invalid('LEAVE_YEAR_MONTH_INVALID', 'The leave-year start month is 1..12.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $month, $actor) {
            $this->guard->requireOperational($school->id);
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
                    throw LeaveException::conflict('LEAVE_YEAR_LOCKED', 'The leave-year start month is fixed once a leave year exists.');
                }
                throw $e;
            }
            $this->audit->school($school, 'leave.settings.changed', actor: $actor, subject: $setting, metadata: ['before' => $before, 'after' => $month]);

            return $setting;
        }));
    }

    /**
     * Materializes (or returns) the leave year containing `$date`, cut from
     * the current start month. Idempotent: an existing year is returned.
     */
    public function open(School $school, string $date, User $actor): LeaveYear
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $date, $actor) {
            $this->guard->requireOperational($school->id);
            // The settings row FOR SHARE: a concurrent start-month change waits, or committed first.
            $month = (int) (LeaveSetting::query()->where('school_id', $school->id)->sharedLock()->value('leave_year_start_month') ?? LeaveSetting::DEFAULT_START_MONTH);

            if (($existing = $this->containing($school, $date)) !== null) {
                return $existing;
            }
            $bounds = LeaveYearBounds::containing($month, $date);
            try {
                $year = LeaveYear::query()->create(['school_id' => $school->id, 'label' => $bounds->label, 'starts_on' => $bounds->startsOn, 'ends_on' => $bounds->endsOn, 'start_month' => $month]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'may not overlap') || str_contains($e->getMessage(), 'leave_years_school_id')) {
                    throw LeaveException::conflict('LEAVE_YEAR_OVERLAP', 'That date falls inside or across an existing leave year.');
                }
                throw $e;
            }
            $this->audit->school($school, 'leave.year.opened', actor: $actor, subject: $year, metadata: ['startsOn' => $bounds->startsOn, 'endsOn' => $bounds->endsOn]);

            return $year;
        }));
    }

    /** The materialized leave year containing `$date`, if any. */
    public function containing(School $school, string $date): ?LeaveYear
    {
        return $this->context->withSchool($school, fn () => LeaveYear::query()->where('school_id', $school->id)
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first());
    }
}
