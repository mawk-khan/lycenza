<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollRunReadService;
use App\Domain\Payroll\Application\PayrollRunSummary;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.9 -- session-authenticated Inertia pages for Payroll
 * Periods. Delegates every write to `PayrollPeriodAdministrationService`,
 * exactly like the JSON API controller
 * (`App\Domain\Payroll\Http\Controllers\PayrollPeriodController`).
 * There is no dedicated period-listing Read service yet (that
 * controller's own docblock: "no independently-useful read-only tier
 * yet") -- `payroll.periods.manage` is the sole gate here too, and the
 * period listing query below is a direct, tenant-scoped,
 * non-sensitive read (periods carry only dates/status, never a
 * monetary field) exactly like `PayrollRunResultReadService`'s own
 * inline-query style for a read with no dedicated service yet.
 */
class PayrollPeriodController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, PayrollRunReadService $runs, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('payroll.periods.manage', $school);

        $periods = $context->withSchool($school, fn () => PayrollPeriod::query()
            ->where('school_id', $school->id)
            ->orderByDesc('period_month')
            ->get());

        $actor = $context->actor();
        // A `payroll.periods.manage`-only actor need not also hold
        // `payroll.runs.view` -- `PayrollRunReadService::listRuns()`
        // throws for a caller lacking it, so the run list is only
        // fetched when authorized; otherwise each period simply omits
        // its run summary rather than the whole page failing.
        $canViewRuns = $capabilities->canInSchool($actor, 'payroll.runs.view', $school);

        return Inertia::render('App/Payroll/Periods/Index', [
            'canViewRuns' => $canViewRuns,
            'periods' => $periods->map(function (PayrollPeriod $period) use ($school, $runs, $actor, $canViewRuns) {
                $periodRuns = $canViewRuns ? $runs->listRuns($school, $period, $actor) : [];

                return [
                    'id' => $period->id,
                    'periodMonth' => $period->period_month->toDateString(),
                    'startsOn' => $period->starts_on->toDateString(),
                    'endsOn' => $period->ends_on->toDateString(),
                    'paymentDate' => $period->payment_date?->toDateString(),
                    'status' => $period->status,
                    'hasRegularRun' => collect($periodRuns)->contains(fn (PayrollRunSummary $r) => $r->runKind === 'regular'),
                    'runs' => array_map(fn (PayrollRunSummary $r) => [
                        'id' => $r->id,
                        'runKind' => $r->runKind,
                        'status' => $r->status,
                    ], $periodRuns),
                ];
            })->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context, PayrollPeriodAdministrationService $service): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'period_month' => ['required', 'date'],
            'payment_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $service->createPeriod(
            $school,
            Carbon::parse($validated['period_month']),
            isset($validated['payment_date']) ? Carbon::parse($validated['payment_date']) : null,
            $context->actor(),
        );

        return redirect('/app/payroll/periods');
    }

    public function open(TenantContext $context, string $payrollPeriod, PayrollPeriodAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $period = PayrollPeriod::query()->findOrFail($payrollPeriod);
        $service->open($period, $context->actor());

        return redirect('/app/payroll/periods');
    }

    public function close(TenantContext $context, string $payrollPeriod, PayrollPeriodAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $period = PayrollPeriod::query()->findOrFail($payrollPeriod);
        $service->close($period, $context->actor());

        return redirect('/app/payroll/periods');
    }
}
