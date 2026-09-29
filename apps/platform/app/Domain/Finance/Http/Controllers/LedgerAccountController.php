<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Fees\Http\TranslatesFeeSetupErrors;
use App\Domain\Finance\Application\LedgerAccountAdministrationService;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    use NormalizesCodeInput, TranslatesFeeSetupErrors;

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
    /**
     * FEE.1 (ADR 0062 §6, K1): create an INR ledger account. Requires
     * finance.accounts.manage (route middleware + service).
     */
    public function store(Request $request, School $school, LedgerAccountAdministrationService $service): JsonResponse
    {
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', $this->caseInsensitiveUniqueCode('ledger_accounts', $school)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(LedgerAccountAdministrationService::TYPES)],
        ]);

        $account = $this->translatingFeeSetupErrors(fn () => $service->create($school, $validated, $request->user()));

        return response()->json(['data' => $this->present($account)], 201);
    }

    /** FEE.1 (K1): activate or deactivate. Never a delete, never a type change. */
    public function update(Request $request, School $school, string $ledgerAccount, LedgerAccountAdministrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(LedgerAccountAdministrationService::STATUSES)],
        ]);

        $account = $this->translatingFeeSetupErrors(
            fn () => $service->changeStatus($school, $ledgerAccount, $validated['status'], $request->user()),
        );

        return response()->json(['data' => $this->present($account)]);
    }

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
