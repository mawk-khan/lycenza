<?php

namespace App\Domain\HR\Application\Workforce;

use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentTerm;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * HR.1A: the Profile's display of an Employee's employment terms history and
 * named placement changes, per employment, oldest first. HR.3A adds the
 * employment's reporting lines (voided corrections included, marked), with
 * the manager's name. Whole-School
 * `hr.employees.assignments.view` only (the Profile's employment tier; a
 * Campus reader uses the operational overview); nothing otherwise. Labels,
 * dates, closed reason/kind labels and Campus/Department/Position names --
 * never pay, personal, contact or actor data. A constant number of queries.
 */
final class WorkforceHistoryReadService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HrEmployeeAccess $access,
    ) {}

    /**
     * @return array<string, array{
     *     currentTerms: array{employmentType: string, employmentTypeLabel: string, staffType: ?string, staffTypeLabel: string, categoryName: ?string}|null,
     *     terms: list<array{id: string, effectiveFrom: string, employmentTypeLabel: string, staffTypeLabel: string, categoryName: ?string, reasonLabel: string}>,
     *     changes: list<array{id: string, effectiveOn: string, kind: string, kindLabel: string, reasonLabel: string, from: ?string, to: string}>,
     *     reporting: list<array{id: string, managerName: ?string, startsOn: string, endsOn: ?string, startedLabel: string, startReasonLabel: ?string, endedLabel: ?string, endReasonLabel: ?string, voided: bool, voidReasonLabel: ?string}>
     * }> employment id => history
     */
    public function forEmployee(School $school, string $employeeId, User $actor): array
    {
        if (! $this->access->holdsWholeSchool($actor, 'hr.employees.assignments.view', $school)) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $employeeId): array {
            $employmentIds = EmploymentRecord::query()->where('school_id', $school->id)->where('employee_id', $employeeId)
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
            if ($employmentIds === []) {
                return [];
            }

            $terms = EmploymentTerm::query()->where('school_id', $school->id)->whereIn('employment_record_id', $employmentIds)
                ->with('category:id,name')->orderBy('effective_from')->get()->groupBy('employment_record_id');
            $changes = EmployeePlacementChange::query()->where('school_id', $school->id)->whereIn('employment_record_id', $employmentIds)
                ->orderBy('effective_on')->orderBy('created_at')->get();
            $assignmentIds = $changes->flatMap(fn (EmployeePlacementChange $c) => array_filter([$c->from_assignment_id, $c->to_assignment_id]))->unique()->values()->all();
            /** @var Collection<string, EmployeeAssignment> $assignments */
            $assignments = $assignmentIds === [] ? collect() : EmployeeAssignment::query()->where('school_id', $school->id)->whereIn('id', $assignmentIds)
                ->with(['campus:id,name', 'department:id,name', 'position:id,name'])->get()->keyBy('id');

            $lines = ReportingRelationship::query()->where('school_id', $school->id)->whereIn('subordinate_employment_id', $employmentIds)
                ->orderBy('starts_on')->orderBy('created_at')->orderBy('id')->get();
            $managerEmployees = $lines->isEmpty() ? collect() : EmploymentRecord::query()->where('school_id', $school->id)
                ->whereIn('id', $lines->pluck('manager_employment_id')->unique()->all())->pluck('employee_id', 'id');
            $managerNames = $managerEmployees->isEmpty() ? collect() : Employee::query()->where('school_id', $school->id)
                ->whereIn('id', $managerEmployees->unique()->values()->all())->pluck('full_name', 'id');

            $result = [];
            foreach ($employmentIds as $employmentId) {
                /** @var Collection<int, EmploymentTerm> $own */
                $own = $terms->get($employmentId, collect());
                $latest = $own->last();
                $result[$employmentId] = [
                    'currentTerms' => $latest === null ? null : [
                        'employmentType' => $latest->employment_type,
                        'employmentTypeLabel' => self::typeLabel($latest->employment_type),
                        'staffType' => $latest->staff_type,
                        'staffTypeLabel' => self::staffLabel($latest->staff_type),
                        'categoryName' => $latest->category?->name,
                    ],
                    'terms' => $own->map(fn (EmploymentTerm $t) => [
                        'id' => $t->id,
                        'effectiveFrom' => $t->effective_from->toDateString(),
                        'employmentTypeLabel' => self::typeLabel($t->employment_type),
                        'staffTypeLabel' => self::staffLabel($t->staff_type),
                        'categoryName' => $t->category?->name,
                        'reasonLabel' => EmploymentTermsChangeReason::labelFor($t->reason_code),
                    ])->values()->all(),
                    'changes' => $changes->where('employment_record_id', $employmentId)->map(fn (EmployeePlacementChange $c) => [
                        'id' => $c->id,
                        'effectiveOn' => $c->effective_on->toDateString(),
                        'kind' => $c->change_kind,
                        'kindLabel' => PlacementChangeKind::labelFor($c->change_kind),
                        'reasonLabel' => PlacementChangeReason::labelFor($c->reason_code),
                        'from' => $c->from_assignment_id === null ? null : self::placementLabel($assignments->get($c->from_assignment_id)),
                        'to' => self::placementLabel($assignments->get($c->to_assignment_id)) ?? '—',
                    ])->values()->all(),
                    'reporting' => $lines->where('subordinate_employment_id', $employmentId)->map(fn (ReportingRelationship $r) => [
                        'id' => $r->id,
                        'managerName' => $managerNames->get((string) $managerEmployees->get($r->manager_employment_id)),
                        'startsOn' => $r->starts_on->toDateString(),
                        'endsOn' => $r->ends_on?->toDateString(),
                        'startedLabel' => self::viaLabel($r->started_via),
                        'startReasonLabel' => ReportingChangeReason::labelFor($r->start_reason),
                        'endedLabel' => $r->ended_via === null ? null : self::viaLabel($r->ended_via),
                        'endReasonLabel' => ReportingChangeReason::labelFor($r->end_reason),
                        'voided' => $r->voided_at !== null,
                        'voidReasonLabel' => ReportingChangeReason::labelFor($r->void_reason),
                    ])->values()->all(),
                ];
            }

            return $result;
        });
    }

    private static function viaLabel(string $via): string
    {
        return match ($via) {
            'assigned' => 'Assigned',
            'changed' => 'Changed',
            'removed' => 'Removed',
            'corrected' => 'Corrected',
            'backfill' => 'Recorded at cut-over',
            'subordinate_employment_ended' => 'Employment ended',
            'manager_employment_ended' => "Manager's employment ended",
            default => $via,
        };
    }

    private static function typeLabel(string $value): string
    {
        return EmploymentType::tryFrom($value)?->label() ?? $value;
    }

    private static function staffLabel(?string $value): string
    {
        return $value === null ? 'Not classified' : (StaffType::tryFrom($value)?->label() ?? $value);
    }

    private static function placementLabel(?EmployeeAssignment $a): ?string
    {
        if ($a === null) {
            return null;
        }

        return implode(' · ', array_filter([$a->position?->name, $a->department?->name, $a->campus->name ?? 'School-wide']));
    }
}
