<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\LeaveRequestDay;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * HRX.3 (ADR 0065 §8, §24.4): Leave's read contract for Staff Attendance --
 * which halves of an employment's dates are covered by APPROVED leave.
 *
 * Coverage is the approval's immutable chargeable-day evidence
 * (`leave_request_days`, §22.6), never a recalculation; a cancelled request
 * covers nothing, so its dates fall back to whatever attendance evidence lies
 * underneath. Each covered half carries only the operational facts: the
 * request id, the leave type's id, code and name, and (HRX.5) whether the
 * type is paid. Never the reason, the
 * decisions, the approver, the policy or a balance.
 *
 * It authorizes nothing: the consumer authorizes its own read, and decides
 * whether to disclose the identifiers (ADR 0065 §24.11).
 */
class LeaveCoverageReader
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Employment => date => half (1 = first, 2 = second) => the approved request covering it.
     *
     * @param  list<string>  $employmentRecordIds
     * @return array<string, array<string, array<int, array{leaveRequestId: string, leaveTypeId: string, leaveTypeCode: string, leaveTypeName: string, isPaid: bool}>>>
     */
    public function approvedCoverage(School $school, array $employmentRecordIds, string $from, string $to): array
    {
        if ($employmentRecordIds === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $employmentRecordIds, $from, $to): array {
            $requests = LeaveRequest::query()->where('school_id', $school->id)->where('status', 'approved')
                ->whereIn('employment_record_id', $employmentRecordIds)->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
                ->get(['id', 'employment_record_id', 'leave_type_id'])->keyBy('id');
            if ($requests->isEmpty()) {
                return [];
            }
            $types = LeaveType::query()->where('school_id', $school->id)->whereIn('id', $requests->pluck('leave_type_id')->unique()->values())
                ->get(['id', 'code', 'name', 'is_paid'])->keyBy('id');

            $coverage = [];
            LeaveRequestDay::query()->where('school_id', $school->id)->whereIn('leave_request_id', $requests->keys())
                ->whereBetween('leave_date', [$from, $to])->orderBy('leave_date')->get()
                ->each(function (LeaveRequestDay $day) use ($requests, $types, &$coverage): void {
                    $request = $requests[$day->leave_request_id];
                    $type = $types[$request->leave_type_id];
                    foreach (DayPortion::from($day->portion)->halves() as $half) {
                        $coverage[$request->employment_record_id][$day->leave_date->toDateString()][$half] = [
                            'leaveRequestId' => $request->id, 'leaveTypeId' => $type->id, 'leaveTypeCode' => $type->code, 'leaveTypeName' => $type->name,
                            // HRX.5: frozen once the type is used (ADR 0065 §22.5), so it is the classification the approval was made under.
                            'isPaid' => (bool) $type->is_paid,
                        ];
                    }
                });

            return $coverage;
        });
    }

    /**
     * HR.14: the SQL form of approvedCoverage() for one half -- narrows
     * $query to rows whose half $half of the employment ($employmentColumn)
     * on the date ($dateColumn) is NOT covered by approved leave. The same
     * evidence (approved requests' immutable `leave_request_days`), so a
     * report filtering in SQL agrees with the composed register exactly.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrainHalfUncovered(Builder|QueryBuilder $query, string $employmentColumn, string $dateColumn, int $half): Builder|QueryBuilder
    {
        $portions = array_values(array_map(fn (DayPortion $p) => $p->value, array_filter(DayPortion::cases(), fn (DayPortion $p) => in_array($half, $p->halves(), true))));

        return $query->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('leave_request_days as lrd')
            ->join('leave_requests as lr', fn ($j) => $j->on('lr.id', '=', 'lrd.leave_request_id')->on('lr.school_id', '=', 'lrd.school_id'))
            ->where('lr.status', 'approved')
            ->whereColumn('lr.employment_record_id', $employmentColumn)
            ->whereColumn('lrd.leave_date', $dateColumn)
            ->whereIn('lrd.portion', $portions));
    }
}
