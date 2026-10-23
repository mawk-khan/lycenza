<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\ActingEmployee;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Application\ReportingLine;
use App\Domain\Leave\Infrastructure\LeaveDecision;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\LeaveRequestDay;
use App\Domain\Leave\Infrastructure\LeaveYearClose;
use App\Domain\Leave\Infrastructure\LeaveYearCloseItem;
use App\Domain\Leave\Infrastructure\LeaveYearCloseReconciliation;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * HRX.2 (ADR 0065 §23): reads of leave requests and year closes.
 *
 * - Administrative reads need `hr.leave.view` and see the whole School.
 * - Manager reads need `hr.leave.approve`. They see ONLY the acting
 *   Employee's current direct reports, resolved server-side from HR's
 *   ReportingLine. Anything else is the private 404, so a capability
 *   without the relationship discovers nothing.
 * - Own reads (HRX.4) need `hr.leave.self` and see ONLY the acting
 *   Employee's requests; anything else is the same private 404.
 * - An approved request shows its stored chargeable-day evidence, never a
 *   recalculation.
 */
class LeaveRequestReadService
{
    use AuthorizesCapability;

    private const LIST_LIMIT = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ActingEmployeeResolver $acting,
        private readonly ReportingLine $reporting,
        private readonly LeaveReadService $leaveReads,
    ) {}

    /**
     * HRX.4 (ADR 0065 §25.4): the acting Employee's own leave overview (types
     * and current balances). No ActingEmployee is the private 404.
     *
     * @return array<string, mixed>
     */
    public function ownOverview(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::SELF, $school);

        return $this->leaveReads->ownOverview($school, $this->ownActing($school, $actor), $actor);
    }

    /**
     * HRX.4: the acting Employee's own requests, across their EmploymentRecords in this School.
     *
     * @return list<array<string, mixed>>
     */
    public function own(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::SELF, $school);
        $acting = $this->ownActing($school, $actor);

        return $this->context->withSchool($school, fn () => LeaveRequest::query()->where('school_id', $school->id)
            ->where('employee_id', $acting->employeeId)
            ->orderByDesc('starts_on')->orderByDesc('id')->limit(self::LIST_LIMIT)->get()
            ->map(fn (LeaveRequest $r) => self::request($r))->values()->all());
    }

    /**
     * HRX.4: one OWN request with its chargeable days and decisions (never the
     * ledger entries, never who decided). Anything not owned -- unknown,
     * another Employee's, another School's -- is the same private 404.
     *
     * @return array<string, mixed>
     */
    public function ownShow(School $school, string $leaveRequestId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::SELF, $school);
        $acting = $this->ownActing($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $leaveRequestId, $acting) {
            $request = LeaveRequest::query()->where('school_id', $school->id)->where('employee_id', $acting->employeeId)->find($leaveRequestId)
                ?? throw LeaveRequestService::privateNotFound();
            $detail = $this->detail($school, $request);
            unset($detail['ledgerEntries']);
            $detail['days'] = array_map(fn (array $d) => ['date' => $d['date'], 'portion' => $d['portion'], 'units' => $d['units'], 'leaveYearId' => $d['leaveYearId']], $detail['days']);

            return $detail;
        });
    }

    private function ownActing(School $school, User $actor): ActingEmployee
    {
        try {
            return $this->acting->resolve($actor, $school);
        } catch (ActingEmployeeUnavailableException) {
            throw LeaveRequestService::privateNotFound();
        }
    }

    /**
     * ORG.9C-1 (ADR 0076 §69), HR-043: also narrows by leave type and by a
     * date range the request OVERLAPS (School-local dates).
     *
     * @param  array{status?: string, employment_record_id?: string, leave_type_id?: string, from?: string, to?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(School $school, array $filters, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->context->withSchool($school, fn () => LeaveRequest::query()->where('school_id', $school->id)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['employment_record_id']), fn ($q) => $q->where('employment_record_id', $filters['employment_record_id']))
            ->when(isset($filters['leave_type_id']), fn ($q) => $q->where('leave_type_id', $filters['leave_type_id']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('ends_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('starts_on', '<=', $filters['to']))
            ->orderByDesc('starts_on')->orderByDesc('id')->limit(self::LIST_LIMIT)->get()
            ->map(fn (LeaveRequest $r) => self::request($r))->values()->all());
    }

    /**
     * ORG.9C-1 (ADR 0076 §69), HR-042: APPROVED leave taken in one month, per
     * employment and leave type, in half-day units (from each request's own
     * per-date rows, so a request spanning months counts only its days in
     * this one). Never a reason (HRX-L1), never paid/unpaid. Whole-School.
     *
     * @param  string  $month  Y-m
     * @return array{month: string, rows: list<array{employmentRecordId: string, units: array<string, int>, total: int}>, totals: array<string, int>}
     */
    public function monthSummary(School $school, string $month, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        if ($start === null || $start->format('Y-m') !== $month) {
            throw new InvalidArgumentException('Invalid month.');
        }

        return $this->context->withSchool($school, function () use ($school, $start, $month): array {
            $rows = [];
            $totals = [];
            foreach (LeaveRequestDay::query()->where('leave_request_days.school_id', $school->id)
                ->join('leave_requests', fn ($j) => $j->on('leave_requests.id', '=', 'leave_request_days.leave_request_id')->on('leave_requests.school_id', '=', 'leave_request_days.school_id'))
                ->where('leave_requests.status', 'approved')
                ->whereBetween('leave_request_days.leave_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
                ->groupBy('leave_requests.employment_record_id', 'leave_requests.leave_type_id')
                ->orderBy('leave_requests.employment_record_id')
                ->toBase()->selectRaw('leave_requests.employment_record_id AS employment_record_id, leave_requests.leave_type_id AS leave_type_id, sum(leave_request_days.units)::int AS units')
                ->get() as $row) {
                $id = (string) $row->employment_record_id;
                $type = (string) $row->leave_type_id;
                $rows[$id]['employmentRecordId'] = $id;
                $rows[$id]['units'][$type] = (int) $row->units;
                $rows[$id]['total'] = ($rows[$id]['total'] ?? 0) + (int) $row->units;
                $totals[$type] = ($totals[$type] ?? 0) + (int) $row->units;
            }

            return ['month' => $month, 'rows' => array_values($rows), 'totals' => $totals];
        });
    }

    /** @return array<string, mixed> */
    public function show(School $school, string $leaveRequestId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->context->withSchool($school, fn () => $this->detail($school, LeaveRequest::query()->where('school_id', $school->id)->findOrFail($leaveRequestId)));
    }

    /**
     * The acting manager's direct reports' submitted and approved requests.
     *
     * @return list<array<string, mixed>>
     */
    public function managed(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::APPROVE, $school);
        $reports = $this->reports($school, $actor);
        if ($reports === []) {
            return [];
        }

        return $this->context->withSchool($school, fn () => LeaveRequest::query()->where('school_id', $school->id)
            ->whereIn('employment_record_id', $reports)->whereIn('status', LeaveRequest::LIVE_STATUSES)
            ->orderBy('starts_on')->orderBy('id')->limit(self::LIST_LIMIT)->get()
            ->map(fn (LeaveRequest $r) => self::request($r))->values()->all());
    }

    /** @return array<string, mixed> one direct report's request, or the private 404 */
    public function managedShow(School $school, string $leaveRequestId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::APPROVE, $school);
        $reports = $this->reports($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $leaveRequestId, $reports) {
            $request = LeaveRequest::query()->where('school_id', $school->id)->find($leaveRequestId);
            if ($request === null || ! in_array($request->employment_record_id, $reports, true)) {
                throw new ModelNotFoundException('No query results for the leave request.');
            }

            return $this->detail($school, $request);
        });
    }

    /** @return list<array<string, mixed>> */
    public function closes(School $school, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->context->withSchool($school, fn () => LeaveYearClose::query()->where('school_id', $school->id)->orderByDesc('executed_at')->get()
            ->map(fn (LeaveYearClose $c) => self::close($c))->values()->all());
    }

    /** @return array<string, mixed> */
    public function showClose(School $school, string $yearCloseId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $yearCloseId) {
            $close = LeaveYearClose::query()->where('school_id', $school->id)->findOrFail($yearCloseId);
            $items = LeaveYearCloseItem::query()->where('school_id', $school->id)->where('year_close_id', $close->id)->orderBy('employment_record_id')->orderBy('leave_type_id')->get();
            $reconciliations = LeaveYearCloseReconciliation::query()->where('school_id', $school->id)->whereIn('year_close_item_id', $items->pluck('id'))->orderBy('created_at')->get();

            return self::close($close) + [
                'items' => $items->map(fn (LeaveYearCloseItem $i) => [
                    'id' => $i->id, 'employmentRecordId' => $i->employment_record_id, 'leaveTypeId' => $i->leave_type_id, 'leavePolicyId' => $i->leave_policy_id,
                    'carryForwardAllowed' => $i->carry_forward_allowed, 'carryForwardCapUnits' => $i->carry_forward_cap_units,
                    'closingUnits' => $i->closing_units, 'carriedUnits' => $i->carried_units, 'lapsedUnits' => $i->lapsed_units,
                    'carriedExpiresOn' => $i->carried_expires_on?->toDateString(),
                ])->values()->all(),
                'reconciliations' => $reconciliations->map(fn (LeaveYearCloseReconciliation $r) => [
                    'id' => $r->id, 'yearCloseItemId' => $r->year_close_item_id, 'leaveRequestId' => $r->leave_request_id, 'reversalEntryId' => $r->reversal_entry_id,
                    'units' => $r->units, 'carriedDelta' => $r->carried_delta, 'lapsedDelta' => $r->lapsed_delta, 'createdAt' => $r->created_at->toIso8601String(),
                ])->values()->all(),
            ];
        });
    }

    /** @return list<string> the acting Employee's current direct reports' EmploymentRecord ids; none when the actor is no eligible Employee */
    private function reports(School $school, User $actor): array
    {
        try {
            $acting = $this->acting->resolve($actor, $school);
        } catch (ActingEmployeeUnavailableException) {
            return [];
        }

        return $this->reporting->reportsOf($school, $acting->employeeId, $acting->asOf);
    }

    /** @return array<string, mixed> */
    private function detail(School $school, LeaveRequest $request): array
    {
        return self::request($request) + [
            'days' => LeaveRequestDay::query()->where('school_id', $school->id)->where('leave_request_id', $request->id)->orderBy('leave_date')->get()
                ->map(fn (LeaveRequestDay $d) => [
                    'date' => $d->leave_date->toDateString(), 'portion' => $d->portion, 'units' => $d->units, 'leaveYearId' => $d->leave_year_id,
                    'leavePolicyAssignmentId' => $d->leave_policy_assignment_id, 'leavePolicyId' => $d->leave_policy_id,
                ])->values()->all(),
            'decisions' => LeaveDecision::query()->where('school_id', $school->id)->where('leave_request_id', $request->id)->orderBy('created_at')->get()
                ->map(fn (LeaveDecision $d) => [
                    'id' => $d->id, 'decision' => $d->decision, 'path' => $d->path, 'reasonCode' => $d->reason_code, 'decidedAt' => $d->created_at->toIso8601String(),
                ])->values()->all(),
            'ledgerEntries' => LeaveLedgerEntry::query()->where('school_id', $school->id)->where('leave_request_id', $request->id)->orderBy('created_at')->get()
                ->map(fn (LeaveLedgerEntry $e) => LeaveReadService::entry($e))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function request(LeaveRequest $r): array
    {
        return [
            'id' => $r->id, 'employmentRecordId' => $r->employment_record_id, 'leaveTypeId' => $r->leave_type_id,
            'startsOn' => $r->starts_on->toDateString(), 'startPortion' => $r->start_portion, 'endsOn' => $r->ends_on->toDateString(),
            'endPortion' => $r->end_portion, 'reasonCode' => $r->reason_code, 'submittedUnits' => $r->submitted_units, 'status' => $r->status,
            'submittedAt' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function close(LeaveYearClose $c): array
    {
        return ['id' => $c->id, 'leaveYearId' => $c->leave_year_id, 'nextLeaveYearId' => $c->next_leave_year_id, 'itemCount' => $c->item_count, 'executedAt' => $c->executed_at->toIso8601String()];
    }
}
