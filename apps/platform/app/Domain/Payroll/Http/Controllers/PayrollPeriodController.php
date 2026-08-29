<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 9.8 -- thin HTTP transport over `PayrollPeriodAdministrationService`.
 * No `index`/`show` here -- `payroll.periods.manage` has no separate
 * read-only tier yet (Checkpoint 9.7's own documented reasoning: "no
 * independently-useful read-only tier yet"), and periods are already
 * reachable via `GET .../payroll-periods/{payrollPeriod}/payroll-runs`
 * once a run names one. `{payrollPeriod}` resolved via
 * `PayrollPeriod::query()->findOrFail()` -- see
 * `SalaryComponentController`'s docblock for why this is safe.
 */
class PayrollPeriodController extends Controller
{
    public function store(Request $request, School $school, PayrollPeriodAdministrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'period_month' => ['required', 'date'],
            'payment_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $period = $service->createPeriod(
            $school,
            Carbon::parse($validated['period_month']),
            isset($validated['payment_date']) ? Carbon::parse($validated['payment_date']) : null,
            $request->user(),
        );

        return response()->json(['data' => $this->present($period)], 201);
    }

    public function open(Request $request, School $school, string $payrollPeriod, PayrollPeriodAdministrationService $service): JsonResponse
    {
        $period = PayrollPeriod::query()->findOrFail($payrollPeriod);
        $period = $service->open($period, $request->user());

        return response()->json(['data' => $this->present($period)]);
    }

    public function close(Request $request, School $school, string $payrollPeriod, PayrollPeriodAdministrationService $service): JsonResponse
    {
        $period = PayrollPeriod::query()->findOrFail($payrollPeriod);
        $period = $service->close($period, $request->user());

        return response()->json(['data' => $this->present($period)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayrollPeriod $period): array
    {
        return [
            'id' => $period->id,
            'periodMonth' => $period->period_month->toDateString(),
            'startsOn' => $period->starts_on->toDateString(),
            'endsOn' => $period->ends_on->toDateString(),
            'paymentDate' => $period->payment_date?->toDateString(),
            'status' => $period->status,
        ];
    }
}
