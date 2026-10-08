<?php

namespace App\Domain\TeachingAssignments\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Application\Exceptions\AcademicYearNotOpenException;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentBeyondEmploymentException;
use App\Domain\TeachingAssignments\Application\Exceptions\AssignmentOutsideAcademicYearException;
use App\Domain\TeachingAssignments\Application\Exceptions\ElectiveOfferingOnlyException;
use App\Domain\TeachingAssignments\Application\Exceptions\EmployeeNotAssignableException;
use App\Domain\TeachingAssignments\Application\Exceptions\InvalidAssignmentDatesException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentAlreadyEndedException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentOverlapException;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingContextInactiveException;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
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
 * TCH-E (ADR 0063 section 45) -- the ONE write path for
 * `elective_teaching_assignments`: create() and end(). TeachingAssignmentService
 * for an elective Offering, Offering-wide instead of Section x Offering, with
 * the same rules and the same capability (`teaching.assignments.manage`,
 * checked here as well as by the page). Teachers never assign themselves: the
 * acting administrator needs no Employee record (Tier 1).
 *
 * Concurrency, inside one transaction, in the TeachingAssignmentService order:
 *   1. School FOR SHARE (SchoolOperationalGuard, rule 86);
 *   2. the key's transaction-scoped advisory lock
 *      `teaching.elective_assignment:{school}:{employee}:{offering}` -- every
 *      create and end of the key serializes here, so the overlap check holds;
 *   3. the SubjectOffering FOR SHARE (an elective->required change or a
 *      deactivation waits, or commits first and is seen);
 *   4. the Employee and its covering EmploymentRecord FOR SHARE (HR
 *      EmploymentCoverage);
 *   5. the overlap check, then the insert and its audit.
 */
class ElectiveTeachingAssignmentService
{
    use AuthorizesCapability;

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
    public function create(School $school, string $employeeId, string $subjectOfferingId, string $startsOn, ?string $endsOn, User $actor): ElectiveTeachingAssignment
    {
        $this->authorizeCapabilityFor($actor, TeachingAssignmentService::CAPABILITY_MANAGE, $school);

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new InvalidAssignmentDatesException;
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employeeId, $subjectOfferingId, $startsOn, $endsOn, $actor) {
            $this->guard->requireOperational($school->id);
            $this->lockKey($school->id, $employeeId, $subjectOfferingId);

            $offering = SubjectOffering::query()->where('school_id', $school->id)->sharedLock()->findOrFail($subjectOfferingId);

            if ($offering->is_required) {
                throw new ElectiveOfferingOnlyException;
            }
            if (! $offering->isActive()) {
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

            if ($this->overlaps($school->id, $employeeId, $offering->id, $startsOn, $endsOn)) {
                throw new TeachingAssignmentOverlapException;
            }

            $assignment = new ElectiveTeachingAssignment;
            $assignment->forceFill([
                'school_id' => $school->id,
                'employee_id' => $employeeId,
                'academic_year_id' => $offering->academic_year_id,
                'campus_id' => $offering->campus_id,
                'grade_level_id' => $offering->grade_level_id,
                'subject_offering_id' => $offering->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'created_by_user_id' => $actor->id,
            ])->save();

            $this->audit->school($school, 'elective_teaching_assignment.created', actor: $actor, subject: $assignment, metadata: [
                'electiveTeachingAssignmentId' => $assignment->id,
                'employeeId' => $employeeId,
                'subjectOfferingId' => $offering->id,
                'startsOn' => $startsOn,
                'endsOn' => $endsOn,
            ]);

            return $assignment->fresh();
        }));
    }

    /**
     * Ends an assignment once: ends_on set or shortened (never before
     * starts_on), ended_at, ended_by_user_id and a closed reason. Serialized on
     * the create() lock, then the row FOR UPDATE.
     *
     * @param  string  $endsOn  School-local Y-m-d, the last effective day (inclusive)
     */
    public function end(School $school, string $assignmentId, string $endsOn, string $reason, User $actor): ElectiveTeachingAssignment
    {
        $this->authorizeCapabilityFor($actor, TeachingAssignmentService::CAPABILITY_MANAGE, $school);

        if (! in_array($reason, ElectiveTeachingAssignment::END_REASONS, true)) {
            throw new InvalidArgumentException("Unknown teaching assignment end reason: {$reason}");
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $assignmentId, $endsOn, $reason, $actor) {
            $this->guard->requireOperational($school->id);

            $key = ElectiveTeachingAssignment::query()->where('school_id', $school->id)->findOrFail($assignmentId);
            $this->lockKey($school->id, $key->employee_id, $key->subject_offering_id);

            $assignment = ElectiveTeachingAssignment::query()->where('school_id', $school->id)->whereKey($assignmentId)->lockForUpdate()->firstOrFail();
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

            $this->audit->school($school, 'elective_teaching_assignment.ended', actor: $actor, subject: $assignment, metadata: [
                'electiveTeachingAssignmentId' => $assignment->id,
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
            $rows = ElectiveTeachingAssignment::query()
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
                    'end_reason' => ElectiveTeachingAssignment::END_REASON_EMPLOYMENT_ENDED,
                ])->save();

                $this->audit->school($school, 'elective_teaching_assignment.ended', actor: $actor, subject: $assignment, metadata: [
                    'electiveTeachingAssignmentId' => $assignment->id,
                    'employeeId' => $employeeId,
                    'previousEndsOn' => $previousEndsOn,
                    'previousEndReason' => $previousEndReason,
                    'endsOn' => $target,
                    'endReason' => ElectiveTeachingAssignment::END_REASON_EMPLOYMENT_ENDED,
                    'employmentEndsOn' => $endsOn,
                ]);
            }
        });
    }

    public static function lockKeyName(string $schoolId, string $employeeId, string $subjectOfferingId): string
    {
        return "teaching.elective_assignment:{$schoolId}:{$employeeId}:{$subjectOfferingId}";
    }

    private function lockKey(string $schoolId, string $employeeId, string $subjectOfferingId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::lockKeyName($schoolId, $employeeId, $subjectOfferingId)]);
    }

    /** Inclusive-date overlap with any row of the same key; ended rows count with their final ends_on; a voided row (S7) overlaps nothing. */
    private function overlaps(string $schoolId, string $employeeId, string $subjectOfferingId, string $startsOn, ?string $endsOn): bool
    {
        return ElectiveTeachingAssignment::query()
            ->where('school_id', $schoolId)
            ->where('employee_id', $employeeId)
            ->where('subject_offering_id', $subjectOfferingId)
            ->when($endsOn !== null, fn ($q) => $q->where('starts_on', '<=', $endsOn))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereColumn('ends_on', '>=', 'starts_on')) // S7: a voided row covers no day
            ->exists();
    }
}
