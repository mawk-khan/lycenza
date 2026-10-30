<?php

namespace App\Support\StudentReports;

use App\Models\School;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * SIS 11.1: a Student report served by the shared report page -- a
 * read-only projection owned by the module whose facts it reads (the Data
 * Health check pattern: the contract lives here, the read model in its
 * domain). It never writes, caches, stores a snapshot or audits a read, and
 * it reads another module's facts only through that module's approved read
 * path.
 *
 * The caller (StudentReportAccess) has already authorized the report's
 * authority; the read model still applies everything that narrows rows or
 * columns for this viewer (StudentReportViewer).
 */
interface StudentReport
{
    public function definition(): StudentReportDefinition;

    /**
     * Options for the declared `choice` and `entity` filters, cut to what this
     * viewer may see.
     *
     * @return array<string, list<array<string, string>>>
     */
    public function options(School $school, StudentReportViewer $viewer): array;

    /**
     * One bounded page (definition()->perPage rows): constant queries per
     * page, whatever the history size. Each row maps column keys to values
     * (a RESTRICTED column the viewer may not see is absent), plus `links`.
     *
     * @param  array<string, string>  $filters  validated, declared filters only
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(School $school, StudentReportViewer $viewer, array $filters, string $sort, int $page): LengthAwarePaginator;

    /** Whether the report has any row at all for this viewer (to tell "nothing yet" from "no match"). */
    public function hasAny(School $school, StudentReportViewer $viewer): bool;
}
