<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.9D-1 (ADR 0076 §73): HR's narrow answer to "which Users are CURRENT
 * staff of this School?" -- the set-based form of ActingEmployeeResolver's
 * identity chain, so another domain (today: the Communications Staff
 * audience) never derives staff from a role name, a capability or an email:
 *
 *   active SchoolMembership for (user, school)
 *   -> enabled User
 *   -> the Employee linked through employees(school_id, user_id) (unique)
 *   -> Employee.record_status = 'active'
 *   -> exactly one EmploymentRecord with starts_on <= today
 *      AND (ends_on IS NULL OR ends_on >= today)
 *      AND status IN EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES
 *
 * "today" is the School-local date. A leaver (ended employment), a future
 * hire (starts_on after today), an archived Employee, a disabled User, a
 * suspended membership and an Employee with no linked User are never staff;
 * two eligible employments (impossible under EmploymentService's overlap
 * invariant) fail closed, exactly like the resolver. Returns User ids ONLY --
 * never an Employee, a position, pay, contact or any other HR field.
 * Four bounded reads, School-constrained, under the School's TenantContext.
 */
final class CurrentStaffMembers
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * ORG.9D-3 (ADR 0076 §75): the same answer for ONE User -- bounded reads
     * keyed by that User, never the whole staff set.
     */
    public function includes(School $school, User $user): bool
    {
        if ($user->isDisabled()) {
            return false;
        }
        $today = SchoolTimezone::today($school);

        return $this->context->withSchool($school, function () use ($school, $user, $today): bool {
            if (! DB::table('school_memberships')->where('school_id', $school->id)->where('user_id', $user->id)->where('status', 'active')->exists()) {
                return false;
            }
            $employeeId = DB::table('employees')->where('school_id', $school->id)->where('user_id', $user->id)->where('record_status', 'active')->value('id');
            if ($employeeId === null) {
                return false;
            }

            return DB::table('employment_records')
                ->where('school_id', $school->id)
                ->where('employee_id', $employeeId)
                ->whereIn('status', EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES)
                ->where('starts_on', '<=', $today)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
                ->count() === 1;
        });
    }

    /** @return list<string> */
    public function userIds(School $school): array
    {
        $today = SchoolTimezone::today($school);

        return $this->context->withSchool($school, function () use ($school, $today): array {
            $eligible = DB::table('employment_records')
                ->where('school_id', $school->id)
                ->whereIn('status', EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES)
                ->where('starts_on', '<=', $today)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
                ->groupBy('employee_id')
                ->havingRaw('count(*) = 1')
                ->pluck('employee_id')
                ->all();
            if ($eligible === []) {
                return [];
            }

            // Four bounded, index-driven reads intersected here -- never one join of the
            // membership set against the employment set, which runs as a nested-loop
            // cross product on thin statistics (ORG.9D-1 query review: ~2.4 s at 1,050 staff).
            $userIds = DB::table('employees')
                ->where('school_id', $school->id)
                ->where('record_status', 'active')
                ->whereNotNull('user_id')
                ->whereIn('id', $eligible)
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all();
            if ($userIds === []) {
                return [];
            }

            $enabled = DB::table('users')->whereIn('id', $userIds)->where('is_disabled', false)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $members = DB::table('school_memberships')->where('school_id', $school->id)->where('status', 'active')
                ->whereIn('user_id', $enabled)->pluck('user_id')->map(fn ($id) => (string) $id)->all();

            $result = array_values(array_unique($members));
            sort($result);

            /** @var list<string> */
            return $result;
        });
    }
}
