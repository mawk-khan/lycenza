<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Reports\SchoolControlPlaneReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.12H (ADR 0080 §13.2): School setup -> Organization report. Read-only:
 * the route's `capability:school.profile.view` floor, then
 * SchoolControlPlaneReport (which re-checks the floor and the School's
 * lifecycle, and each section's own authority). No export, no write.
 */
class OrganizationReportController extends Controller
{
    public function index(Request $request, TenantContext $context, SchoolControlPlaneReport $report, SensitiveReadAssurance $assurance): Response
    {
        return Inertia::render('App/SchoolSetup/OrganizationReport', [
            'report' => $report->forViewer($context->requireSchool(), $context->actor(), $assurance->holds($request)),
        ]);
    }
}
