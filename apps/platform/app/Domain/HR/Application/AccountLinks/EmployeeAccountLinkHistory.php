<?php

namespace App\Domain\HR\Application\AccountLinks;

use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C7 (OD-HR-25): the history of an Employee's account links, for whole-School
 * `hr.employees.manage` only. Names and dates, never anything else about the account.
 * `state` keeps what is known apart: current, ended, corrected. `evidence` says
 * whether the link was recorded as it happened, or only known at cutover, which
 * says nothing about before.
 */
final class EmployeeAccountLinkHistory
{
    public function __construct(private readonly HrEmployeeAccess $access, private readonly HrSelfAdministrationGuard $selfAdministration) {}

    /** @return list<array<string, mixed>> */
    public function forEmployee(Employee $employee, User $actor): array
    {
        $this->access->requireWholeSchool($actor, 'hr.employees.manage', $employee->school);

        $mayCorrect = ! $this->selfAdministration->hasUncorrectedLink($actor, $employee->school_id, $employee->id);

        return EmployeeAccountLink::query()->where('school_id', $employee->school_id)->where('employee_id', $employee->id)
            ->orderByDesc('linked_at')->orderByDesc('id')->get()
            ->map(fn (EmployeeAccountLink $link): array => [
                'id' => $link->id,
                'account' => DB::table('users')->where('id', $link->user_id)->value('name'),
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
                'mayCorrect' => $mayCorrect && $link->voided_at === null,
            ])->all();
    }
}
