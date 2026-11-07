<?php

namespace App\Domain\HR\Application\Workforce;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeePlacementLock;
use App\Domain\HR\Application\Exceptions\InvalidPlacementChangeException;
use App\Domain\HR\Application\Exceptions\PlacementChangeConflictException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Events\AssignmentEnded;
use App\Domain\HR\Events\PrimaryAssignmentChanged;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HR.1A: the named, dated, reasoned placement change -- transfer, promotion,
 * designation change or department change -- of an employment's PRIMARY
 * placement. Never an edit: the source assignment is closed the day before
 * the effective date and a NEW primary assignment opens on it, so both stay
 * queryable for ever (WorkforceAsOfReader::placementOn()).
 *
 *   Assignment A  Campus A, Primary Teacher  2026-04-01 .. 2026-10-31
 *   Assignment B  Campus B, Coordinator      2026-11-01 .. (A's planned end)
 *   employee_placement_changes: promotion, A -> B, 2026-11-01, reason, actor
 *
 * In ONE transaction:
 * - lock order: Employee (forEmployeeWrite), the employment row (FOR NO KEY
 *   UPDATE), then the source assignment (FOR UPDATE);
 * - the source must be the employment's primary assignment covering the
 *   effective date and starting before it; the kind must match what
 *   changes (PlacementChangeKind); something must change;
 * - reporting lines are untouched: since HR.3A they join EMPLOYMENTS, not
 *   assignments (`reporting_relationships`), so the mover keeps their manager
 *   and their direct reports keep them, for a past, current or future-dated
 *   change alike (the HR.1A `future_change_with_reports` refusal is gone);
 * - the database refuses an overlapping primary
 *   (`trg_employee_assignments_primary_no_overlap`) and binds the change
 *   record to the employment (`trg_employee_placement_changes_guard`).
 *
 * Authorization: `hr.employees.assignments.manage`, Campus-aware like the
 * other assignment writes (ORG.7E-1): a Campus-scoped actor changes only an
 * Employee wholly in scope, from today, to an in-scope Campus
 * (HrEmployeeCampusGuard::beforePlacementChange()); never one's own placement
 * (rule 95). A placement grants nothing: no capability follows it.
 */
final class PlacementChangeService
{
    use AuthorizesCampusScope;

    public function __construct(
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly HrEmployeeAccess $access,
        private readonly EmployeePlacementLock $placement,
        private readonly EmployeeAssignmentService $assignments,
    ) {}

    public function change(
        EmployeeAssignment $source,
        PlacementChangeKind $kind,
        string $effectiveOn,
        PlacementChangeReason $reason,
        Position $position,
        User $actor,
        ?Campus $campus = null,
        ?Department $department = null,
    ): EmployeeAssignment {
        $school = $source->school;
        $guard = $this->access->writeGuard($school, $this->authorizeCampusScopeFor($actor, 'hr.employees.assignments.manage', $school));
        $effectiveOn = CarbonImmutable::parse($effectiveOn)->toDateString();

        return $this->context->withSchool($school, fn (): EmployeeAssignment => DB::transaction(function () use ($school, $source, $kind, $effectiveOn, $reason, $position, $actor, $campus, $department, $guard): EmployeeAssignment {
            $this->operational->requireOperational($school->id);
            $employeeId = (string) EmploymentRecord::query()->where('school_id', $school->id)->whereKey($source->employment_record_id)->value('employee_id');
            if ($employeeId === '') {
                throw (new ModelNotFoundException)->setModel(EmployeeAssignment::class, [$source->id]);
            }
            $this->placement->forEmployeeWrite($school, $employeeId);
            $employment = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($source->employment_record_id)->lock('for no key update')->firstOrFail();

            $locked = EmployeeAssignment::query()->where('school_id', $school->id)->whereKey($source->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(EmployeeAssignment::class, [$source->id]);
            $this->selfAdministration->refuseOwnEmployee($actor, $employeeId, 'placement.change');

            $this->assertSourceIsThePrimaryOn($locked, $employment, $effectiveOn);
            [$changedFields] = $this->assertKindMatches($kind, $locked, $position, $campus, $department);
            $guard?->beforePlacementChange($locked, $effectiveOn, $campus?->id, $department);

            // 1. Close the source the day before (only narrows: never an overlap).
            $plannedEnd = $locked->ends_on?->toDateString();
            $closedOn = CarbonImmutable::parse($effectiveOn)->subDay()->toDateString();
            $locked->update(['ends_on' => $closedOn]);
            $this->audit->school($school, 'hr.assignment.ended', actor: $actor, subject: $locked, metadata: [
                'employeeId' => $employeeId,
                'employmentRecordId' => $employment->id,
                'endsOn' => $closedOn,
                'placementChange' => $kind->value,
            ]);
            event(new AssignmentEnded($school->id, $employeeId, $locked->id));

            // 2. Open the new primary placement (reference validation, self-guard,
            //    active Campus, employment range: EmployeeAssignmentService::createRecord()).
            $new = $this->assignments->createRecord($employment, ['starts_on' => $effectiveOn, 'ends_on' => $plannedEnd], $position, $actor, $campus, $department);
            try {
                $new->forceFill(['is_primary' => true])->save();
            } catch (QueryException $e) {
                if (in_array($e->getCode(), ['23P01', '23505'], true)) {
                    throw new PlacementChangeConflictException('primary_overlap', 'Another primary assignment already covers part of this period.');
                }
                throw $e;
            }
            event(new PrimaryAssignmentChanged($school->id, $employeeId, $employment->id, $new->id));

            // 3. The named, reasoned record.
            $change = EmployeePlacementChange::query()->create([
                'school_id' => $school->id,
                'employment_record_id' => $employment->id,
                'change_kind' => $kind->value,
                'from_assignment_id' => $locked->id,
                'to_assignment_id' => $new->id,
                'effective_on' => $effectiveOn,
                'reason_code' => $reason->value,
                'recorded_by_user_id' => $actor->id,
            ]);

            // Ids, closed codes and changed field names only.
            $this->audit->school($school, 'hr.placement.changed', actor: $actor, subject: $new, metadata: [
                'employeeId' => $employeeId,
                'employmentRecordId' => $employment->id,
                'placementChangeId' => $change->id,
                'kind' => $kind->value,
                'reason' => $reason->value,
                'effectiveOn' => $effectiveOn,
                'fromAssignmentId' => $locked->id,
                'toAssignmentId' => $new->id,
                'fields' => $changedFields,
            ]);

            return $new->fresh() ?? $new;
        }));
    }

    private function assertSourceIsThePrimaryOn(EmployeeAssignment $source, EmploymentRecord $employment, string $effectiveOn): void
    {
        if (in_array($employment->status, ['separated', 'terminated', 'retired', 'deceased'], true)) {
            throw new InvalidPlacementChangeException('employment_ended', 'An ended employment keeps its placement history.');
        }
        $employmentLast = $employment->ends_on?->toDateString();
        if ($employmentLast !== null && $effectiveOn > $employmentLast) {
            throw new InvalidPlacementChangeException('outside_employment', 'The change takes effect within the employment.');
        }
        $sourceFirst = $source->starts_on->toDateString();
        $sourceLast = $source->ends_on?->toDateString();
        // The source keeps at least one day, and covers the effective date.
        if (! $source->is_primary || $sourceFirst >= $effectiveOn || ($sourceLast !== null && $sourceLast < $effectiveOn)) {
            throw new PlacementChangeConflictException('source_not_primary_on_date', 'That assignment is not the primary placement on the effective date (or starts on it).');
        }
    }

    /**
     * @return array{0: list<string>} the changed placement fields
     */
    private function assertKindMatches(PlacementChangeKind $kind, EmployeeAssignment $source, Position $position, ?Campus $campus, ?Department $department): array
    {
        $campusChanges = $campus?->id !== $source->campus_id;
        $departmentChanges = $department?->id !== $source->department_id;
        $positionChanges = $position->id !== $source->position_id;
        $changed = array_keys(array_filter(['campus' => $campusChanges, 'department' => $departmentChanges, 'position' => $positionChanges]));

        if ($changed === []) {
            throw new InvalidPlacementChangeException('no_change', 'The new placement is the same as the current one.');
        }
        $matches = match ($kind) {
            PlacementChangeKind::TRANSFER => $campusChanges,
            PlacementChangeKind::PROMOTION, PlacementChangeKind::DESIGNATION_CHANGE => $positionChanges,
            PlacementChangeKind::DEPARTMENT_CHANGE => $departmentChanges && ! $campusChanges && ! $positionChanges,
        };
        if (! $matches) {
            throw new InvalidPlacementChangeException('kind_mismatch', match ($kind) {
                PlacementChangeKind::TRANSFER => 'A transfer changes the Campus.',
                PlacementChangeKind::PROMOTION, PlacementChangeKind::DESIGNATION_CHANGE => 'A promotion or designation change changes the designation.',
                PlacementChangeKind::DEPARTMENT_CHANGE => 'A department change changes only the Department.',
            });
        }

        return [$changed];
    }
}
