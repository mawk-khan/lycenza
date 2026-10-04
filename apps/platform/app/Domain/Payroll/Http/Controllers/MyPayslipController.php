<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayslipPresenter;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * HRX.4 (ADR 0065 §25.7): the acting Employee's OWN posted payslips, through
 * PayslipReadService's ownership path -- `payroll.payslips.self` plus
 * ActingEmployee ownership, both checked in the service on every access.
 * Anything not owned, not posted or unknown is one identical private 404.
 */
class MyPayslipController extends Controller
{
    public function index(Request $request, School $school, PayslipReadService $service): JsonResponse
    {
        return response()->json(['data' => $service->ownPayslips($school, $request->user())]);
    }

    public function show(Request $request, School $school, string $payrollRun, string $employmentRecord, PayslipReadService $service): JsonResponse
    {
        if (! Str::isUuid($payrollRun) || ! Str::isUuid($employmentRecord)) {
            throw PayslipReadService::privateNotFound();
        }

        return response()->json(['data' => PayslipPresenter::present($service->renderOwn($school, $payrollRun, $employmentRecord, $request->user()))]);
    }
}
