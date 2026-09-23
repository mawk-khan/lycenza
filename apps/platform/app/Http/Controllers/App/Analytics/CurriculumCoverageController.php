<?php

namespace App\Http\Controllers\App\Analytics;

use App\Domain\Analytics\Application\AnalyticsReadGate;
use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0L.2-1 -- the session-authenticated Curriculum Coverage
 * Analytics page. Thin: validates the one supported filter and hands
 * everything else -- `analytics.view`, the cohort policy, the tenant
 * context, audit -- to AnalyticsReadGate. The School is always the
 * trusted session context (TenantContext::requireSchool()), never a
 * request parameter. Read-only; no export (not in this checkpoint).
 */
class CurriculumCoverageController extends Controller
{
    public function index(Request $request, TenantContext $context, AnalyticsReadGate $gate, CurriculumCoverageReadModel $readModel): Response
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
        ]);

        return Inertia::render('App/Analytics/CurriculumCoverage', [
            'report' => $gate->read($readModel, $school, $request->user(), $validated),
        ]);
    }
}
