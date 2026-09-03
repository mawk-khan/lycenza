<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\Exceptions\PayrollException;
use App\Domain\Payroll\Application\Payslip;
use App\Domain\Payroll\Application\PayslipLine;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.10 -- session-authenticated Inertia printable payslip view.
 * Delegates entirely to `PayslipReadService` (`payroll.compensation.sensitive.view`)
 * -- never a raw Eloquent query for anything that service already
 * authorizes/assembles, mirroring `PayrollRunController`'s identical
 * "same Application services as the JSON API, different transport"
 * shape. Renders on demand only -- no output is ever persisted or
 * emailed by this route.
 *
 * Unlike `PayrollRunController::show()` (which never lets a domain
 * "not found" exception reach this layer at all -- it resolves the
 * run itself via `PayrollRun::query()->findOrFail()`, so Laravel's own
 * `ModelNotFoundException` handling already renders a clean 404),
 * `PayslipReadService::render()` does its own run/result lookup and
 * eligibility check internally and throws a `PayrollException`
 * subtype for each expected failure -- these carry a real
 * `getStatusCode()`/`errorCode()` (rendered automatically for the
 * JSON API by bootstrap/app.php's `/api/*`-only exception envelope),
 * but that envelope deliberately does not apply to this `/app/*`
 * Inertia route. `abort()` is the standard Laravel translation for an
 * already-known HTTP status on this transport -- without it, a
 * bookmarked/typed URL for a since-reverted-to-draft or nonexistent
 * run/EmploymentRecord would 500 instead of showing the correct
 * 404/422.
 */
class PayslipController extends Controller
{
    public function show(TenantContext $context, string $payrollRun, string $employmentRecord, PayslipReadService $service): Response
    {
        $school = $context->requireSchool();

        try {
            $payslip = $service->render($school, $payrollRun, $employmentRecord, $context->actor());
        } catch (PayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return Inertia::render('App/Payroll/Payslips/Show', [
            'payslip' => [
                'schoolId' => $payslip->schoolId,
                'schoolName' => $payslip->schoolName,
                'payrollRunId' => $payslip->payrollRunId,
                'runKind' => $payslip->runKind,
                'correctsPayrollRunId' => $payslip->correctsPayrollRunId,
                'correctsPayrollPeriodMonth' => $payslip->correctsPayrollPeriodMonth,
                'runStatus' => $payslip->runStatus,
                'isReversed' => $payslip->isReversed,
                'payrollPeriodId' => $payslip->payrollPeriodId,
                'periodMonth' => $payslip->periodMonth,
                'paymentDate' => $payslip->paymentDate,
                'employmentRecordId' => $payslip->employmentRecordId,
                'employeeId' => $payslip->employeeId,
                'employeeFullName' => $payslip->employeeFullName,
                'employeeNumber' => $payslip->employeeNumber,
                'grossAmount' => $payslip->grossAmount,
                'totalDeductions' => $payslip->totalDeductions,
                'netAmount' => $payslip->netAmount,
                'lines' => array_map(fn (PayslipLine $l) => [
                    'salaryComponentId' => $l->salaryComponentId,
                    'componentCode' => $l->componentCode,
                    'componentName' => $l->componentName,
                    'componentType' => $l->componentType,
                    'amount' => $l->amount,
                    'effect' => $l->effect,
                ], $payslip->lines),
                'statutoryDeductionsIncluded' => $payslip->statutoryDeductionsIncluded,
            ],
        ]);
    }
}
