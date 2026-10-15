<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\EnrollmentRolloverBulkDecisionService;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Application\Exceptions\StaleRolloverDecisionsException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Http\Controllers\App\Concerns\ReturnsToRolloverPlanContext;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * UAT-C2 (UAT-YR-01b): bulk Promote / Exclude on one rollover plan --
 * preview (writes nothing) then apply. Same capabilities as a single
 * decision (`enrollments.manage` + `enrollments.rollovers.manage`); the
 * plan resolves through the School's own scope (another School's plan is a
 * 404). Every rule lives in EnrollmentRolloverBulkDecisionService.
 *
 * Rule 29 (retries): no `idempotent` middleware -- apply() names the plan's
 * configuration version, so a repeated or late submission is refused whole
 * as stale and changes nothing.
 */
class EnrollmentRolloverBulkDecisionController extends Controller
{
    use AuthorizesCapability;
    use ReturnsToRolloverPlanContext;

    /** @return array{plan: EnrollmentRolloverPlan, itemIds: list<string>, decision: string} */
    private function input(Request $request, TenantContext $context, string $rollover): array
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('enrollments.manage', $school);
        $this->authorizeCapability('enrollments.rollovers.manage', $school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(EnrollmentRolloverBulkDecisionService::DECISIONS)],
            'item_ids' => ['required', 'array', 'min:1', 'max:'.EnrollmentRolloverBulkDecisionService::MAX_ITEMS],
            'item_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        return ['plan' => $plan, 'itemIds' => array_values($validated['item_ids']), 'decision' => $validated['decision']];
    }

    public function preview(Request $request, TenantContext $context, string $rollover, EnrollmentRolloverBulkDecisionService $service): JsonResponse
    {
        ['plan' => $plan, 'itemIds' => $itemIds, 'decision' => $decision] = $this->input($request, $context, $rollover);

        try {
            $rows = $service->preview($plan, $itemIds, $decision);
        } catch (RolloverPlanNoLongerConfigurableException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 422);
        }

        return response()->json(['decision' => $decision, 'configurationVersion' => $plan->configuration_version, 'rows' => $rows]);
    }

    public function apply(Request $request, TenantContext $context, string $rollover, EnrollmentRolloverBulkDecisionService $service): RedirectResponse
    {
        ['plan' => $plan, 'itemIds' => $itemIds, 'decision' => $decision] = $this->input($request, $context, $rollover);
        $expected = $request->validate(['configuration_version' => ['required', 'integer', 'min:0']])['configuration_version'];

        try {
            $rows = $service->apply($plan, $itemIds, $decision, (int) $expected, $context->actor());
        } catch (StaleRolloverDecisionsException|RolloverPlanNoLongerConfigurableException $e) {
            throw ValidationException::withMessages(['bulk' => [$e->getMessage()]]);
        }

        $applied = count(array_filter($rows, fn (array $r) => $r['outcome'] === EnrollmentRolloverBulkDecisionService::APPLIED));
        $label = $decision === 'promote' ? 'Promote' : 'Exclude';

        return redirect($this->rolloverPlanUrl($request, $plan->id))
            ->with('flash', "{$label} saved for {$applied} of ".count($rows).' selected Student'.(count($rows) === 1 ? '' : 's').'. Run Validation when the plan is ready.')
            ->with('rolloverBulkResult', [
                'decision' => $decision,
                'applied' => $applied,
                'total' => count($rows),
                'unresolved' => $this->named(array_values(array_filter($rows, fn (array $r) => $r['outcome'] !== EnrollmentRolloverBulkDecisionService::APPLIED)), $plan),
            ]);
    }

    /**
     * Names the Students left unchanged, so the result stays readable after
     * the list moves on (e.g. a "No decision" filter no longer shows them).
     *
     * @param  list<array{itemId: string, outcome: string, target: ?string}>  $rows
     * @return list<array{itemId: string, outcome: string, target: ?string, student: ?string}>
     */
    private function named(array $rows, EnrollmentRolloverPlan $plan): array
    {
        $students = EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)
            ->whereIn('id', array_column($rows, 'itemId'))
            ->with('student:id,school_id,student_number,first_name,last_name')
            ->get()
            ->mapWithKeys(fn (EnrollmentRolloverItem $i) => [$i->id => trim("{$i->student->first_name} {$i->student->last_name}")." ({$i->student->student_number})"]);

        return array_map(fn (array $r) => [...$r, 'student' => $students->get($r['itemId'])], $rows);
    }
}
