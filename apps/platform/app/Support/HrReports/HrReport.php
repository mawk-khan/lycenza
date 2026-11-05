<?php

namespace App\Support\HrReports;

use App\Models\School;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * HR.14 (owner decisions 2026-11-05): an HR report served by the shared HR
 * report page -- a read-only projection owned by the module whose facts it
 * reads (HR, Leave, Staff Attendance, WorkforceTasks), through that module's
 * own read path. It never writes, caches, stores a snapshot, exports or
 * audits a read.
 *
 * The caller (HrReportAccess) has already authorized the report's authority;
 * the read model still applies everything that narrows rows or columns for
 * this viewer (HrReportViewer).
 */
interface HrReport
{
    public function definition(): HrReportDefinition;

    /**
     * Options for the declared `choice` and `entity` filters, from their
     * owners, cut to what this viewer may see.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function options(School $school, HrReportViewer $viewer): array;

    /**
     * Default values for filters the report needs a value for (e.g. today's
     * date, the current Leave Year). Never a business value.
     *
     * @param  array<string, list<array<string, string>>>  $options
     * @return array<string, string>
     */
    public function defaults(School $school, array $options): array;

    /**
     * One bounded page: a fixed number of queries per page, whatever the
     * School's size. Each row maps column keys to values, plus `key`.
     *
     * @param  array<string, string>  $filters  validated, declared filters only (defaults applied)
     * @param  list<string>  $columns  the visible, selected column keys
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator;

    /** Whether the report has any row at all for this viewer (to tell "nothing yet" from "no match"). */
    public function hasAny(School $school, HrReportViewer $viewer): bool;
}
