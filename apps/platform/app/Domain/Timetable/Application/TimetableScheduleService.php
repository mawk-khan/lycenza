<?php

namespace App\Domain\Timetable\Application;

use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\Exceptions\CampusNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\RequiredSubjectOfferingOnlyException;
use App\Domain\Timetable\Application\Exceptions\RoomAlreadyScheduledException;
use App\Domain\Timetable\Application\Exceptions\RoomCampusMismatchException;
use App\Domain\Timetable\Application\Exceptions\RoomNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\SectionAlreadyScheduledException;
use App\Domain\Timetable\Application\Exceptions\SectionNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\SubjectOfferingNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\TeacherAlreadyScheduledException;
use App\Domain\Timetable\Application\Exceptions\TeacherNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\TimetablePeriodNotAvailableException;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Concurrency\TenantLock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H (Timetable foundation): the ONE sanctioned write path for
 * `timetable_entries` -- never write TimetableEntry directly from
 * anywhere else. No controller exists yet, so every public method
 * requires a real `User $actor` and authorizes
 * `timetable.schedule.manage` at $school itself, mirroring
 * App\Domain\HR\Application\DepartmentService's established precedent
 * (see App\Domain\Timetable\Application\TimetablePeriodService's own
 * docblock for the same reasoning).
 *
 * SubjectOffering/Section/Employee(teacher)/Room/TimetablePeriod are
 * always accepted as real, already-resolved model instances -- never a
 * raw caller-supplied id (the "never trust a caller-supplied id" rule
 * App\Domain\HR\Application\DepartmentService already established for
 * Campus/parent-Department, applied here). `academic_year_id`/
 * `campus_id`/`grade_level_id` are always DERIVED from the resolved
 * SubjectOffering, never accepted as separate caller input -- the
 * database's composite FKs additionally structurally guarantee the
 * given Section shares that exact same context (CLAUDE.md rule 70), so
 * a mismatched pairing is rejected at the database level even if this
 * derivation were somehow bypassed.
 *
 * Double-booking (teacher / Section / Room, all scoped to
 * School + day-of-week + Period, while active) is enforced by three
 * partial unique indexes on `timetable_entries`
 * (`create_timetable_entries_table` migration) -- the REAL concurrency
 * guarantee, proven under real two-process concurrency for the teacher
 * case in `Tests\Feature\Timetable\TimetableEntryConcurrencyTest`.
 * `guarded()` below translates a caught `QueryException` into the
 * matching typed domain exception by constraint name, mirroring
 * App\Domain\Fees\Application\ChargeService::violatesConstraint()'s
 * established pattern -- a caller never sees a raw database message.
 *
 * Cross-service Period-lifecycle race (closed): `create()`/`update()`
 * (when it touches the Period reference)/`activate()` all acquire
 * `App\Support\Concurrency\TenantLock::forSchool($school, 'timetable.periods')`
 * -- the IDENTICAL lock key
 * App\Domain\Timetable\Application\TimetablePeriodService uses for its
 * own overlap check AND for `deactivate()`/the start_time/end_time
 * change guard. Without this, two independently-locked services could
 * each pass their own Period-eligibility check against
 * stale/not-yet-committed state: process A creates an entry against
 * Period P (P read as active, before A's insert commits) while process
 * B concurrently deactivates P (its "no active entry references P"
 * check runs before A's insert is visible) -- neither side ever sees
 * the other's effect, leaving an active TimetableEntry referencing an
 * inactive Period. Serializing BOTH sides on the same lock key makes
 * whichever side acquires it first fully win: the loser's re-check
 * (Period active? / any active entry references P?) now runs strictly
 * after the winner's commit, so it always sees accurate state. Proven
 * under real two-process concurrency in
 * `Tests\Feature\Timetable\TimetableEntryVersusPeriodDeactivationConcurrencyTest`.
 * Only the Period-eligibility check itself needs to run inside this
 * lock/transaction -- the SubjectOffering/Section/teacher/Room checks
 * (`assertParentsSchedulable()`) have no equivalent
 * deactivate-blocked-by-active-reference guard on the other side, so
 * they are validated up front as before.
 *
 * ORG.7D-1 (ADR 0076 §37): `timetable.schedule.manage` is Campus-adopted.
 * Every write resolves the actor's CampusScope here, and the Campus it
 * touches -- the entry's current Campus and, on create/update, the
 * Offering's -- must be within it, BEFORE any eligibility check, so nothing
 * is revealed about another Campus's records. Out of scope is NOT FOUND,
 * exactly like another School's record. A Campus-scoped actor's slot
 * collision is one generic refusal (TimetableScheduleAccess::
 * concealSlotConflict()). Whole-School behaviour is unchanged.
 */
class TimetableScheduleService
{
    use AuthorizesCampusScope;

    /**
     * MUST exactly match
     * App\Domain\Timetable\Application\TimetablePeriodService::LOCK_OPERATION
     * -- App\Support\Concurrency\TenantLock keys a lock by
     * (school, operation name); both services need to contend for the
     * SAME lock for the cross-service coordination documented on this
     * class to work at all.
     */
    private const PERIOD_LOCK_OPERATION = 'timetable.periods';

    private const LOCK_WAIT_SECONDS = 10;

    private const LOCK_TTL_SECONDS = 15;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly TenantLock $locks,
        private readonly ActiveCampus $activeCampus,
        private readonly TimetableScheduleAccess $access,
    ) {}

    /**
     * $dayOfWeek: 1 (Monday) .. 7 (Sunday) -- the database's own
     * `timetable_entries_day_of_week_check` CHECK constraint is the
     * structural backstop for an out-of-range value.
     */
    public function create(
        School $school,
        SubjectOffering $subjectOffering,
        Section $section,
        Employee $teacher,
        ?Room $room,
        TimetablePeriod $period,
        int $dayOfWeek,
        User $actor,
    ): TimetableEntry {
        $scope = $this->authorizeCampusScopeFor($actor, 'timetable.schedule.manage', $school);
        $this->assertInScope($scope, $subjectOffering->campus_id, SubjectOffering::class, $subjectOffering->id);

        $this->assertParentsSchedulable($subjectOffering, $section, $teacher, $room);
        $context = $this->deriveContext($subjectOffering);

        return $this->context->withSchool($school, fn () => $this->withPeriodsLock($school, fn () => $this->guarded($scope, fn () => DB::transaction(function () use ($school, $subjectOffering, $section, $teacher, $room, $period, $dayOfWeek, $context, $actor) {
            $this->assertPeriodSchedulable($period);
            $this->assertCampusSchedulable($school, $context['campus_id']);

            $entry = TimetableEntry::query()->create([
                'school_id' => $school->id,
                'academic_year_id' => $context['academic_year_id'],
                'campus_id' => $context['campus_id'],
                'grade_level_id' => $context['grade_level_id'],
                'subject_offering_id' => $subjectOffering->id,
                'section_id' => $section->id,
                'teacher_id' => $teacher->id,
                'room_id' => $room?->id,
                'period_id' => $period->id,
                'day_of_week' => $dayOfWeek,
                'status' => 'active',
            ]);

            $this->audit->school($school, 'timetable.entry.created', actor: $actor, subject: $entry, metadata: $this->auditMetadata($entry));

            return $entry;
        }))));
    }

    /**
     * Re-runs the ENTIRE validation set (identical to `create()`)
     * atomically before persisting ANY of the new
     * SubjectOffering/Section/teacher/Room/Period/day-of-week -- one
     * full re-validate-and-rewrite, never a bag of independently
     * validated field setters. Does not touch `status` -- use
     * `activate()`/`deactivate()` for that transition. Always acquires
     * the `timetable.periods` lock for its Period re-check (see this
     * class's own docblock) -- even when the caller passes back the
     * SAME Period the entry already had, this method has no cheap way
     * to prove that without the lock, so it is never skipped.
     */
    public function update(
        TimetableEntry $entry,
        SubjectOffering $subjectOffering,
        Section $section,
        Employee $teacher,
        ?Room $room,
        TimetablePeriod $period,
        int $dayOfWeek,
        User $actor,
    ): TimetableEntry {
        $school = $entry->school;
        $scope = $this->authorizeCampusScopeFor($actor, 'timetable.schedule.manage', $school);
        // Both ends: the entry where it is now, and where the Offering puts it.
        $this->assertInScope($scope, $entry->campus_id, TimetableEntry::class, $entry->id);
        $this->assertInScope($scope, $subjectOffering->campus_id, SubjectOffering::class, $subjectOffering->id);

        $this->assertParentsSchedulable($subjectOffering, $section, $teacher, $room);
        $context = $this->deriveContext($subjectOffering);

        return $this->context->withSchool($school, fn () => $this->withPeriodsLock($school, fn () => $this->guarded($scope, fn () => DB::transaction(function () use ($school, $entry, $subjectOffering, $section, $teacher, $room, $period, $dayOfWeek, $context, $actor, $scope) {
            $this->holdInScope($scope, $entry);
            $this->assertPeriodSchedulable($period);
            $this->assertCampusSchedulable($school, $context['campus_id']);

            $entry->update([
                'academic_year_id' => $context['academic_year_id'],
                'campus_id' => $context['campus_id'],
                'grade_level_id' => $context['grade_level_id'],
                'subject_offering_id' => $subjectOffering->id,
                'section_id' => $section->id,
                'teacher_id' => $teacher->id,
                'room_id' => $room?->id,
                'period_id' => $period->id,
                'day_of_week' => $dayOfWeek,
            ]);

            $entry->refresh();

            $this->audit->school($school, 'timetable.entry.updated', actor: $actor, subject: $entry, metadata: $this->auditMetadata($entry));

            return $entry;
        }))));
    }

    /**
     * Sets status=inactive and keeps the row -- immediately frees its
     * slot, since every double-booking index is scoped
     * `WHERE status = 'active'`. No downstream effects; no
     * re-validation of parents is needed to deactivate, and no lock is
     * needed either -- deactivating never establishes a new dependency
     * on a Period's current state, it only removes one.
     */
    public function deactivate(TimetableEntry $entry, User $actor): TimetableEntry
    {
        $school = $entry->school;
        $scope = $this->authorizeCampusScopeFor($actor, 'timetable.schedule.manage', $school);
        $this->assertInScope($scope, $entry->campus_id, TimetableEntry::class, $entry->id);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $entry, $actor, $scope) {
            $this->holdInScope($scope, $entry);
            $entry->update(['status' => 'inactive']);

            $this->audit->school($school, 'timetable.entry.deactivated', actor: $actor, subject: $entry);

            return $entry->fresh();
        }));
    }

    /**
     * Re-runs the FULL validation set against the entry's CURRENT
     * parents (freshly loaded, never trusting a possibly-stale
     * in-memory relation) before flipping back to active -- never a
     * bare status update. This is what re-detects a conflict that
     * arose from ANOTHER entry while this one was inactive: the
     * `guarded()` translation applies here exactly as it does in
     * `create()`. Acquires the `timetable.periods` lock for its Period
     * re-check, exactly like `create()`/`update()` (see this class's
     * own docblock).
     */
    public function activate(TimetableEntry $entry, User $actor): TimetableEntry
    {
        $school = $entry->school;
        $scope = $this->authorizeCampusScopeFor($actor, 'timetable.schedule.manage', $school);
        $this->assertInScope($scope, $entry->campus_id, TimetableEntry::class, $entry->id);

        return $this->context->withSchool($school, function () use ($school, $entry, $actor, $scope) {
            $subjectOffering = $entry->subjectOffering()->firstOrFail();
            $section = $entry->section()->firstOrFail();
            $teacher = $entry->teacher()->firstOrFail();
            $room = $entry->room_id !== null ? $entry->room()->firstOrFail() : null;
            $period = $entry->period()->firstOrFail();

            $this->assertParentsSchedulable($subjectOffering, $section, $teacher, $room);

            return $this->withPeriodsLock($school, fn () => $this->guarded($scope, fn () => DB::transaction(function () use ($school, $entry, $period, $actor, $scope) {
                $this->holdInScope($scope, $entry);
                $this->assertPeriodSchedulable($period);
                $this->assertCampusSchedulable($school, $entry->campus_id);

                $entry->update(['status' => 'active']);

                $this->audit->school($school, 'timetable.entry.activated', actor: $actor, subject: $entry, metadata: $this->auditMetadata($entry));

                return $entry->fresh();
            })));
        });
    }

    /**
     * Acquires the School's `timetable.periods` operation lock (waiting
     * up to LOCK_WAIT_SECONDS) and runs $callback while holding it --
     * released only once $callback returns. MUST be called from inside
     * an already-established `TenantContext::withSchool()` (the Period
     * re-check queries `timetable_periods`, itself RLS-protected).
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withPeriodsLock(School $school, callable $callback): mixed
    {
        return $this->locks
            ->forSchool($school, self::PERIOD_LOCK_OPERATION, self::LOCK_TTL_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    /**
     * The SubjectOffering/Section/teacher/Room portion of eligibility --
     * deliberately NOT the Period check, which has its own
     * lock-protected re-check (`assertPeriodSchedulable()`) run
     * separately, INSIDE the `timetable.periods` lock and the
     * transaction, per this class's own docblock.
     */
    private function assertParentsSchedulable(SubjectOffering $subjectOffering, Section $section, Employee $teacher, ?Room $room): void
    {
        if (! $subjectOffering->is_required) {
            throw new RequiredSubjectOfferingOnlyException;
        }

        if (! $subjectOffering->isActive()) {
            throw new SubjectOfferingNotAvailableException;
        }

        if (! $section->isActive()) {
            throw new SectionNotAvailableException;
        }

        if (! $teacher->isActive()) {
            throw new TeacherNotAvailableException;
        }

        if ($room !== null && ! $room->isActive()) {
            throw new RoomNotAvailableException;
        }

        // ORG.7D-0 R1: the entry's Campus is its Offering's (deriveContext());
        // a Room from another Campus is never schedulable for it.
        if ($room !== null && $room->campus_id !== $subjectOffering->campus_id) {
            throw new RoomCampusMismatchException;
        }
    }

    /**
     * ORG.7D-1 (ADR 0076 §37): a Campus outside the actor's scope is NOT
     * FOUND -- the same answer as a record of another School.
     *
     * @param  class-string  $model
     */
    private function assertInScope(CampusScope $scope, string $campusId, string $model, string $id): void
    {
        if (! $scope->covers($campusId)) {
            throw (new ModelNotFoundException)->setModel($model, [$id]);
        }
    }

    /**
     * ORG.7D-1: inside the write transaction, the entry row is locked and
     * its CURRENT Campus re-checked, so an entry another scheduler moved to
     * another Campus after the caller read it is never written.
     */
    private function holdInScope(CampusScope $scope, TimetableEntry $entry): void
    {
        $current = TimetableEntry::query()->whereKey($entry->id)->lockForUpdate()->value('campus_id');
        $this->assertInScope($scope, is_string($current) ? $current : '', TimetableEntry::class, $entry->id);
    }

    /**
     * ORG.7D-0 R5 (ADR 0076 §36): nothing new is scheduled on an inactive
     * Campus. Runs inside the transaction, so the Campus row is share-locked
     * until commit (ActiveCampus). Data validity only -- never authority.
     */
    private function assertCampusSchedulable(School $school, string $campusId): void
    {
        if ($this->activeCampus->find($school, $campusId) === null) {
            throw new CampusNotAvailableException;
        }
    }

    /**
     * MUST be called only from inside `withPeriodsLock()`'s callback,
     * inside the enclosing `DB::transaction()` -- re-fetches the
     * Period's CURRENT row (never trusting the caller's possibly-stale
     * in-memory `$period`), so this is the check that actually closes
     * the cross-service race documented on this class. Once the lock is
     * held, whichever side (this service's create/update/activate, or
     * TimetablePeriodService's deactivate/time-change) got there first
     * has already committed and released the lock before this query
     * runs, so this always sees accurate, current state.
     */
    private function assertPeriodSchedulable(TimetablePeriod $period): void
    {
        $current = TimetablePeriod::query()->findOrFail($period->id);

        if (! $current->isActive()) {
            throw new TimetablePeriodNotAvailableException;
        }
    }

    /**
     * @return array{academic_year_id: string, campus_id: string, grade_level_id: string}
     */
    private function deriveContext(SubjectOffering $subjectOffering): array
    {
        return [
            'academic_year_id' => $subjectOffering->academic_year_id,
            'campus_id' => $subjectOffering->campus_id,
            'grade_level_id' => $subjectOffering->grade_level_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMetadata(TimetableEntry $entry): array
    {
        return [
            'entryId' => $entry->id,
            'subjectOfferingId' => $entry->subject_offering_id,
            'sectionId' => $entry->section_id,
            'teacherId' => $entry->teacher_id,
            'roomId' => $entry->room_id,
            'periodId' => $entry->period_id,
            'dayOfWeek' => $entry->day_of_week,
        ];
    }

    /**
     * Translates a caught unique-constraint (or, ORG.7D-0, Room-Campus FK)
     * violation into the matching typed domain exception by constraint
     * name -- never lets a raw PostgreSQL message reach a caller. Any OTHER QueryException (a
     * bug, or a constraint this class does not know how to translate)
     * is deliberately rethrown unchanged rather than swallowed.
     *
     * ORG.7D-1: a slot collision seen by a Campus-scoped actor is concealed
     * (TimetableScheduleAccess::concealSlotConflict()).
     *
     * @param  callable(): TimetableEntry  $callback
     */
    private function guarded(CampusScope $scope, callable $callback): TimetableEntry
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if ($this->violatesConstraint($e, 'timetable_entries_teacher_slot_unique')) {
                throw $this->access->concealSlotConflict($scope, new TeacherAlreadyScheduledException);
            }

            if ($this->violatesConstraint($e, 'timetable_entries_section_slot_unique')) {
                throw $this->access->concealSlotConflict($scope, new SectionAlreadyScheduledException);
            }

            if ($this->violatesConstraint($e, 'timetable_entries_room_slot_unique')) {
                throw $this->access->concealSlotConflict($scope, new RoomAlreadyScheduledException);
            }

            if ($this->violatesConstraint($e, 'timetable_entries_room_campus_fk')) {
                throw new RoomCampusMismatchException;
            }

            throw $e;
        }
    }

    private function violatesConstraint(QueryException $e, string $constraintName): bool
    {
        return str_contains($e->getMessage(), $constraintName);
    }
}
