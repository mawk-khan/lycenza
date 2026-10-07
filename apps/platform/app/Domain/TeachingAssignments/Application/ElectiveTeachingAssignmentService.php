<?php

namespace App\Domain\TeachingAssignments\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Application\Exceptions\AcademicYearNotOpenException;
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

    public static function lockKeyName(string $schoolId, string $employeeId, string $subjectOfferingId): string
    {
        return "teaching.elective_assignment:{$schoolId}:{$employeeId}:{$subjectOfferingId}";
    }

    private function lockKey(string $schoolId, string $employeeId, string $subjectOfferingId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::lockKeyName($schoolId, $employeeId, $subjectOfferingId)]);
    }

    /** Inclusive-date overlap with any row of the same key; ended rows count with their final ends_on. */
    private function overlaps(string $schoolId, string $employeeId, string $subjectOfferingId, string $startsOn, ?string $endsOn): bool
    {
        return ElectiveTeachingAssignment::query()
            ->where('school_id', $schoolId)
            ->where('employee_id', $employeeId)
            ->where('subject_offering_id', $subjectOfferingId)
            ->when($endsOn !== null, fn ($q) => $q->where('starts_on', '<=', $endsOn))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
            ->exists();
    }
}
