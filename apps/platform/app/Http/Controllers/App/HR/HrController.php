<?php

namespace App\Http\Controllers\App\HR;

use App\Http\Controllers\Controller;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 8A closure correction (item 2) -- the HR workspace hub, mirroring
 * `App\Http\Controllers\App\Finance\FinanceController::index()`'s
 * established hub-page pattern. No business data of its own; every
 * link's real capability check happens again at its own destination
 * (root CLAUDE.md rule 6 -- this page's `can` map is UX only).
 */
class HrController extends Controller
{
    public function index(TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $user = $context->actor();

        return Inertia::render('App/HR/Index', [
            'can' => [
                'viewEmployees' => $capabilities->canInSchool($user, 'hr.employees.view', $school),
                'manageEmployees' => $capabilities->canInSchool($user, 'hr.employees.manage', $school),
                'viewDepartments' => $capabilities->canInSchool($user, 'hr.departments.view', $school),
                'viewPositions' => $capabilities->canInSchool($user, 'hr.positions.view', $school),
                'viewCategories' => $capabilities->canInSchool($user, 'hr.categories.view', $school),
            ],
        ]);
    }
}
