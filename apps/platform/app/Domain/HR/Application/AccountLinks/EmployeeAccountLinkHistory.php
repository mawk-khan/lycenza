<?php

namespace App\Domain\HR\Application\AccountLinks;

use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmployeeAccountLinkCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C7 (OD-HR-25): the history of an Employee's account links, for whole-School
 * `hr.employees.manage` only. Names and dates, never anything else about the account.
 * `state` keeps what is known apart: current, ended, corrected. `evidence` says
 * whether the link was recorded as it happened, or only known at cutover, which
 * says nothing about before.
 *
 * HR.R1-C8: each link carries its open correction request, with what the reviewer is
 * deciding on. The two `may*` answers are for the page: the service decides again
 * under its locks.
 */
final class EmployeeAccountLinkHistory
{
    public function __construct(private readonly HrEmployeeAccess $access, private readonly EmployeeAccountLinkCorrectionService $corrections) {}

    /** @return list<array<string, mixed>> */
    public function forEmployee(Employee $employee, User $actor): array
    {
        $school = $employee->school;
        $this->access->requireWholeSchool($actor, 'hr.employees.manage', $school);
        $qualified = $this->corrections->qualifies($actor, $school, $employee->id);
        $open = EmployeeAccountLinkCorrection::query()->where('school_id', $employee->school_id)->where('employee_id', $employee->id)
            ->where('status', EmployeeAccountLinkCorrection::REQUESTED)->get()->keyBy('account_link_id');

        return EmployeeAccountLink::query()->where('school_id', $employee->school_id)->where('employee_id', $employee->id)
            ->orderByDesc('linked_at')->orderByDesc('id')->get()
            ->map(function (EmployeeAccountLink $link) use ($school, $actor, $qualified, $open): array {
                $request = $open->get($link->id);

                return [
                    'id' => $link->id,
                    'account' => $this->name($link->user_id),
                    'state' => match (true) {
                        $link->voided_at !== null => 'corrected',
                        $link->ended_at !== null => 'ended',
                        default => 'current',
                    },
                    'evidence' => $link->origin,
                    'linkedAt' => $link->linked_at->toIso8601String(),
                    'endedAt' => $link->ended_at?->toIso8601String(),
                    'correctedAt' => $link->voided_at?->toIso8601String(),
                    'correctionReason' => $link->void_reason === null ? null : EmployeeAccountLinkVoidReason::from($link->void_reason)->label(),
                    'mayRequestCorrection' => $qualified && $link->voided_at === null && $request === null,
                    'correctionRequest' => $request === null ? null : [
                        'id' => $request->id,
                        'reason' => EmployeeAccountLinkVoidReason::from($request->reason)->label(),
                        'requestedBy' => $this->name($request->requested_by_user_id),
                        'requestedAt' => $request->requested_at->toIso8601String(),
                        'evidenceVersion' => $this->corrections->version($request->school_id, $link->id),
                        'stale' => ! hash_equals($request->evidence_version, $this->corrections->version($request->school_id, $link->id)),
                        'mayDecide' => $this->corrections->mayDecide($actor, $school, $request),
                    ],
                ];
            })->all();
    }

    private function name(string $userId): ?string
    {
        return DB::table('users')->where('id', $userId)->value('name');
    }
}
