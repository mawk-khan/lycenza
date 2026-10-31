<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\StudentReports\Export\StudentReportExportDefinition;
use App\Support\StudentReports\Export\StudentReportExportService;
use App\Support\StudentReports\StudentReportAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SIS 11.4 (D-11): the governed SIS export (CSV) and print view. Authority,
 * validation, columns, row ceiling and audit are StudentReportExportService's
 * (over StudentReportAccess::authorizeExport); the controller only chooses
 * the format. The CSV is a POST (CSRF-protected, it records an audit event);
 * the print view is a GET page under exactly the same rules.
 */
class StudentReportExportController extends Controller
{
    public function export(Request $request, TenantContext $context, StudentReportAccess $access, StudentReportExportService $exports, string $report): StreamedResponse
    {
        $school = $context->requireSchool();
        $viewer = $access->viewer($context->actor(), $school);
        $request->validate(['format' => ['required', 'in:'.StudentReportExportDefinition::CSV]]);

        $plan = $exports->prepare($school, $viewer, $report, StudentReportExportDefinition::CSV, $request->all());
        $exports->audit($school, $viewer, $plan, StudentReportExportDefinition::CSV);

        return $exports->csv($school, $viewer, $plan);
    }

    public function print(Request $request, TenantContext $context, StudentReportAccess $access, StudentReportExportService $exports, string $report): Response
    {
        $school = $context->requireSchool();
        $viewer = $access->viewer($context->actor(), $school);

        $plan = $exports->prepare($school, $viewer, $report, StudentReportExportDefinition::PRINT, $request->query());
        $exports->audit($school, $viewer, $plan, StudentReportExportDefinition::PRINT);

        return Inertia::render('App/Students/Reports/Print', $exports->printPayload($school, $viewer, $plan));
    }
}
