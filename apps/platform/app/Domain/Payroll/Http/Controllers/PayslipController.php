<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayslipPresenter;
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

        return response()->json(['data' => PayslipPresenter::present($payslip)]);
    }
}
