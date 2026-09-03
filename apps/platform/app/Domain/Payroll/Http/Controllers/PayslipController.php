<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\Payslip;
use App\Domain\Payroll\Application\PayslipLine;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;

/**
 * Phase 9.10 -- thin HTTP transport over `PayslipReadService`
 * (`payroll.compensation.sensitive.view`, checked inside that service
 * before any query runs -- this controller adds no authorization of
 * its own beyond the route `capability:` middleware, mirroring
 * `PayrollRunPostingController::results()`'s identical split).
 */
class PayslipController extends Controller
{
    public function show(School $school, string $payrollRun, string $employmentRecord, PayslipReadService $service): JsonResponse
    {
        $payslip = $service->render($school, $payrollRun, $employmentRecord, request()->user());

        return response()->json(['data' => $this->present($payslip)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Payslip $payslip): array
    {
        return [
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
        ];
    }
}
