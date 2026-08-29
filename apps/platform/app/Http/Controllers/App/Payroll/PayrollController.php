<?php

namespace App\Http\Controllers\App\Payroll;

use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.9 -- the Payroll workspace hub, mirroring
 * `App\Http\Controllers\App\Finance\FinanceController::index()`'s
 * established hub-page pattern exactly: lists the sub-areas this
 * checkpoint implements, each link gated by its own capability. No
 * business data of its own -- every capability check here is UX only
 * (root CLAUDE.md rule 6); each linked destination re-checks
 * authorization server-side regardless of what this map says.
 */
class PayrollController extends Controller
{
    public function index(TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        return Inertia::render('App/Payroll/Index', [
            'can' => [
                'viewStructures' => $capabilities->canInSchool($user, 'payroll.structures.view', $school),
                'manageStructures' => $capabilities->canInSchool($user, 'payroll.structures.manage', $school),
                'viewCompensation' => $capabilities->canInSchool($user, 'payroll.compensation.view', $school),
                'manageAccounting' => $capabilities->canInSchool($user, 'payroll.accounting.manage', $school),
                'managePeriods' => $capabilities->canInSchool($user, 'payroll.periods.manage', $school),
                'viewRuns' => $capabilities->canInSchool($user, 'payroll.runs.view', $school),
            ],
        ]);
    }
}
