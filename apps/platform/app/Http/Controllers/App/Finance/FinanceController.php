<?php

namespace App\Http\Controllers\App\Finance;

use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0G.7: the Finance workspace hub -- lists the Ledger/Charges/
 * Payments areas this checkpoint implements, each link gated by its
 * own capability, mirroring `App\Http\Controllers\App\SchoolSetupController::index()`'s
 * established hub-page pattern. No business data of its own.
 */
class FinanceController extends Controller
{
    public function index(TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        return Inertia::render('App/Finance/Index', [
            'can' => [
                'viewLedger' => $capabilities->canInSchool($user, 'finance.ledger.view', $school),
                'postLedger' => $capabilities->canInSchool($user, 'finance.ledger.post', $school),
                'viewCharges' => $capabilities->canInSchool($user, 'finance.charges.view', $school),
                'manageCharges' => $capabilities->canInSchool($user, 'finance.charges.manage', $school),
                'viewPayments' => $capabilities->canInSchool($user, 'finance.payments.view', $school),
            ],
        ]);
    }
}
