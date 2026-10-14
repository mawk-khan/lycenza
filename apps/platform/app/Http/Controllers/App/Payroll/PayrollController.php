<?php

namespace App\Http\Controllers\App\Payroll;

use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\StaffNavigation;
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
    use AuthorizesCapability;

    public function index(TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        // SWC.5 (ADR 0073 section 20.3, F-1): the hub opens only for an actor
        // it has at least one link for -- its navigation entry's requirement.
        $this->authorizeAnyCapability(StaffNavigation::hubRequirement('payroll'), $school);
        $user = $context->actor();

        return Inertia::render('App/Payroll/Index', [
            'can' => [
                'viewStructures' => $capabilities->canInSchool($user, 'payroll.structures.view', $school),
                'manageStructures' => $capabilities->canInSchool($user, 'payroll.structures.manage', $school),
                'viewCompensation' => $capabilities->canInSchool($user, 'payroll.compensation.view', $school),
                'manageAccounting' => $capabilities->canInSchool($user, 'payroll.accounting.manage', $school),
                'managePeriods' => $capabilities->canInSchool($user, 'payroll.periods.manage', $school),
                'viewRuns' => $capabilities->canInSchool($user, 'payroll.runs.view', $school),
                // Checkpoint 9.6I -- additive: the Statutory Payroll
                // workspace re-checks every capability server-side
                // itself, this is UX-only like every other flag here.
                'viewStatutory' => $capabilities->canInSchool($user, 'payroll.statutory.view', $school),
            ],
        ]);
    }
}
