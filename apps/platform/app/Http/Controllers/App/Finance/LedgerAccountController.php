<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerReadService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0G.7: session-authenticated Inertia page for the Ledger
 * Account directory -- the same convention every other App/Finance
 * controller in this namespace follows (NOT the Bearer-token JSON API
 * under /api/v1 that 0G.6 built for Flutter/external consumers; see
 * that checkpoint's LedgerAccountController for the parallel surface).
 * Delegates to `LedgerReadService::listAccounts()` -- the exact same
 * Application-layer service the JSON API controller calls -- never a
 * raw `LedgerAccount` Eloquent query. Read-only: no account CRUD
 * exists in this checkpoint's scope (FINANCE.md 0G.7 rule 53).
 */
class LedgerAccountController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, LedgerReadService $service): Response
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
        ]);
    }
}
