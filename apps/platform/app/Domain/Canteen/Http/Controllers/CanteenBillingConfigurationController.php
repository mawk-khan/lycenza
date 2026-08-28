<?php

namespace App\Domain\Canteen\Http\Controllers;

use App\Domain\Canteen\Application\CanteenBillingConfigurationService;
use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 10F -- the School-wide singleton Canteen billing configuration
 * (which ledger_accounts fulfillment posts against). `ledgerAccounts()`
 * is a narrow, read-only lookup for the settings UI's account pickers
 * -- reads App\Domain\Finance\Infrastructure\LedgerAccount directly
 * rather than the existing /ledger-accounts endpoint (that endpoint is
 * gated by `finance.ledger.view`, a DIFFERENT capability than
 * `canteen.settings.manage`), mirroring the established cross-module
 * "read-for-display" precedent
 * (App\Http\Controllers\App\Finance\ChargeController::searchStudents()'s
 * docblock).
 */
class CanteenBillingConfigurationController extends Controller
{
    use AuthorizesCapability;

    public function show(School $school): JsonResponse
    {
        $this->authorizeCapability('canteen.settings.view', $school);

        $config = CanteenBillingConfiguration::query()->where('school_id', $school->id)->first();

        return response()->json(['data' => $config ? $this->present($config) : null]);
    }

    public function update(Request $request, School $school, CanteenBillingConfigurationService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.settings.manage', $school);

        $validated = $request->validate([
            'receivable_ledger_account_id' => ['required', 'uuid'],
            'revenue_ledger_account_id' => ['required', 'uuid'],
        ]);

        $config = $service->configure($school, $validated['receivable_ledger_account_id'], $validated['revenue_ledger_account_id'], $request->user());

        return response()->json(['data' => $this->present($config)]);
    }

    public function ledgerAccounts(School $school): JsonResponse
    {
        $this->authorizeCapability('canteen.settings.manage', $school);

        $accounts = LedgerAccount::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->whereIn('type', ['asset', 'income'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        return response()->json([
            'data' => $accounts->map(fn (LedgerAccount $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CanteenBillingConfiguration $config): array
    {
        return [
            'id' => $config->id,
            'receivableLedgerAccountId' => $config->receivable_ledger_account_id,
            'revenueLedgerAccountId' => $config->revenue_ledger_account_id,
            'currency' => $config->currency,
        ];
    }
}
