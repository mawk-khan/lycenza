<?php

namespace App\Domain\Library\Application;

use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Support\Str;

/**
 * SIS.EXP-1: Library's own read of ONE Student's open loans, for the staff
 * Student profile (presentation only; SIS never owns or copies them).
 *
 * - Through LibraryCirculationAccess under the caller's
 *   `library.circulation.view` CampusScope: a loan is reached by its own
 *   frozen Campus, so out-of-scope loans are not counted.
 * - Two numbers only: active loans and, of those, overdue (due before now,
 *   the LibraryLoan::isOverdue() rule). No fine is read or computed.
 * - Bounded: one aggregate query.
 */
final class LibraryStudentSummaryReader
{
    public function __construct(private readonly LibraryCirculationAccess $access) {}

    /** @return array{activeLoans: int, overdueLoans: int} */
    public function openLoansFor(CampusScope $scope, string $studentId): array
    {
        if ($scope->isNone() || ! Str::isUuid($studentId)) {
            return ['activeLoans' => 0, 'overdueLoans' => 0];
        }

        $row = $this->access->loans($scope)
            ->toBase()
            ->where('library_loans.student_id', $studentId)
            ->where('library_loans.status', 'active')
            ->selectRaw('count(*) as active_loans, count(*) filter (where library_loans.due_at < ?) as overdue_loans', [now()])
            ->first();

        return ['activeLoans' => (int) ($row->active_loans ?? 0), 'overdueLoans' => (int) ($row->overdue_loans ?? 0)];
    }
}
