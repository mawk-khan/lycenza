<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;

/**
 * Phase 0G.6: thin HTTP transport over `LedgerReadService` -- no
 * business logic, no direct `LedgerAccount` Eloquent access. The
 * `capability:finance.ledger.view` route middleware (routes/api.php)
 * and `LedgerReadService::listAccounts()`'s own internal
 * `authorizeCapabilityFor()` check both gate this endpoint (the same
 * harmless double-check convention `AcademicYearController` already
 * establishes) -- a denied caller never reaches the query.
 */
class LedgerAccountController extends Controller
{
    public function index(School $school, LedgerReadService $service): JsonResponse
    {
        $accounts = $service->listAccounts($school, request()->user());

        return response()->json([
            'data' => $accounts->map(fn (LedgerAccountSummary $a) => $this->present($a))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LedgerAccountSummary $account): array
    {
        return [
            'id' => $account->ledgerAccountId,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'currency' => $account->currency,
            'isSystem' => $account->isSystem,
            'status' => $account->status,
            'createdAt' => $account->createdAt->toIso8601String(),
            'updatedAt' => $account->updatedAt->toIso8601String(),
        ];
    }
}
