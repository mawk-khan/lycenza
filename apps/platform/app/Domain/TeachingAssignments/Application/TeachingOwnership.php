<?php

namespace App\Domain\TeachingAssignments\Application;

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
}
