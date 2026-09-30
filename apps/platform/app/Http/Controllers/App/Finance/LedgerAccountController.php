<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\LedgerAccountAdministrationService;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerReadService;
use App\Domain\Finance\Http\TranslatesLedgerAccountErrors;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Chart of Accounts page. Reads need finance.ledger.view; since FEE.1
 * (ADR 0062 §6, K1) a holder of finance.accounts.manage can also create an
 * account and activate/deactivate one. There is no delete and no type
 * change.
 */
class LedgerAccountController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput, TranslatesLedgerAccountErrors;

    public function index(TenantContext $context, LedgerReadService $service, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.ledger.view', $school);

        $accounts = $service->listAccounts($school, $context->actor());

        return Inertia::render('App/Finance/Ledger/Accounts', [
            'accounts' => $accounts->map(fn (LedgerAccountSummary $a) => [
                'id' => $a->ledgerAccountId,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
                'isSystem' => $a->isSystem,
                'status' => $a->status,
            ])->all(),
            'types' => LedgerAccountAdministrationService::TYPES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'finance.accounts.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, LedgerAccountAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.accounts.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', $this->caseInsensitiveUniqueCode('ledger_accounts', $school)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(LedgerAccountAdministrationService::TYPES)],
        ]);

        $this->translatingLedgerAccountErrors(fn () => $service->create($school, $validated, $context->actor()));

        return redirect('/app/finance/ledger-accounts');
    }

    public function updateStatus(Request $request, TenantContext $context, LedgerAccountAdministrationService $service, string $ledgerAccount): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.accounts.manage', $school);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(LedgerAccountAdministrationService::STATUSES)],
        ]);

        try {
            $service->changeStatus($school, $ledgerAccount, $validated['status'], $context->actor());
        } catch (LedgerAccountNotFoundException) {
            throw new NotFoundHttpException;
        }

        return redirect('/app/finance/ledger-accounts');
    }
}
