<?php

namespace App\Http\Controllers\App\Payroll\Statutory;

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollException;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEcrExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryEsiContributionWorksheetExportService;
use App\Domain\Payroll\Statutory\Application\Export\StatutoryTdsDraftStatementExportService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * Checkpoint 9.6I (Section 4) -- session-authenticated download
 * surfaces over the Checkpoint 9.6G export services (already
 * capability-checked internally). No public file URL -- the response
 * streams the export content directly, requiring an active,
 * authorized session for every request. `StatutoryPayrollException`
 * is translated via `abort()` -- the JSON API's `bootstrap/app.php`
 * exception renderer does not cover this `/app/*` Inertia transport,
 * matching `PayslipController`'s established pattern exactly.
 */
class StatutoryExportController extends Controller
{
    use AuthorizesCapability;

    public function show(TenantContext $context, string $payrollRun): \Inertia\Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('payroll.statutory.exports.generate', $school);
        $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRun);

        return Inertia::render('App/Payroll/Statutory/Exports/Show', [
            'payrollRunId' => $run->id,
        ]);
    }

    public function ecr(TenantContext $context, StatutoryEcrExportService $service, string $payrollRun): Response
    {
        $school = $context->requireSchool();
        $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRun);

        try {
            $content = $service->generate($run, $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return response($content, 200)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', "attachment; filename=\"ecr-{$run->id}.txt\"");
    }

    public function esiWorksheet(TenantContext $context, StatutoryEsiContributionWorksheetExportService $service, string $payrollRun): Response
    {
        $school = $context->requireSchool();
        $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRun);

        try {
            $content = $service->generate($run, $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return response($content, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"esi-worksheet-{$run->id}.csv\"");
    }

    public function tdsDraftStatement(TenantContext $context, StatutoryTdsDraftStatementExportService $service, string $payrollRun): Response
    {
        $school = $context->requireSchool();
        $run = PayrollRun::query()->where('school_id', $school->id)->findOrFail($payrollRun);

        try {
            $content = $service->generate($run, $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return response($content, 200)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"tds-draft-statement-{$run->id}.csv\"");
    }
}
