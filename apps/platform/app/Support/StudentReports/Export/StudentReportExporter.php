<?php

namespace App\Support\StudentReports\Export;

use App\Models\School;
use App\Support\StudentReports\StudentReportViewer;

/**
 * SIS 11.4 (D-11): the export side of ONE registered report, implemented
 * next to that report's read model and built on the SAME query (scope,
 * filters and sort), never a second, drifting export query.
 *
 * The caller (StudentReportExportService) has authorized the report, the
 * export capability and the columns, and validated the filters with
 * filterRules(). Everything that narrows rows for the viewer is applied
 * here exactly as on the screen.
 */
interface StudentReportExporter
{
    public function exportDefinition(): StudentReportExportDefinition;

    /**
     * The report page's own filter and sort validation (shared, not copied).
     *
     * @return array<string, mixed>
     */
    public function filterRules(School $school, StudentReportViewer $viewer): array;

    /** @param  array<string, mixed>  $filters  validated */
    public function exportCount(School $school, StudentReportViewer $viewer, array $filters): int;

    /**
     * Every matching row, in the report's sort, as export column key =>
     * display value -- streamed in bounded chunks.
     *
     * @param  array<string, mixed>  $filters  validated
     * @return iterable<array<string, string|int|null>>
     */
    public function exportRows(School $school, StudentReportViewer $viewer, array $filters): iterable;
}
