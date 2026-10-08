<?php

namespace App\Domain\TeachingAssignments\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * TCH.3 (ADR 0063 sections 11, 16, 20): the TeachingAssignments read a
 * downstream owned consumer uses -- "does this Employee own this Section +
 * SubjectOffering, and when?". Fresh, tenant-scoped, never cached, never
 * derived from TimetableEntry, never aware of any role.
 *
 * - periods(): every ownership period of one Employee (current, past and
 *   future -- ended rows keep their final ends_on), for listing and
 *   visibility.
 * - hold(): inside the caller's transaction, the ONE assignment of the key
 *   (School, Employee, Section, Offering) covering $date, read FOR SHARE.
 *   A TeachingAssignmentService::end() either commits first (and this
 *   finds no coverage for a date the end removed) or waits for the caller
 *   to commit. Zero -- or, in corrupt data, more than one -- covering row
 *   is "not owned" (fail closed).
 *
 * TCH-E (ADR 0063 section 45) adds the ELECTIVE fact, Offering-wide:
 * - electivePeriods() / holdElective(): the same two reads over
 *   `elective_teaching_assignments` (Employee x elective Offering);
 * - holdOffering(): one decision for any Offering. Inside the caller's
 *   transaction it takes the Offering FOR SHARE (a required<->elective change
 *   waits) and asks the fact that matches it: a required Offering needs the
 *   Section (hold()), an elective one does not (holdElective()). A required
 *   Offering without a Section, or an elective one judged by a Section, is
 *   "not owned".
 * periods() and hold() are unchanged: required ownership never reads the
 * elective fact, and no existing consumer sees elective periods.
 *
 * S7 (ADR 0063 §47): a row voided by an employment end (ends_on = starts_on
 * - 1) never covered a day, so it is no period; hold() never covers it.
 *
 * The Employee is the caller's ActingEmployee; this class never resolves
 * one and never decides authorization by itself.
 */
class TeachingOwnership
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return list<OwnedTeachingPeriod> */
    public function periods(School $school, string $employeeId): array
    {
        return $this->context->withSchool($school, fn () => TeachingAssignment::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employeeId)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereColumn('ends_on', '>=', 'starts_on')) // S7: a voided row never owned a day
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->map(fn (TeachingAssignment $a) => new OwnedTeachingPeriod(
                $a->id,
                $a->section_id,
                $a->subject_offering_id,
                $a->starts_on->toDateString(),
                $a->ends_on?->toDateString(),
            ))->values()->all());
    }

    /**
     * @param  string  $date  School-local Y-m-d
     */
    public function hold(School $school, string $employeeId, string $sectionId, string $subjectOfferingId, string $date): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TeachingOwnership::hold() must run inside a database transaction.');
        }

        // Rows, not count(*): PostgreSQL refuses FOR SHARE on an aggregate,
        // and the covering rows themselves are what must stay locked.
        $covering = $this->context->withSchool($school, fn () => TeachingAssignment::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employeeId)
            ->where('section_id', $sectionId)
            ->where('subject_offering_id', $subjectOfferingId)
            ->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->sharedLock()
            ->limit(2)
            ->pluck('id')
            ->all());

        return count($covering) === 1;
    }

    /** @return list<OwnedElectivePeriod> every elective ownership period of one Employee (past, current and future) */
    public function electivePeriods(School $school, string $employeeId): array
    {
        return $this->context->withSchool($school, fn () => ElectiveTeachingAssignment::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employeeId)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereColumn('ends_on', '>=', 'starts_on')) // S7: a voided row never owned a day
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->map(fn (ElectiveTeachingAssignment $a) => new OwnedElectivePeriod(
                $a->id,
                $a->subject_offering_id,
                $a->starts_on->toDateString(),
                $a->ends_on?->toDateString(),
            ))->values()->all());
    }

    /**
     * hold() for an elective Offering: inside the caller's transaction, the ONE elective assignment of the key
     * (School, Employee, Offering) covering $date, FOR SHARE. ElectiveTeachingAssignmentService::end() commits
     * first or waits. Zero (or, in corrupt data, more than one) covering row is "not owned".
     *
     * @param  string  $date  School-local Y-m-d
     */
    public function holdElective(School $school, string $employeeId, string $subjectOfferingId, string $date): bool
    {
        $this->requireTransaction();

        $covering = $this->context->withSchool($school, fn () => ElectiveTeachingAssignment::query()
            ->where('school_id', $school->id)
            ->where('employee_id', $employeeId)
            ->where('subject_offering_id', $subjectOfferingId)
            ->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->sharedLock()
            ->limit(2)
            ->pluck('id')
            ->all());

        return count($covering) === 1;
    }

    /**
     * One ownership decision for any Offering, inside the caller's transaction: the Offering FOR SHARE, then the
     * fact that matches it -- required: hold() for $sectionId (required); elective: holdElective() ($sectionId
     * must be null; an elective has no Section cohort).
     *
     * @param  string  $date  School-local Y-m-d
     */
    public function holdOffering(School $school, string $employeeId, string $subjectOfferingId, ?string $sectionId, string $date): bool
    {
        $this->requireTransaction();

        $isRequired = $this->context->withSchool($school, fn () => SubjectOffering::query()
            ->where('school_id', $school->id)->whereKey($subjectOfferingId)->sharedLock()->value('is_required'));

        return match (true) {
            $isRequired === null => false,
            (bool) $isRequired => $sectionId !== null && $this->hold($school, $employeeId, $sectionId, $subjectOfferingId, $date),
            default => $sectionId === null && $this->holdElective($school, $employeeId, $subjectOfferingId, $date),
        };
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TeachingOwnership holds must run inside a database transaction.');
        }
    }
}
