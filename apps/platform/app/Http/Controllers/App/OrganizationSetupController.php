<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Setup\OrganizationSetupCenter;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.12B (ADR 0080 §7): Organization -> Setup & readiness. Read-only: the
 * route's `capability:school.profile.view` floor, then
 * OrganizationSetupCenter (which re-checks the floor and the School's
 * lifecycle through OrganizationSetupReadService). Every change happens in
 * the owning module's own page.
 */
class OrganizationSetupController extends Controller
{
    public function index(TenantContext $context, OrganizationSetupCenter $center): Response
    {
        return Inertia::render('App/SchoolSetup/Readiness', [
            'center' => $center->forViewer($context->requireSchool(), $context->actor()),
        ]);
    }
}
