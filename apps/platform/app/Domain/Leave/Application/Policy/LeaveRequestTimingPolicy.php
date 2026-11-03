<?php

namespace App\Domain\Leave\Application\Policy;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveRequestShape;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;

/**
 * HR.9 (ADR 0065 §28.2): applies the request-timing rules at SUBMISSION, on
 * every path (self-service and on behalf -- one rule set, no override).
 *
 * The versions judged are every policy version assigned to the employment
 * for this leave type on ANY requested date (an assignment overlapping the
 * request), read in one query; each one's rules must hold. The reference
 * date is the School-local today. An employment with no assignment (an
 * unpaid type, or a tracked type whose approval will itself refuse
 * LEAVE_NO_POLICY_ASSIGNMENT) has no timing rule -- unchanged behaviour.
 *
 * Approval does not re-judge timing: it is a rule about when an employee
 * applies, not when someone decides. Every stronger lock still applies at
 * approval and is never relaxed by a window: a closed leave year, recorded
 * presence, the year sealed for Payroll evidence (ADR 0065 §23-§26).
 */
final class LeaveRequestTimingPolicy
{
    /**
     * @return list<string> the policy version ids whose rules were applied
     *
     * @throws LeaveException
     */
    public function enforce(School $school, string $employmentRecordId, string $leaveTypeId, LeaveRequestShape $shape, int $units): array
    {
        $versions = LeavePolicy::query()
            ->where('leave_policies.school_id', $school->id)
            ->whereIn('leave_policies.id', fn ($q) => $q->select('leave_policy_id')->from('leave_policy_assignments')
                ->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)->where('leave_type_id', $leaveTypeId)
                ->where('effective_from', '<=', $shape->endsOn)
                ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $shape->startsOn)))
            ->orderBy('leave_policies.id')
            ->get();

        $today = SchoolTimezone::today($school);
        foreach ($versions as $version) {
            LeaveRequestTimingRules::of($version)->assertSatisfiedBy($shape->startsOn, $today, $units);
        }

        return $versions->filter(fn (LeavePolicy $p) => ! LeaveRequestTimingRules::of($p)->isEmpty())->pluck('id')->values()->all();
    }
}
