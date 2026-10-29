<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Integrations\IntegrationHubReadService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.12G (ADR 0080 §12): School setup -> Integrations & devices. Read-only:
 * the route's `capability:school.settings.view` floor, then
 * IntegrationHubReadService (which re-checks the floor and the School's
 * lifecycle). Nothing here configures, tests or contacts an integration;
 * each is managed in its owning module, or by Lycenza.
 */
class IntegrationHubController extends Controller
{
    public function index(Request $request, TenantContext $context, IntegrationHubReadService $hub, SensitiveReadAssurance $assurance): Response
    {
        // A family whose own page needs current MFA assurance (API clients)
        // shows its status here only under the same assurance.
        return Inertia::render('App/SchoolSetup/Integrations', [
            'hub' => $hub->forViewer($context->requireSchool(), $context->actor(), $assurance->holds($request)),
        ]);
    }
}
