<?php

namespace App\Http\Controllers\App\Leave;

use App\Domain\Leave\Application\Accrual\LeaveAccrualOutcome;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\Accrual\LeaveStatementReader;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeaveReadService;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR.10 (ADR 0065 §29) -- the monthly accrual workspace (session, School in
 * context):
 * - GET  /app/leave/accruals (`hr.leave.view`): one leave type and year --
 *   the monthly grid (server-paged; a month with nothing posted shows
 *   nothing, never a zero), the run history; with `period_start` and
 *   `hr.leave.manage`, the month's preview (complete counts, one page of
 *   rows); with `run`, that run's reconciled counts and items.
 * - POST /app/leave/accrual-runs (`hr.leave.manage`): open or resume the
 *   month's run and process a bounded number of batches; the page shows
 *   whether it completed or should be continued.
 *
 * Every number comes from LeaveAccrualService / LeaveStatementReader.
 */
class LeaveAccrualPageController extends Controller
{
    use AuthorizesCapability, LeavePageSupport;

    /** Batches one web request processes before handing back (a technical bound; the run resumes). */
    private const WEB_BATCHES = 20;

    public function show(Request $request, TenantContext $context, CapabilityResolver $capabilities, LeaveReadService $reads, LeaveAccrualService $accruals, LeaveStatementReader $statements): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(LeaveCapabilities::VIEW, $school);
        $actor = $context->actor();
        $validated = $request->validate([
            'leave_type_id' => ['sometimes', 'uuid'], 'leave_year_id' => ['sometimes', 'uuid'], 'period_start' => ['sometimes', 'date_format:Y-m-d'],
            'after' => ['sometimes', 'uuid'], 'preview_after' => ['sometimes', 'uuid'], 'run' => ['sometimes', 'uuid'],
            'outcome' => ['sometimes', Rule::in(array_column(LeaveAccrualOutcome::options(), 'value'))], 'item_after' => ['sometimes', 'uuid'],
        ]);
        $today = SchoolTimezone::today($school);
        $types = array_values(array_filter($reads->types($school, $actor), fn (array $t) => $t['tracksBalance']));
        $years = $reads->leaveYears($school, $actor);
        $typeId = $validated['leave_type_id'] ?? ($types[0]['id'] ?? null);
        $yearId = $validated['leave_year_id'] ?? (collect($years)->first(fn (array $y) => $y['startsOn'] <= $today && $y['endsOn'] >= $today)['id'] ?? ($years[0]['id'] ?? null));
        $canManage = $capabilities->canInSchool($actor, LeaveCapabilities::MANAGE, $school);

        $grid = $runs = $preview = $run = null;
        $error = null;
        try {
            if ($typeId !== null && $yearId !== null) {
                $grid = $statements->grid($school, $typeId, $yearId, $actor, $validated['after'] ?? null);
                $runs = $accruals->runs($school, $typeId, $yearId, $actor);
                if ($canManage && isset($validated['period_start'])) {
                    $preview = $accruals->preview($school, $typeId, $yearId, $validated['period_start'], $actor, $validated['preview_after'] ?? null);
                }
            }
            if (isset($validated['run'])) {
                $run = $accruals->show($school, $validated['run'], $actor, $validated['outcome'] ?? null, $validated['item_after'] ?? null);
            }
        } catch (LeaveException $e) {
            $error = "{$e->getMessage()} ({$e->errorCode()})";
        } catch (ModelNotFoundException) {
            $error = 'Choose a leave type, year and run of this School.';
        }

        return Inertia::render('App/Leave/Accruals', [
            'types' => $types, 'years' => $years, 'leaveTypeId' => $typeId ?? '', 'leaveYearId' => $yearId ?? '', 'today' => $today,
            'periodStart' => $validated['period_start'] ?? '', 'grid' => $grid, 'runs' => $runs, 'preview' => $preview, 'run' => $run,
            'outcome' => $validated['outcome'] ?? '', 'outcomes' => LeaveAccrualOutcome::options(), 'error' => $error, 'canManage' => $canManage,
        ]);
    }

    public function execute(Request $request, TenantContext $context, LeaveAccrualService $accruals): RedirectResponse
    {
        $school = $context->requireSchool();
        $validated = $request->validate(['leave_type_id' => ['required', 'uuid'], 'leave_year_id' => ['required', 'uuid'], 'period_start' => ['required', 'date_format:Y-m-d']]);
        $run = null;
        $response = $this->command(function () use (&$run, $accruals, $school, $validated, $request) {
            $run = $accruals->execute($school, $validated['leave_type_id'], $validated['leave_year_id'], $validated['period_start'], $request->user(), self::WEB_BATCHES);
        }, 'Accrual run processed.');
        if ($run === null) {
            return $response;
        }

        return redirect('/app/leave/accruals?'.http_build_query([
            'leave_type_id' => $validated['leave_type_id'], 'leave_year_id' => $validated['leave_year_id'], 'run' => $run->id,
        ]))->with('status', $run->status === 'completed' ? 'Accrual run completed.' : 'Accrual run processed part of the staff; run it again to continue.');
    }
}
