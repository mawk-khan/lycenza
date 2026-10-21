<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Payments\Application\Access\FinanceCampusAccess;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ORG.7G-1 (ADR 0076 §50, G1-D8): the Finance debtor lookup -- Campus-aware
 * (C6) under finance.charges.view. It finds Students with at least one OPEN
 * Charge whose STORED Campus the reader's scope covers (leavers and moved
 * Students included), by name or Student number, bounded, and returns
 * `id`, `studentNumber` and `displayName` only. It is not a Student
 * directory and confers no Student Profile, Guardian or SIS authority.
 */
class FinanceDebtorController extends Controller
{
    use AuthorizesCampusScope;

    public function search(Request $request, TenantContext $context, FinanceCampusAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('finance.charges.view', $school);

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => array_map(
                fn (StudentLookupCandidate $c) => $c->toArray(),
                $access->debtors($school, $context->actor(), $validated['q'] ?? null),
            ),
        ]);
    }
}
