<?php

namespace App\Domain\TeachingAssignments\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Application\Exceptions\AcademicYearNotOpenException;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentBeyondEmploymentException;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentOutsideAcademicYearException;
use App\Domain\TeachingAssignments\Application\Exceptions\EmployeeNotAssignableException;
use App\Domain\TeachingAssignments\Application\Exceptions\InvalidAssignmentDatesException;
use App\Domain\TeachingAssignments\Application\Exceptions\RequiredOfferingOnlyException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentAlreadyEndedException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentOverlapException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingContextInactiveException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingContextMismatchException;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * TCH.2 (ADR 0063 sections 7-10, 15, 20, 22) -- the ONE write path for
 * `teaching_assignments`: create() and end(). There is no update, no
 * repointing and no delete; correcting an owner, class or start date is a
 * new row.
 *
 * Authorization: `teaching.assignments.manage` on the School, checked
 * here as well as on the route. It is an administrative capability; the
 * acting administrator is never required to be an Employee (no
 * ActingEmployee -- ADR 0063 section 11, Tier 1).
 *
 * Structural context is server-derived: the caller chooses the Employee,
 * Section, SubjectOffering and dates; academic_year_id/campus_id/
 * grade_level_id come from the resolved Offering, and the composite
 * foreign keys refuse any pairing of a Section and Offering that do not
 * share it. An id of another School is the same tenant-safe 404 as an
 * unknown one.
 *
 * Concurrency, inside one transaction, in this order:
 *   1. School FOR SHARE (SchoolOperationalGuard, CLAUDE.md rule 86);
 *   2. the assignment key's transaction-scoped advisory lock
 *      `teaching.assignment:{school}:{employee}:{section}:{offering}` --
 *      every create and end of that key serializes here, so the overlap
 *      check below is authoritative;
 *   3. Section and SubjectOffering FOR SHARE (a deactivation or a
 *      required->elective change waits, or commits first and is seen);
 *   4. Employee, then its covering EmploymentRecord, FOR SHARE through
 *      HR's EmploymentCoverage (an archive or EmploymentService::end()
 *      waits, or commits first and is seen) -- the ADR 0063 order
 *      Employee -> EmploymentRecord -> TeachingAssignment;
 *   5. the overlap check, then the insert and its audit.
 */
class TeachingAssignmentService
{
    use AuthorizesCapability;

    public const string CAPABILITY_VIEW = 'teaching.assignments.view';

    public const string CAPABILITY_MANAGE = 'teaching.assignments.manage';

    /** S7: the employment-end path is authorized by the HR capability that ends the employment, never by this module's own. */
    public const string CAPABILITY_EMPLOYMENT_END = 'hr.employees.assignments.manage';

    private const string OPEN_YEAR_STATUSES = 'draft|active';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly SchoolOperationalGuard $guard,
        private readonly EmploymentCoverage $coverage,
    ) {}

    /**
     * @param  string  $startsOn  School-local Y-m-d, inclusive
     * @param  string|null  $endsOn  School-local Y-m-d, inclusive; null = open-ended
     */
    public function create(
        School $school,
        string $employeeId,
        string $sectionId,
        string $subjectOfferingId,
        string $startsOn,
        ?string $endsOn,
        User $actor,
    ): TeachingAssignment {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY_MANAGE, $school);

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new InvalidAssignmentDatesException;
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employeeId, $sectionId, $subjectOfferingId, $startsOn, $endsOn, $actor) {
            $this->guard->requireOperational($school->id);
            $this->lockKey($school->id, $employeeId, $sectionId, $subjectOfferingId);

            $section = Section::query()->where('school_id', $school->id)->sharedLock()->findOrFail($sectionId);
            $offering = SubjectOffering::query()->where('school_id', $school->id)->sharedLock()->findOrFail($subjectOfferingId);

            if (! $offering->is_required) {
                throw new RequiredOfferingOnlyException;
            }

            if ($section->academic_year_id !== $offering->academic_year_id
                || $section->campus_id !== $offering->campus_id
                || $section->grade_level_id !== $offering->grade_level_id) {
                throw new TeachingContextMismatchException;
            }

            if (! $section->isActive() || ! $offering->isActive()) {
                throw new TeachingContextInactiveException;
            }

            $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($offering->academic_year_id);

            if (! in_array($year->status, explode('|', self::OPEN_YEAR_STATUSES), true)) {
                throw new AcademicYearNotOpenException;
            }

            $yearStarts = $year->starts_on->toDateString();
            $yearEnds = $year->ends_on->toDateString();
            if ($startsOn < $yearStarts || $startsOn > $yearEnds || ($endsOn !== null && $endsOn > $yearEnds)) {
                throw new AssignmentOutsideAcademicYearException;
            }

            match ($this->coverage->hold($school, $employeeId, $startsOn)) {
                EmploymentCoverage::COVERED => null,
                EmploymentCoverage::EMPLOYEE_NOT_FOUND => throw (new ModelNotFoundException)->setModel(Employee::class, [$employeeId]),
                default => throw new EmployeeNotAssignableException,
            };

            // S7 (ADR 0063 §47): ownership never outlives the employment covering its start.
            $employmentEndsOn = $this->coverage->coveringEndsOn($school, $employeeId, $startsOn);
            if ($employmentEndsOn !== null && ($endsOn === null || $endsOn > $employmentEndsOn)) {
                throw new AssignmentBeyondEmploymentException;
            }

            if ($this->overlaps($school->id, $employeeId, $section->id, $offering->id, $startsOn, $endsOn)) {
                throw new TeachingAssignmentOverlapException;
            }

            $assignment = new TeachingAssignment;
            $assignment->forceFill([
                'school_id' => $school->id,
                'employee_id' => $employeeId,
                'academic_year_id' => $offering->academic_year_id,
                'campus_id' => $offering->campus_id,
                'grade_level_id' => $offering->grade_level_id,
                'section_id' => $section->id,
                'subject_offering_id' => $offering->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'created_by_user_id' => $actor->id,
            ])->save();

            $this->audit->school($school, 'teaching_assignment.created', actor: $actor, subject: $assignment, metadata: [
                'teachingAssignmentId' => $assignment->id,
                'employeeId' => $employeeId,
                'sectionId' => $section->id,
                'subjectOfferingId' => $offering->id,
                'startsOn' => $startsOn,
                'endsOn' => $endsOn,
            ]);

            return $assignment->fresh();
        }));
    }

    /**
     * Ends an assignment: sets ends_on (or shortens an existing one, never
     * before starts_on), ended_at, ended_by_user_id and a closed reason --
     * once. Serialized on the same advisory lock as create(), then the row
     * FOR UPDATE; a second end is TeachingAssignmentAlreadyEndedException.
     * There is no cancellation: an assignment can at most be ended on its
     * own start date (ADR 0063 section 9).
     *
     * @param  string  $endsOn  School-local Y-m-d, the last effective day (inclusive)
     */
    public function end(School $school, string $assignmentId, string $endsOn, string $reason, User $actor): TeachingAssignment
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY_MANAGE, $school);

        if (! in_array($reason, TeachingAssignment::END_REASONS, true)) {
            throw new InvalidArgumentException("Unknown teaching assignment end reason: {$reason}");
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $assignmentId, $endsOn, $reason, $actor) {
            $this->guard->requireOperational($school->id);

            $key = TeachingAssignment::query()->where('school_id', $school->id)->findOrFail($assignmentId);
            $this->lockKey($school->id, $key->employee_id, $key->section_id, $key->subject_offering_id);

            $assignment = TeachingAssignment::query()->where('school_id', $school->id)->whereKey($assignmentId)->lockForUpdate()->firstOrFail();

            if ($assignment->isEnded()) {
                throw new TeachingAssignmentAlreadyEndedException;
            }

            $previousEndsOn = $assignment->ends_on?->toDateString();

            if ($endsOn < $assignment->starts_on->toDateString() || ($previousEndsOn !== null && $endsOn > $previousEndsOn)) {
                throw new InvalidAssignmentDatesException;
            }

            $assignment->forceFill([
                'ends_on' => $endsOn,
                'ended_at' => now(),
                'ended_by_user_id' => $actor->id,
                'end_reason' => $reason,
            ])->save();

            $this->audit->school($school, 'teaching_assignment.ended', actor: $actor, subject: $assignment, metadata: [
                'teachingAssignmentId' => $assignment->id,
                'employeeId' => $assignment->employee_id,
                'previousEndsOn' => $previousEndsOn,
                'endsOn' => $endsOn,
                'endReason' => $reason,
            ]);

            return $assignment->fresh();
        }));
    }

    /**
     * S7 (ADR 0063 §47): the employment-end path -- called only by
     * EmploymentEndedTeachingOwnership, inside EmploymentService::end()'s
     * transaction (the EmploymentRecord is already FOR UPDATE and ended).
     * Every row of the Employee that would grant ownership after $endsOn
     * ends, reason `employment_ended`, by the HR actor:
     * - started by $endsOn (open, scheduled past it, or already ended past
     *   it): ends_on = $endsOn -- ownership holds through the last employed
     *   day, never after;
     * - not started by $endsOn: voided -- ends_on = starts_on - 1, so it never
     *   covers a date; the row stays as history;
     * - ending on or before $endsOn already: untouched.
     * Never a delete, never a new starts_on. The rows are taken FOR UPDATE in
     * id order with NO advisory key: create() takes its key BEFORE the
     * EmploymentRecord, so taking one here, after it, could deadlock. A
     * create() of this Employee either committed first (its row is seen
     * here) or waits on the EmploymentRecord and then sees the end.
     *
     * Authorized by the HR capability that ends the employment.
     *
     * @param  string  $endsOn  School-local Y-m-d, the employment's last day (inclusive)
     */
    public function endForEmployment(School $school, string $employeeId, string $endsOn, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, TeachingAssignmentService::CAPABILITY_EMPLOYMENT_END, $school);

        if (DB::transactionLevel() === 0) {
            throw new LogicException('endForEmployment() runs only inside the employment-end transaction.');
        }

        $this->context->withSchool($school, function () use ($school, $employeeId, $endsOn, $actor): void {
            $rows = TeachingAssignment::query()
                ->where('school_id', $school->id)
                ->where('employee_id', $employeeId)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $endsOn))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $assignment) {
                $startsOn = $assignment->starts_on->toDateString();
                $target = $startsOn > $endsOn ? $assignment->starts_on->copy()->subDay()->toDateString() : $endsOn;
                $previousEndsOn = $assignment->ends_on?->toDateString();

                if ($previousEndsOn !== null && $target >= $previousEndsOn) {
                    continue; // already voided at its own start (an earlier employment end)
                }

                $previousEndReason = $assignment->end_reason;
                $assignment->forceFill([
                    'ends_on' => $target,
                    'ended_at' => now(),
                    'ended_by_user_id' => $actor->id,
                    'end_reason' => TeachingAssignment::END_REASON_EMPLOYMENT_ENDED,
                ])->save();

                $this->audit->school($school, 'teaching_assignment.ended', actor: $actor, subject: $assignment, metadata: [
                    'teachingAssignmentId' => $assignment->id,
                    'employeeId' => $employeeId,
                    'previousEndsOn' => $previousEndsOn,
                    'previousEndReason' => $previousEndReason,
                    'endsOn' => $target,
                    'endReason' => TeachingAssignment::END_REASON_EMPLOYMENT_ENDED,
                    'employmentEndsOn' => $endsOn,
                ]);
            }
        });
    }

    /** The per-key advisory lock (transaction-scoped), the FeeSettingsService convention. */
    public static function lockKeyName(string $schoolId, string $employeeId, string $sectionId, string $subjectOfferingId): string
    {
        return "teaching.assignment:{$schoolId}:{$employeeId}:{$sectionId}:{$subjectOfferingId}";
    }

    private function lockKey(string $schoolId, string $employeeId, string $sectionId, string $subjectOfferingId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            self::lockKeyName($schoolId, $employeeId, $sectionId, $subjectOfferingId),
        ]);
    }

    /**
     * Inclusive-date overlap with any row of the same key:
     * existing.starts_on <= new.ends_on (or +inf) AND
     * new.starts_on <= existing.ends_on (or +inf). Ended rows count with
     * their final ends_on -- ended_at is history, never an overlap rule.
     * A row voided by an employment end (S7: ends_on = starts_on - 1)
     * covers no day and overlaps nothing.
     */
    private function overlaps(string $schoolId, string $employeeId, string $sectionId, string $subjectOfferingId, string $startsOn, ?string $endsOn): bool
    {
        return TeachingAssignment::query()
            ->where('school_id', $schoolId)
            ->where('employee_id', $employeeId)
            ->where('section_id', $sectionId)
            ->where('subject_offering_id', $subjectOfferingId)
            ->when($endsOn !== null, fn ($q) => $q->where('starts_on', '<=', $endsOn))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereColumn('ends_on', '>=', 'starts_on')) // S7: a voided row covers no day
            ->exists();
    }
}
