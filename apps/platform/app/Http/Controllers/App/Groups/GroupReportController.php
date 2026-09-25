<?php

namespace App\Http\Controllers\App\Groups;

use App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService;
use App\Domain\Platform\Application\Groups\Reporting\GroupReportFailedException;
use App\Http\Controllers\Controller;
use App\Models\SchoolGroup;
use App\Support\Auth\Mfa\Exceptions\MfaException;
use App\Support\Auth\RendersAuthJsonErrors;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0N.11 (ADR 0048): the Group Curriculum Coverage report page --
 * context-neutral (no `school-context`), read-only, no filters, no export,
 * no drill-through. All authorization, MFA assurance and the per-School
 * reads are GroupCurriculumCoverageReportService's; a Group the actor
 * cannot report on is the Group view's non-disclosing 404.
 */
class GroupReportController extends Controller
{
    use RendersAuthJsonErrors;

    public function curriculumCoverage(Request $request, SchoolGroup $schoolGroup, GroupCurriculumCoverageReportService $reports): Response
    {
        try {
            $report = $reports->generate($request, $request->user(), $schoolGroup);
        } catch (MfaException $e) {
            if ($request->expectsJson() && $request->header('X-Inertia') !== 'true') {
                return $this->jsonError($e);
            }

            return Inertia::render('App/Platform/MfaRequired', ['code' => $e->errorCode()])
                ->toResponse($request)->setStatusCode($e->getStatusCode());
        } catch (GroupReportFailedException $e) {
            return Inertia::render('App/Groups/CurriculumCoverageReport', [
                'group' => ['id' => $schoolGroup->id, 'name' => $schoolGroup->name],
                'report' => null,
            ])->toResponse($request)->setStatusCode($e->getStatusCode());
        }

        return Inertia::render('App/Groups/CurriculumCoverageReport', [
            'group' => $report['group'],
            'report' => $report,
        ])->toResponse($request);
    }
}
