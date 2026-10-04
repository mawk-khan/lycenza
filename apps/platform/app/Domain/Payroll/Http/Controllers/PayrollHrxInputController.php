<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayrollHrxInputReadService;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * HRX.5 (ADR 0065 §26.10): a payroll run's captured HRX absence evidence and
 * its live comparison, plus the difference check that audits every source
 * change. Payroll administration only (`payroll.runs.prepare`, checked in the
 * service). Evidence, never a deduction or an NCP figure (HRX-L4 open).
 */
class PayrollHrxInputController extends Controller
{
    public function index(Request $request, School $school, string $payrollRun, PayrollHrxInputReadService $service): JsonResponse
    {
        abort_if(! Str::isUuid($payrollRun), 404);

        return response()->json(['data' => $service->forRun($school, $payrollRun, $request->user())]);
    }

    public function check(Request $request, School $school, string $payrollRun, PayrollHrxInputReadService $service): JsonResponse
    {
        abort_if(! Str::isUuid($payrollRun), 404);

        return response()->json(['data' => $service->checkDifferences($school, $payrollRun, $request->user())]);
    }
}
