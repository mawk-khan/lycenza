<?php

namespace App\Domain\Timetable\Application;

use App\Domain\Timetable\Application\Exceptions\InvalidTimetablePeriodException;
use App\Domain\Timetable\Application\Exceptions\TimetablePeriodOverlapException;
use App\Domain\Timetable\Application\Exceptions\TimetablePeriodReferencedException;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Concurrency\TenantLock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H (Timetable foundation): the ONE sanctioned write path for
 * `timetable_periods` -- never write TimetablePeriod directly from
 * anywhere else. No controller exists yet (a later phase builds the
 * HTTP layer as a thin wrapper around this class) -- every public
 * method therefore requires a real `User $actor` and authorizes
 * `timetable.periods.manage` at $school itself, mirroring
 * App\Domain\HR\Application\DepartmentService's exact established
 * precedent for "authoritative production entry point, no controller
 * yet" services.
 *
 * Overlap invariant: no two ACTIVE Periods for the same School may
 * have overlapping [start_time, end_time) ranges. PostgreSQL's plain
 * btree unique index cannot express a range-overlap rule (that needs
 * an exclusion constraint this checkpoint does not introduce), so the
 * invariant is enforced here instead: every method capable of
 * establishing or changing an active time interval
 * (`create()`, `update()` when start/end changes, `activate()`)
 * acquires `App\Support\Concurrency\TenantLock::forSchool($school,
 * 'timetable.periods')`, and -- INSIDE that lock's callback, inside one
 * `DB::transaction()` -- re-checks every other currently-active
 * Period's range before writing. The lock is released only after the
 * transaction commits (never before), proven under real two-process
 * concurrency in `Tests\Feature\Timetable\TimetablePeriodConcurrencyTest`.
 *
 * Referenced-entry guard: deactivating a Period, or changing its
 * start_time/end_time, is rejected while an ACTIVE `TimetableEntry`
 * still references it -- see
 * App\Domain\Timetable\Application\Exceptions\TimetablePeriodReferencedException's
 * own docblock for why this is a deliberate, narrow exception to this
 * codebase's usual reference-entity deactivation convention.
 * Non-temporal field changes (name/code/sort_order) are never subject
 * to either the lock or this guard. `deactivate()` DOES acquire the
 * same `timetable.periods` lock for its referenced-entry check (even
 * though it establishes no new active interval itself) -- see the
 * cross-service race this closes, documented on
 * App\Domain\Timetable\Application\TimetableScheduleService, which
 * acquires the IDENTICAL lock key for its own Period-eligibility
 * re-check in `create()`/`update()`/`activate()`. Without both sides
 * serialized on the same lock, `deactivate()`'s "no active entry
 * references this Period" check and TimetableScheduleService's "this
 * Period is active" check could each pass against
 * stale/not-yet-committed state, leaving an active TimetableEntry
 * referencing an inactive Period -- proven closed under real
 * two-process concurrency in
 * `Tests\Feature\Timetable\TimetableEntryVersusPeriodDeactivationConcurrencyTest`.
 */
class TimetablePeriodService
{
    use AuthorizesCapability;

    private const LOCK_OPERATION = 'timetable.periods';

    private const LOCK_WAIT_SECONDS = 10;

    private const LOCK_TTL_SECONDS = 15;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly TenantLock $locks,
    ) {}

    /**
     * @param  array{code: string, name: string, start_time: string, end_time: string, sort_order?: int|null}  $attributes
     */
    public function create(School $school, array $attributes, User $actor): TimetablePeriod
    {
        $this->authorizeCapabilityFor($actor, 'timetable.periods.manage', $school);

        $startTime = $attributes['start_time'];
        $endTime = $attributes['end_time'];
        $this->assertValidRange($startTime, $endTime);

        return $this->withPeriodsLock($school, function () use ($school, $attributes, $startTime, $endTime, $actor) {
            return DB::transaction(function () use ($school, $attributes, $startTime, $endTime, $actor) {
                $this->assertNoOverlap($school, $startTime, $endTime, null);

                $period = TimetablePeriod::query()->create([
                    'school_id' => $school->id,
                    'code' => $attributes['code'],
                    'name' => $attributes['name'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'sort_order' => $attributes['sort_order'] ?? null,
                    'status' => 'active',
                ]);

                $this->audit->school($school, 'timetable.period.created', actor: $actor, subject: $period, metadata: [
                    'code' => $period->code,
                    'startTime' => $period->start_time,
                    'endTime' => $period->end_time,
                ]);

                return $period;
            });
        });
    }

    /**
     * A single method covering BOTH kinds of update, dispatching on
     * whether $attributes touches start_time/end_time -- never a
     * separate "rename" vs. "reschedule" pair a caller could get wrong.
     * `status` is never accepted here -- use `activate()`/`deactivate()`.
     *
     * @param  array<string, mixed>  $attributes  may include name/code/sort_order/start_time/end_time; school_id/status are always stripped
     */
    public function update(TimetablePeriod $period, array $attributes, User $actor): TimetablePeriod
    {
        $school = $period->school;
        $this->authorizeCapabilityFor($actor, 'timetable.periods.manage', $school);

        unset($attributes['school_id'], $attributes['status']);

        $changingTime = array_key_exists('start_time', $attributes) || array_key_exists('end_time', $attributes);

        if (! $changingTime) {
            return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $period, $attributes, $actor) {
                $period->update($this->onlyNonTemporalFields($attributes));

                $this->audit->school($school, 'timetable.period.updated', actor: $actor, subject: $period, metadata: [
                    'fields' => array_keys($this->onlyNonTemporalFields($attributes)),
                ]);

                return $period->fresh();
            }));
        }

        $newStart = $attributes['start_time'] ?? $period->start_time;
        $newEnd = $attributes['end_time'] ?? $period->end_time;
        $this->assertValidRange($newStart, $newEnd);

        return $this->withPeriodsLock($school, function () use ($school, $period, $attributes, $newStart, $newEnd, $actor) {
            return DB::transaction(function () use ($school, $period, $attributes, $newStart, $newEnd, $actor) {
                $this->assertNotReferencedByActiveEntry($period, 'change the start/end time of');
                $this->assertNoOverlap($school, $newStart, $newEnd, $period->id);

                $fields = array_merge(
                    $this->onlyNonTemporalFields($attributes),
                    ['start_time' => $newStart, 'end_time' => $newEnd],
                );
                $period->update($fields);

                $this->audit->school($school, 'timetable.period.updated', actor: $actor, subject: $period, metadata: [
                    'fields' => array_keys($fields),
                ]);

                return $period->fresh();
            });
        });
    }

    /**
     * Rejects while any ACTIVE TimetableEntry still references this
     * Period (see this class's own docblock and
     * TimetablePeriodReferencedException's docblock for why). Never a
     * bare status update -- always inside a transaction, always
     * audited. Acquires the SAME `timetable.periods` lock `create()`/
     * `update()`/`activate()` use -- required so this method's
     * referenced-entry check and
     * App\Domain\Timetable\Application\TimetableScheduleService's own
     * Period-eligibility check genuinely serialize against each other
     * rather than each reading stale/not-yet-committed state (see this
     * class's own docblock).
     */
    public function deactivate(TimetablePeriod $period, User $actor): TimetablePeriod
    {
        $school = $period->school;
        $this->authorizeCapabilityFor($actor, 'timetable.periods.manage', $school);

        return $this->withPeriodsLock($school, function () use ($school, $period, $actor) {
            return DB::transaction(function () use ($school, $period, $actor) {
                $this->assertNotReferencedByActiveEntry($period, 'deactivate');

                $period->update(['status' => 'inactive']);

                $this->audit->school($school, 'timetable.period.deactivated', actor: $actor, subject: $period);

                return $period->fresh();
            });
        });
    }

    /**
     * Reactivation re-runs the FULL create-time validation (overlap
     * against every OTHER currently-active Period, under the same
     * lock) before flipping status back to active -- never a bare
     * status update. start_time < end_time is already guaranteed by
     * the database's own CHECK constraint (this row's range cannot
     * have become invalid while inactive), so it is not re-validated
     * here; code uniqueness is likewise always enforced by the
     * database's case-insensitive expression index regardless of
     * status, so there is nothing extra to re-check there either.
     */
    public function activate(TimetablePeriod $period, User $actor): TimetablePeriod
    {
        $school = $period->school;
        $this->authorizeCapabilityFor($actor, 'timetable.periods.manage', $school);

        return $this->withPeriodsLock($school, function () use ($school, $period, $actor) {
            return DB::transaction(function () use ($school, $period, $actor) {
                $this->assertNoOverlap($school, $period->start_time, $period->end_time, $period->id);

                $period->update(['status' => 'active']);

                $this->audit->school($school, 'timetable.period.activated', actor: $actor, subject: $period);

                return $period->fresh();
            });
        });
    }

    /**
     * Acquires the School's `timetable.periods` operation lock (waiting
     * up to LOCK_WAIT_SECONDS), then runs $callback while holding it --
     * the lock is released only once $callback (which must itself open
     * and commit its own DB::transaction()) returns. Tenant context
     * (the RLS session GUC) is set for the ENTIRE duration, including
     * the lock-acquisition wait, since the overlap check and the
     * referenced-entry check both query RLS-protected tables. THE SAME
     * lock key (school + 'timetable.periods') is also acquired by
     * App\Domain\Timetable\Application\TimetableScheduleService for its
     * own Period-eligibility re-check -- see this class's own docblock
     * for why that cross-service coordination is required.
     */
    private function withPeriodsLock(School $school, callable $callback): mixed
    {
        return $this->context->withSchool($school, fn () => $this->locks
            ->forSchool($school, self::LOCK_OPERATION, self::LOCK_TTL_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback));
    }

    /**
     * Half-open interval overlap: two ranges [a1, a2) and [b1, b2)
     * overlap iff a1 < b2 AND b1 < a2. MUST be called from inside the
     * operation lock's callback, after it is held, and re-checked
     * AFTER the lock is acquired -- never before (a stale pre-lock read
     * would defeat the whole point of the lock).
     */
    private function assertNoOverlap(School $school, string $startTime, string $endTime, ?string $excludePeriodId): void
    {
        $overlaps = TimetablePeriod::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($excludePeriodId !== null, fn ($query) => $query->where('id', '!=', $excludePeriodId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        if ($overlaps) {
            throw new TimetablePeriodOverlapException;
        }
    }

    private function assertNotReferencedByActiveEntry(TimetablePeriod $period, string $action): void
    {
        $referenced = TimetableEntry::query()
            ->where('period_id', $period->id)
            ->where('status', 'active')
            ->exists();

        if ($referenced) {
            throw new TimetablePeriodReferencedException($action);
        }
    }

    private function assertValidRange(string $startTime, string $endTime): void
    {
        if (! Carbon::createFromTimeString($startTime)->lt(Carbon::createFromTimeString($endTime))) {
            throw new InvalidTimetablePeriodException;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function onlyNonTemporalFields(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(['name', 'code', 'sort_order']));
    }
}
