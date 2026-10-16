<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Privacy\EmailNormalizer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * UAT-C6 (UAT-STF-05, UAT-HR-23; ADR 0075 section 19): the two search-and-
 * pick reads behind the Employee page, so a browser user never types an id.
 * They only SUGGEST: the writes stay `EmployeeService::linkUser()` and
 * `ReportingHierarchyService::setManager()`, which re-validate everything
 * under their own locks.
 *
 * - Account candidates (`hr.employees.manage`): Users with an ACTIVE
 *   membership at this School, not disabled, not already linked to an
 *   Employee here, never the actor (no self-link, ADR 0071 §26.2). Names
 *   only -- like the Student/Guardian account-link search. An account whose
 *   sign-in email is this Employee's work email is listed FIRST; that match
 *   stays server-side (never returned), so the search cannot be used to test
 *   whether an address is someone's sign-in email.
 * - Manager candidates (`hr.employees.assignments.manage`): CURRENT
 *   assignments (HR.md: starts_on <= the School's today <= ends_on or
 *   open) of active Employees whose employment is active or in notice,
 *   never the subordinate's own Employee and never the actor's own.
 *   Labelled "Name (EMP-…) — Position · Department · Campus".
 */
class EmployeePickerReadService
{
    use AuthorizesCapability;

    private const LIMIT = 20;

    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array{userId: string, name: string}> */
    public function accountCandidates(School $school, Employee $employee, string $query, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $employee, $query, $actor) {
            $linked = Employee::query()->whereNotNull('user_id')->pluck('user_id');
            $term = self::like($query);
            $workEmail = $employee->work_email === null ? null : EmailNormalizer::canonical($employee->work_email);

            return SchoolMembership::query()
                ->where('school_id', $school->id)
                ->active()
                ->where('user_id', '!=', $actor->id)
                ->whereNotIn('user_id', $linked)
                ->whereHas('user', fn ($q) => $q->where('is_disabled', false)
                    ->when($term !== null, fn ($w) => $w->where('name', 'ilike', $term)))
                ->with('user:id,name,email')
                ->limit(self::LIMIT)
                ->get()
                ->sortBy(fn (SchoolMembership $m) => ($workEmail !== null && EmailNormalizer::canonical((string) $m->user->email) === $workEmail ? '0' : '1')
                    .'|'.mb_strtolower($m->user->name).'|'.$m->user->id)
                ->map(fn (SchoolMembership $m) => [
                    'userId' => $m->user->id,
                    'name' => $m->user->name,
                ])
                ->values()
                ->all();
        });
    }

    /** The linked account's display name, for an HR manager; null when unlinked. */
    public function linkedAccountName(School $school, Employee $employee, User $actor): ?string
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        return $employee->user_id === null ? null : User::query()->whereKey($employee->user_id)->value('name');
    }

    /** @return list<array{assignmentId: string, label: string}> */
    public function managerCandidates(School $school, EmployeeAssignment $subordinate, string $query, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.assignments.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $subordinate, $query, $actor) {
            $today = CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
            $term = self::like($query);
            $ownEmployeeId = Employee::query()->where('user_id', $actor->id)->value('id');
            $subordinateEmployeeId = EmploymentRecord::query()->whereKey($subordinate->employment_record_id)->value('employee_id');

            $employees = Employee::query()
                ->where('record_status', 'active')
                ->where('id', '!=', $subordinateEmployeeId)
                ->when($ownEmployeeId !== null, fn ($q) => $q->where('id', '!=', $ownEmployeeId))
                ->when($term !== null, fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'ilike', $term)->orWhere('employee_number', 'ilike', $term)))
                ->orderBy('full_name')
                ->limit(200)
                ->get(['id', 'full_name', 'employee_number'])
                ->keyBy('id');

            $records = EmploymentRecord::query()
                ->whereIn('employee_id', $employees->keys())
                ->whereIn('status', EmploymentRecord::AUTHORIZATION_ELIGIBLE_STATUSES)
                ->get(['id', 'employee_id'])
                ->keyBy('id');

            $assignments = EmployeeAssignment::query()
                ->whereIn('employment_record_id', $records->keys())
                ->where('id', '!=', $subordinate->id)
                ->whereDate('starts_on', '<=', $today)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                ->get();

            $positions = Position::query()->whereIn('id', $assignments->pluck('position_id'))->pluck('name', 'id');
            $departments = Department::query()->whereIn('id', $assignments->pluck('department_id')->filter())->pluck('name', 'id');
            $campuses = Campus::query()->whereIn('id', $assignments->pluck('campus_id')->filter())->pluck('name', 'id');

            return $assignments
                ->map(function (EmployeeAssignment $a) use ($employees, $records, $positions, $departments, $campuses) {
                    $employee = $employees[$records[$a->employment_record_id]->employee_id];
                    $where = array_filter([$positions[$a->position_id] ?? null, $departments[$a->department_id] ?? null, $campuses[$a->campus_id] ?? null]);

                    return [
                        'assignmentId' => $a->id,
                        'label' => "{$employee->full_name} ({$employee->employee_number})".($where === [] ? '' : ' — '.implode(' · ', $where)),
                        'sort' => mb_strtolower($employee->full_name).'|'.($a->is_primary ? '0' : '1'),
                    ];
                })
                ->sortBy('sort')
                ->take(self::LIMIT)
                ->map(fn (array $c) => ['assignmentId' => $c['assignmentId'], 'label' => $c['label']])
                ->values()
                ->all();
        });
    }

    private static function like(string $query): ?string
    {
        $query = trim($query);

        return $query === '' ? null : '%'.addcslashes($query, '%_\\').'%';
    }
}
