<?php

namespace App\Domain\HR\Application\Separation;

use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\HR\Infrastructure\EmploymentSeparationEvent;
use App\Domain\HR\Infrastructure\SeparationClearanceItem;
use App\Domain\HR\Infrastructure\SeparationClearanceRequirement;
use App\Domain\Identity\Application\Staff\StaffAccessState;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * HR.12: the separation reads -- the paged case queue and one case's detail
 * (terms, timeline, clearance, owners' obligations and the actions this
 * viewer may take). Whole-School `hr.separations.view`; another School's id
 * is the same "not found". Names shown are the Employee's directory name and
 * the actors' display names; never contact, personal or pay data.
 */
final class SeparationCaseReadService
{
    public const int PER_PAGE = 25;

    public const array STATUS_FILTERS = ['open', 'requested', 'approved', 'finalized', 'completed', 'closed'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly HrEmployeeAccess $access,
        private readonly CapabilityResolver $capabilities,
        private readonly SeparationObligations $obligations,
        private readonly StaffAccessState $staffAccess,
    ) {}

    /**
     * @param  array{status?: string|null, reason?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, perPage: int, counts: array<string, int>}
     */
    public function page(School $school, User $viewer, array $filters, int $page = 1): array
    {
        $this->access->requireWholeSchool($viewer, SeparationCapabilities::VIEW, $school);
        $page = max(1, $page);

        return $this->context->withSchool($school, function () use ($school, $filters, $page): array {
            $base = DB::table('employment_separation_cases as c')
                ->join('employment_records as er', 'er.id', '=', 'c.employment_record_id')
                ->join('employees as e', 'e.id', '=', 'er.employee_id')
                ->where('c.school_id', $school->id);

            $counts = (clone $base)->selectRaw('c.status, count(*) as n')->groupBy('c.status')->pluck('n', 'c.status')->map(fn ($n) => (int) $n)->all();

            $query = clone $base;
            $status = $filters['status'] ?? 'open';
            match ($status) {
                'open' => $query->whereIn('c.status', ['requested', 'approved', 'finalized']),
                'closed' => $query->whereIn('c.status', ['rejected', 'withdrawn', 'cancelled']),
                'requested', 'approved', 'finalized', 'completed' => $query->where('c.status', $status),
                default => $query->whereIn('c.status', ['requested', 'approved', 'finalized']),
            };
            if (($reason = $filters['reason'] ?? null) !== null && SeparationReason::tryFrom($reason) !== null) {
                $query->where('c.reason_code', $reason);
            }

            $total = (clone $query)->count();
            $rows = $query->orderBy('c.planned_last_day')->orderBy('c.id')
                ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
                ->get(['c.id', 'c.status', 'c.reason_code', 'c.notified_on', 'c.planned_last_day', 'c.final_ends_on', 'e.id as employee_id', 'e.full_name', 'e.employee_number'])
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'status' => $r->status,
                    'reason' => SeparationReason::from($r->reason_code)->label(),
                    'notifiedOn' => $r->notified_on,
                    'plannedLastDay' => $r->planned_last_day,
                    'finalEndsOn' => $r->final_ends_on,
                    'employeeId' => $r->employee_id,
                    'employeeName' => $r->full_name,
                    'employeeNumber' => $r->employee_number,
                ])->all();

            return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => self::PER_PAGE, 'counts' => $counts];
        });
    }

    /** @return array<string, mixed> */
    public function detail(School $school, User $viewer, string $caseId): array
    {
        $this->access->requireWholeSchool($viewer, SeparationCapabilities::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $viewer, $caseId): array {
            $case = EmploymentSeparationCase::query()->where('school_id', $school->id)->whereKey($caseId)->first()
                ?? throw SeparationException::notFound();
            $employment = EmploymentRecord::query()->with('employee')->whereKey($case->employment_record_id)->firstOrFail();

            $userIds = array_values(array_unique(array_filter([
                $case->requested_by_user_id, $case->decided_by_user_id, $case->withdrawn_by_user_id, $case->cancelled_by_user_id,
                $case->finalized_by_user_id, $case->access_retained_by_user_id, $case->completed_by_user_id,
                ...EmploymentSeparationEvent::query()->where('case_id', $case->id)->pluck('actor_user_id')->all(),
                ...SeparationClearanceItem::query()->where('case_id', $case->id)->pluck('cleared_by_user_id')->all(),
            ])));
            $names = User::query()->whereIn('id', $userIds)->pluck('name', 'id');

            $items = SeparationClearanceItem::query()->where('case_id', $case->id)->orderBy('sequence')->orderBy('code')->get();
            $obligations = in_array($case->status, ['approved', 'finalized', 'completed'], true) ? $this->obligations->forCase($school, $case) : [];
            $subjectUserId = $employment->employee?->user_id;
            $isSubject = $subjectUserId !== null && $subjectUserId === $viewer->id;
            $can = fn (string $capability): bool => ! $isSubject && $this->capabilities->can($viewer, $capability, $school);
            $today = SchoolTimezone::today($school);

            $pendingMandatory = $items->filter(fn ($i) => $i->is_mandatory && $i->status !== 'cleared')->count();
            $blocking = SeparationObligations::blocking($obligations);

            return [
                'id' => $case->id,
                'version' => $case->version,
                'status' => $case->status,
                'reason' => ['value' => $case->reason_code, 'label' => SeparationReason::from($case->reason_code)->label()],
                'notifiedOn' => $case->notified_on->toDateString(),
                'plannedLastDay' => $case->planned_last_day->toDateString(),
                'finalEndsOn' => $case->final_ends_on?->toDateString(),
                'cancelReason' => $case->cancel_reason === null ? null : SeparationCancelReason::from($case->cancel_reason)->label(),
                'accessRetained' => $case->access_retained_at !== null,
                // HR.R1-C35 WO-20 (HRC-4): what was recorded as kept -- the bound grants, or a legacy record that bound
                // none. Never today's roles presented as the kept ones. Which roles they are is someone's role
                // assignments, so only a viewer who may see those (`school.roles.view`, or the access administrator's
                // `school.members.manage`) gets the roles (review M1); everyone else gets the number of grants.
                'accessRetention' => $case->access_retained_by_user_id === null ? null : [
                    'recordedAt' => $case->access_retained_at?->setTimezone(SchoolTimezone::resolve($school))->format('Y-m-d H:i'),
                    'recordedBy' => $names[$case->access_retained_by_user_id] ?? null,
                    'bound' => $case->retainedGrantIds() !== null,
                    'grantCount' => count($case->retainedGrantIds() ?? []),
                    'roles' => $case->retainedGrantIds() === null || ! ($can('school.roles.view') || $can('school.members.manage')) ? null : array_map(
                        fn (array $r) => ['grantId' => $r['grantId'], 'roleName' => $r['roleName'], 'campus' => $r['campus'], 'revoked' => $r['revoked']],
                        $this->staffAccess->grantRoles($school, $case->retainedGrantIds()),
                    ),
                ],
                'today' => $today,
                'employee' => [
                    'id' => $employment->employee_id,
                    'name' => $employment->employee?->full_name,
                    'number' => $employment->employee?->employee_number,
                ],
                'employment' => [
                    'id' => $employment->id,
                    'startsOn' => $employment->starts_on->toDateString(),
                    'endsOn' => $employment->ends_on?->toDateString(),
                    'status' => $employment->status,
                    'staffType' => $employment->staff_type,
                    'staffTypeLabel' => $employment->staff_type === null ? null : StaffType::from($employment->staff_type)->label(),
                ],
                'clearanceBasis' => $this->clearanceBasis($school, $case, $employment),
                'people' => [
                    'requestedBy' => $names[$case->requested_by_user_id] ?? null,
                    'decidedBy' => $case->decided_by_user_id === null ? null : ($names[$case->decided_by_user_id] ?? null),
                    'finalizedBy' => $case->finalized_by_user_id === null ? null : ($names[$case->finalized_by_user_id] ?? null),
                ],
                'timeline' => EmploymentSeparationEvent::query()->where('case_id', $case->id)->orderBy('occurred_at')->orderBy('id')->get()
                    ->map(fn (EmploymentSeparationEvent $e) => [
                        'id' => $e->id,
                        'kind' => $e->kind,
                        'occurredAt' => $e->occurred_at->toIso8601String(),
                        'actor' => $names[$e->actor_user_id] ?? null,
                        'before' => $e->planned_last_day_before?->toDateString(),
                        'after' => $e->planned_last_day_after?->toDateString(),
                        'item' => $e->clearance_item_id === null ? null : $items->firstWhere('id', $e->clearance_item_id)?->label,
                    ])->all(),
                'clearance' => $items->map(fn (SeparationClearanceItem $i) => [
                    'id' => $i->id,
                    'label' => $i->label,
                    'responsibility' => ClearanceResponsibility::from($i->responsibility)->label(),
                    'mandatory' => $i->is_mandatory,
                    'status' => $i->status,
                    'clearedBy' => $i->cleared_by_user_id === null ? null : ($names[$i->cleared_by_user_id] ?? null),
                    'clearedAt' => $i->cleared_at?->toIso8601String(),
                    'canClear' => $i->status === 'pending' && in_array($case->status, ['approved', 'finalized'], true)
                        && $can(ClearanceResponsibility::from($i->responsibility)->capability()),
                ])->all(),
                'obligations' => array_map(fn (SeparationObligation $o) => $o->toArray(), $obligations),
                'pendingMandatory' => $pendingMandatory,
                'blockingCount' => count($blocking),
                'actions' => [
                    'decide' => $case->status === 'requested' && $can(SeparationCapabilities::APPROVE) && $case->requested_by_user_id !== $viewer->id,
                    'decideBlockedAsRequester' => $case->status === 'requested' && $case->requested_by_user_id === $viewer->id,
                    'withdraw' => $case->status === 'requested' && $can(SeparationCapabilities::MANAGE),
                    'changeLastDay' => $case->status === 'requested' && $can(SeparationCapabilities::MANAGE),
                    'cancel' => $case->status === 'approved' && $can(SeparationCapabilities::MANAGE),
                    'finalize' => $case->status === 'approved' && $can(SeparationCapabilities::MANAGE) && $can('hr.employees.assignments.manage'),
                    'finalizeReached' => $case->planned_last_day->toDateString() <= $today,
                    'recordAccessRetained' => $case->status === 'finalized' && ! $isSubject && $case->access_retained_at === null
                        && $this->capabilities->can($viewer, 'school.members.manage', $school)
                        && collect($obligations)->contains(fn (SeparationObligation $o) => $o->code === SeparationObligations::SCHOOL_ACCESS && $o->state === SeparationObligation::OPEN),
                    'complete' => $case->status === 'finalized' && $can(SeparationCapabilities::MANAGE),
                    'completeReady' => $pendingMandatory === 0 && $blocking === [],
                ],
                'cancelReasons' => SeparationCancelReason::options(),
            ];
        });
    }

    /**
     * HR.R1-C35 WO-21 (HRC-8, D-C35-4, D-WO21-1): which staff type the clearance checklist used, and which
     * staff-type requirements an unclassified employment did not get -- from the case's frozen record once
     * approved ('recorded'), or, before approval, a preview of what approving now would do ('preview', from the
     * School's active requirements and today's classification); a case approved before WO-21 says so
     * ('not_recorded'), never reconstructed. Each skipped requirement is named, with whether it has since been
     * retired. Nothing here changes a clearance state or blocks completion.
     *
     * @return array<string, mixed>
     */
    private function clearanceBasis(School $school, EmploymentSeparationCase $case, EmploymentRecord $employment): array
    {
        $label = fn (?string $type) => $type === null ? null : StaffType::from($type)->label();
        if ($case->status === 'requested') {
            $skipped = $employment->staff_type !== null ? [] : SeparationClearanceRequirement::query()->where('school_id', $school->id)
                ->where('status', 'active')->whereNotNull('staff_type')->pluck('id')->map(fn ($id) => (string) $id)->all();

            return ['state' => 'preview', 'staffType' => $employment->staff_type, 'staffTypeLabel' => $label($employment->staff_type),
                'unclassified' => $employment->staff_type === null, 'skipped' => $this->requirementsNamed($school, $skipped)];
        }
        // Approved at some point: approved, finalized, completed, or cancelled after approval (review LOW 4).
        if (! in_array($case->status, ['approved', 'finalized', 'completed', 'cancelled'], true)) {
            return ['state' => 'none'];
        }
        $recorded = $case->clearanceSkippedRequirementIds();
        if ($recorded === null) {
            return ['state' => 'not_recorded'];
        }

        return ['state' => 'recorded', 'staffType' => $case->clearance_staff_type, 'staffTypeLabel' => $label($case->clearance_staff_type),
            'unclassified' => $case->clearance_staff_type === null, 'skipped' => $this->requirementsNamed($school, $recorded)];
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string, code: string, label: string, staffTypeLabel: ?string, mandatory: bool, retired: bool}>
     */
    private function requirementsNamed(School $school, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return SeparationClearanceRequirement::query()->where('school_id', $school->id)->whereIn('id', $ids)->orderBy('sequence')->orderBy('code')->get()
            ->map(fn (SeparationClearanceRequirement $r) => [
                'id' => (string) $r->id, 'code' => $r->code, 'label' => $r->label,
                'staffTypeLabel' => $r->staff_type === null ? null : StaffType::from($r->staff_type)->label(),
                'mandatory' => (bool) $r->is_mandatory, 'retired' => $r->status === 'retired',
            ])->values()->all();
    }

    /** The employment a new case would be recorded for, or "not found" when it cannot have one. */
    public function recordable(School $school, User $viewer, string $employmentRecordId): array
    {
        $this->access->requireWholeSchool($viewer, SeparationCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $employmentRecordId): array {
            $employment = EmploymentRecord::query()->with('employee')->where('school_id', $school->id)->whereKey($employmentRecordId)->first()
                ?? throw SeparationException::notFound();
            $live = EmploymentSeparationCase::query()->where('employment_record_id', $employment->id)
                ->whereIn('status', ['requested', 'approved', 'finalized', 'completed'])->value('id');

            return [
                'employment' => ['id' => $employment->id, 'startsOn' => $employment->starts_on->toDateString(), 'status' => $employment->status, 'endsOn' => $employment->ends_on?->toDateString()],
                'employee' => ['id' => $employment->employee_id, 'name' => $employment->employee?->full_name, 'number' => $employment->employee?->employee_number],
                'recordable' => $employment->status === 'active' && $employment->ends_on === null && $live === null,
                'liveCaseId' => $live,
                'today' => SchoolTimezone::today($school),
                'reasons' => SeparationReason::options(),
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function requirements(School $school, User $viewer): array
    {
        $this->access->requireWholeSchool($viewer, SeparationCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => SeparationClearanceRequirement::query()->where('school_id', $school->id)
            ->orderByRaw("status = 'retired'")->orderBy('sequence')->orderBy('code')->get()
            ->map(fn (SeparationClearanceRequirement $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'label' => $r->label,
                'responsibility' => ClearanceResponsibility::from($r->responsibility)->label(),
                'mandatory' => $r->is_mandatory,
                'staffType' => $r->staff_type,
                'status' => $r->status,
            ])->all());
    }
}
