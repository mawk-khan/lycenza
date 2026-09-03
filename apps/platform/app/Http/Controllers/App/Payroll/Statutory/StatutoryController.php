<?php

namespace App\Http\Controllers\App\Payroll\Statutory;

use App\Domain\Payroll\Statutory\Application\Admin\StatutoryRuleStatusReadService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkpoint 9.6I (Section 4 "Administrative UI") -- the Statutory
 * Payroll workspace hub, mirroring `App\Http\Controllers\App\Payroll\PayrollController::index()`'s
 * established hub-page pattern: capability map is UX-only, every
 * linked destination re-checks authorization server-side.
 */
class StatutoryController extends Controller
{
    public function index(TenantContext $context, CapabilityResolver $capabilities, StatutoryRuleStatusReadService $ruleStatus): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        $can = [
            'view' => $capabilities->canInSchool($user, 'payroll.statutory.view', $school),
            'manage' => $capabilities->canInSchool($user, 'payroll.statutory.manage', $school),
            'viewIdentifiers' => $capabilities->canInSchool($user, 'payroll.statutory.identifiers.view', $school),
            'manageIdentifiers' => $capabilities->canInSchool($user, 'payroll.statutory.identifiers.manage', $school),
            'generateExports' => $capabilities->canInSchool($user, 'payroll.statutory.exports.generate', $school),
        ];

        $versions = $can['view'] ? $ruleStatus->activeRuleVersions($school, $user) : null;

        return Inertia::render('App/Payroll/Statutory/Index', [
            'can' => $can,
            'ruleStatus' => $versions === null ? null : [
                'pf' => $versions['pf'] === null ? null : [
                    'effectiveFrom' => $versions['pf']->effective_from->toDateString(),
                    'legalReference' => $versions['pf']->legal_reference,
                ],
                'esi' => $versions['esi'] === null ? null : [
                    'effectiveFrom' => $versions['esi']->effective_from->toDateString(),
                    'legalReference' => $versions['esi']->legal_reference,
                    'disabilityThresholdStatus' => 'DEFERRED — ADDITIONAL LEGAL CLARIFICATION REQUIRED',
                ],
            ],
        ]);
    }
}
