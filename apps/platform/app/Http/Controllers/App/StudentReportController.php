<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\StudentReports\StudentReportAccess;
use App\Support\StudentReports\StudentReportColumn;
use App\Support\StudentReports\StudentReportDefinition;
use App\Support\StudentReports\StudentReportFilter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.1: the SIS Reports hub and the shared report page -- read only.
 * Authority is StudentReportAccess (per report, from the closed
 * StudentReportRegistry); the controller only validates the declared
 * filters and renders. No export, print, write or audit of the read.
 */
class StudentReportController extends Controller
{
    public function index(TenantContext $context, StudentReportAccess $access): Response
    {
        $school = $context->requireSchool();
        $visible = $access->authorizeHub($access->viewer($context->actor(), $school));

        $categories = [];
        foreach (StudentReportDefinition::CATEGORIES as $key => $label) {
            $reports = array_values(array_filter($visible, fn (StudentReportDefinition $d) => $d->category === $key));
            if ($reports !== []) {
                $categories[] = ['key' => $key, 'label' => $label, 'reports' => array_map(fn (StudentReportDefinition $d) => $d->summary(), $reports)];
            }
        }

        return Inertia::render('App/Students/Reports/Index', ['categories' => $categories]);
    }

    public function show(Request $request, TenantContext $context, StudentReportAccess $access, string $report): Response
    {
        $school = $context->requireSchool();
        $viewer = $access->viewer($context->actor(), $school);
        $read = $access->authorizeReport($viewer, $report);
        $definition = $read->definition();

        $options = $read->options($school, $viewer);
        // Only the declared filters are read; anything else in the query string is ignored.
        $validated = $request->validate(StudentReportFilter::rules($definition->filters, $options, array_keys($definition->sorts)));
        $filters = [];
        foreach ($definition->filters as $key) {
            $filters[$key] = isset($validated[$key]) ? (string) $validated[$key] : '';
        }
        $sort = $validated['sort'] ?? $definition->defaultSort();

        $rows = $read->page($school, $viewer, $filters, $sort, (int) ($validated['page'] ?? 1))->withQueryString();
        $filtered = array_filter($filters, fn (string $v) => $v !== '') !== [];

        return Inertia::render('App/Students/Reports/Show', [
            'report' => $definition->summary(),
            'filters' => StudentReportFilter::present($definition->filters, $options),
            'values' => [...$filters, 'sort' => $sort === $definition->defaultSort() ? '' : $sort],
            'columns' => array_values(array_map(
                fn (StudentReportColumn $c) => $c->toArray(),
                array_filter($definition->columns, fn (StudentReportColumn $c) => $viewer->maySee($c, $definition->scope)),
            )),
            'sorts' => array_map(fn (string $k, string $l) => ['value' => $k, 'label' => $l], array_keys($definition->sorts), $definition->sorts),
            'rows' => $rows,
            // "Nothing recorded yet" versus "nothing matches these filters".
            'hasAny' => $filtered ? $read->hasAny($school, $viewer) : $rows->total() > 0,
            // SIS 11.4 (D-11): governed export/print, only when this viewer may (re-checked on the request).
            'canExport' => $access->mayExport($viewer, $report),
        ]);
    }
}
