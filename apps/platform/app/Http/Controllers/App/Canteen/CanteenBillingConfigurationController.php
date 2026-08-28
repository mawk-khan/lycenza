<?php

namespace App\Http\Controllers\App\Canteen;

use App\Domain\Canteen\Application\CanteenBillingConfigurationService;
use App\Domain\Canteen\Application\Exceptions\CanteenBillingAccountInvalidException;
use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10F -- session-authenticated Inertia page for the School-wide
 * Canteen billing configuration singleton. Mirrors
 * App\Http\Controllers\App\Finance\ChargeController's shape (thin,
 * capability-checked in-controller, cross-module Finance read for
 * display -- see the API-layer
 * App\Domain\Canteen\Http\Controllers\CanteenBillingConfigurationController's
 * own docblock for why this reads LedgerAccount directly).
 */
class CanteenBillingConfigurationController extends Controller
{
    use AuthorizesCapability;

    public function edit(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.settings.view', $school);

        $config = CanteenBillingConfiguration::query()->where('school_id', $school->id)->first();

        $accounts = LedgerAccount::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->whereIn('type', ['asset', 'income'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        return Inertia::render('App/Canteen/Settings/Edit', [
            'configuration' => $config ? [
                'receivableLedgerAccountId' => $config->receivable_ledger_account_id,
                'revenueLedgerAccountId' => $config->revenue_ledger_account_id,
            ] : null,
            'ledgerAccounts' => $accounts->map(fn (LedgerAccount $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
            ])->all(),
            'canManage' => $this->authorizeCapabilitySilently($context, $school),
        ]);
    }

    public function update(Request $request, TenantContext $context, CanteenBillingConfigurationService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.settings.manage', $school);

        $validated = $request->validate([
            'receivable_ledger_account_id' => ['required', 'uuid'],
            'revenue_ledger_account_id' => ['required', 'uuid'],
        ]);

        try {
            $service->configure($school, $validated['receivable_ledger_account_id'], $validated['revenue_ledger_account_id'], $context->actor());
        } catch (CanteenBillingAccountInvalidException $e) {
            throw ValidationException::withMessages(['receivable_ledger_account_id' => [$e->getMessage()]]);
        }

        return redirect('/app/canteen-settings')->with('flash', 'Canteen billing configuration saved.');
    }

    private function authorizeCapabilitySilently(TenantContext $context, School $school): bool
    {
        return app(CapabilityResolver::class)->canInSchool($context->actor(), 'canteen.settings.manage', $school);
    }
}
