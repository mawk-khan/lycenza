<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEcrExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEsiContributionWorksheetExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryTdsDraftStatementExportService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Checkpoint 9.6I (Section 2 "Exports") -- streams the already-built
 * Checkpoint 9.6G export content directly in the response body (no
 * public file URL, ADR 0036's export-only boundary is unaffected --
 * this is still data PREPARATION, never a government portal
 * submission). Each export service already enforces
 * `payroll.statutory.exports.generate` (plus `.identifiers.view` for
 * ECR specifically) internally -- no separate route-level capability
 * check needed for the *content*, but see `routes/api.php`'s own
 * `capability:` middleware on these routes for the double-check
 * convention every other Payroll route already follows.
 */
class StatutoryExportController extends Controller
{
    public function ecr(Request $request, School $school, string $payrollRun, StatutoryEcrExportService $service): Response
    {
        $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRun);
        $content = $service->generate($run, $request->user());

        return response($content, 200)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', "attachment; filename=\"ecr-{$run->id}.txt\"");
    }

    public function esiWorksheet(Request $request, School $school, string $payrollRun, StatutoryEsiContributionWorksheetExportService $service): Response
    {
        $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRun);
        $content = $service->generate($run, $request->user());

        return response($content, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"esi-worksheet-{$run->id}.csv\"");
    }

    public function tdsDraftStatement(Request $request, School $school, string $payrollRun, StatutoryTdsDraftStatementExportService $service): Response
    {
        $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRun);
        $content = $service->generate($run, $request->user());

        return response($content, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"tds-draft-statement-{$run->id}.csv\"");
    }
}
