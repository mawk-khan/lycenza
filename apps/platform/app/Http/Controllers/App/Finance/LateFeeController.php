<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\Exceptions\FeeLateFeeRuleNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeesException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeLateFeeRuleException;
use App\Domain\Fees\Application\LateFeeRuleService;
use App\Domain\Fees\Http\Controllers\LateFeeRuleController as ApiLateFeeRuleController;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeLateFeeRule;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Payments\Application\Exceptions\InvalidLateFeeRunException;
use App\Domain\Payments\Application\Exceptions\LateFeeAssessmentNotFoundException;
use App\Domain\Payments\Application\Exceptions\LateFeeRunNotFoundException;
use App\Domain\Payments\Application\Exceptions\PaymentsException;
use App\Domain\Payments\Application\LateFeeAssessmentService;
use App\Domain\Payments\Application\LateFeeReadService;
use App\Domain\Payments\Application\LateFeeRunService;
use App\Domain\Payments\Http\Controllers\LateFeeRunController as ApiLateFeeRunController;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\LateFeeRun;
use App\Domain\Payments\Infrastructure\LateFeeRunItem;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEE.5 (ADR 0062 §16; owner decision H): Finance -> Late fees. Rules need
 * finance.fee_structures.view (and .manage to change them); runs need
 * finance.charges.view to read, finance.fee_assessments.run to act; voiding
 * a late fee needs finance.charges.manage -- checked here and again in the
 * Application services. One late fee per source charge per rule; no
 * recurrence or compounding. Legal status: DEVELOPMENT AUTHORISED — PROD
 * LEGAL SIGN-OFF REQUIRED (ADR 0058 E31). Reads `Student`/`AcademicYear`
 * for display only.
 */
class LateFeeController extends Controller
{
    use AuthorizesCapability;

    public function rules(TenantContext $context, LateFeeRuleService $rules, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.view', $school);
        $actor = $context->actor();
        $structures = $this->structureLabels();
        $heads = FeeHead::query()->orderBy('code')->get();

        return Inertia::render('App/Finance/FeeSetup/LateFees', [
            'rules' => $rules->list($school, $actor)->map(fn (FeeLateFeeRule $r) => [
                ...ApiLateFeeRuleController::present($r),
                'structureLabel' => $structures[$r->fee_structure_id] ?? null,
                'feeHeadLabel' => $r->fee_head_id ? $heads->firstWhere('id', $r->fee_head_id)?->name : null,
                'lateFeeHeadLabel' => $heads->firstWhere('id', $r->late_fee_head_id)?->name,
            ])->values()->all(),
            'structures' => FeeStructure::query()->whereIn('status', ['active', 'retired'])->orderBy('name')->get()
                ->map(fn (FeeStructure $s) => ['id' => $s->id, 'label' => $structures[$s->id] ?? $s->name, 'headIds' => $s->lines()->pluck('fee_head_id')->all()])->values()->all(),
            'feeHeads' => $heads->where('status', FeeHead::STATUS_ACTIVE)->map(fn (FeeHead $h) => ['id' => $h->id, 'label' => "{$h->code} · {$h->name}"])->values()->all(),
            'canManage' => $capabilities->canInSchool($actor, 'finance.fee_structures.manage', $school),
        ]);
    }

    public function storeRule(Request $request, TenantContext $context, LateFeeRuleService $rules): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $data = $this->validatedRule($request, true);

        return $this->ruleAction(fn () => $rules->create($school, $data, $context->actor()));
    }

    public function updateRule(Request $request, TenantContext $context, LateFeeRuleService $rules, string $rule): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $data = $this->validatedRule($request, false);

        return $this->ruleAction(fn () => $rules->update($school, $rule, $data, $context->actor()));
    }

    public function ruleStatus(Request $request, TenantContext $context, LateFeeRuleService $rules, string $rule): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        return $this->ruleAction(fn () => $validated['status'] === 'active'
            ? $rules->activate($school, $rule, $context->actor())
            : $rules->deactivate($school, $rule, $context->actor()));
    }

    public function runs(TenantContext $context, LateFeeReadService $reads, LateFeeRuleService $rules, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.view', $school);
        $actor = $context->actor();
        $canRun = $capabilities->canInSchool($actor, 'finance.fee_assessments.run', $school);
        $ruleNames = FeeLateFeeRule::query()->pluck('name', 'id');

        return Inertia::render('App/Finance/FeeSetup/LateFeeRuns', [
            'runs' => $reads->listRuns($school, $actor)->map(fn (LateFeeRun $r) => [
                ...ApiLateFeeRunController::presentRun($r),
                'ruleName' => $ruleNames[$r->fee_late_fee_rule_id] ?? null,
            ])->values()->all(),
            'activeRules' => $canRun && $capabilities->canInSchool($actor, 'finance.fee_structures.view', $school)
                ? $rules->list($school, $actor)->filter(fn (FeeLateFeeRule $r) => $r->isActive())->map(fn (FeeLateFeeRule $r) => ['id' => $r->id, 'name' => $r->name])->values()->all()
                : [],
            'today' => Carbon::now($school->timezone)->toDateString(),
            'canRun' => $canRun,
        ]);
    }

    public function storeRun(Request $request, TenantContext $context, LateFeeRunService $runs): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_assessments.run', $school);
        $validated = $request->validate([
            'late_fee_rule_id' => ['required', 'uuid'],
            'evaluation_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $run = $runs->create($school, $validated['late_fee_rule_id'], $validated['evaluation_date'] ?? null, $context->actor());
        } catch (InvalidLateFeeRunException $e) {
            throw ValidationException::withMessages([$e->field() === 'fee_late_fee_rule_id' ? 'late_fee_rule_id' : $e->field() => [$e->getMessage()]]);
        } catch (PaymentsException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect("/app/finance/late-fee-runs/{$run->id}");
    }

    public function showRun(Request $request, TenantContext $context, LateFeeReadService $reads, CapabilityResolver $capabilities, string $run): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.view', $school);
        $actor = $context->actor();
        $validated = $request->validate([
            'preview_result' => ['sometimes', 'string', Rule::in(LateFeeRunItem::PREVIEW_RESULTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $model = $reads->getRun($school, $run, $actor);
            $items = $reads->listItems($school, $run, array_intersect_key($validated, ['preview_result' => 1]), (int) ($validated['page'] ?? 1), $actor);
        } catch (LateFeeRunNotFoundException) {
            throw new NotFoundHttpException;
        }

        $students = Student::query()->whereIn('id', collect($items->items())->pluck('student_id')->unique()->all())->get()->keyBy('id');
        $heads = FeeHead::query()->get()->keyBy('id');
        $rule = FeeLateFeeRule::query()->find($model->fee_late_fee_rule_id);
        $lateFees = LateFeeAssessment::query()->whereIn('id', collect($items->items())->pluck('late_fee_assessment_id')->filter()->all())->get()->keyBy('id');

        return Inertia::render('App/Finance/FeeSetup/LateFeeRun', [
            'run' => [
                ...ApiLateFeeRunController::presentRun($model),
                'rule' => $rule === null ? null : ApiLateFeeRuleController::present($rule),
            ],
            'items' => $items->through(fn (LateFeeRunItem $i) => [
                ...ApiLateFeeRunController::presentItem($i),
                'studentName' => ($s = $students->get($i->student_id)) ? collect([$s->first_name, $s->middle_name, $s->last_name])->filter()->implode(' ') : null,
                'studentNumber' => $students->get($i->student_id)?->student_number,
                'feeHeadCode' => $heads->get($i->fee_head_id)?->code,
                'lateFeeChargeId' => $i->late_fee_assessment_id ? $lateFees->get($i->late_fee_assessment_id)?->charge_id : null,
                'lateFeeVoided' => $i->late_fee_assessment_id ? $lateFees->get($i->late_fee_assessment_id)?->voided_at !== null : false,
            ])->appends($request->only('preview_result')),
            'filters' => ['preview_result' => $validated['preview_result'] ?? ''],
            'canRun' => $capabilities->canInSchool($actor, 'finance.fee_assessments.run', $school),
            'canVoid' => $capabilities->canInSchool($actor, 'finance.charges.manage', $school),
        ]);
    }

    public function preview(TenantContext $context, LateFeeRunService $runs, string $run): RedirectResponse
    {
        return $this->runAction($context, $run, fn ($school, $actor) => $runs->preview($school, $run, $actor));
    }

    public function execute(TenantContext $context, LateFeeRunService $runs, string $run): RedirectResponse
    {
        return $this->runAction($context, $run, fn ($school, $actor) => $runs->execute($school, $run, $actor));
    }

    public function resume(TenantContext $context, LateFeeRunService $runs, string $run): RedirectResponse
    {
        return $this->runAction($context, $run, fn ($school, $actor) => $runs->resume($school, $run, $actor));
    }

    public function cancel(TenantContext $context, LateFeeRunService $runs, string $run): RedirectResponse
    {
        return $this->runAction($context, $run, fn ($school, $actor) => $runs->cancel($school, $run, $actor));
    }

    public function void(Request $request, TenantContext $context, LateFeeAssessmentService $assessments, string $assessment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $assessments->void($school, $assessment, $context->actor(), $validated['reason'] ?? null);
        } catch (LateFeeAssessmentNotFoundException) {
            throw new NotFoundHttpException;
        } catch (PaymentsException|FeesException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back();
    }

    /** @param callable(School, User): mixed $operation */
    private function runAction(TenantContext $context, string $run, callable $operation): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_assessments.run', $school);
        $url = "/app/finance/late-fee-runs/{$run}";

        try {
            $operation($school, $context->actor());
        } catch (LateFeeRunNotFoundException) {
            throw new NotFoundHttpException;
        } catch (PaymentsException $e) {
            return redirect($url)->withErrors(['action' => $e->getMessage()]);
        }

        return redirect($url);
    }

    /** @param callable(): mixed $operation */
    private function ruleAction(callable $operation): RedirectResponse
    {
        try {
            $operation();
        } catch (InvalidFeeLateFeeRuleException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        } catch (FeeLateFeeRuleNotFoundException) {
            throw new NotFoundHttpException;
        } catch (FeesException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect('/app/finance/late-fees');
    }

    /** @return array<string, mixed> */
    private function validatedRule(Request $request, bool $withStructure): array
    {
        return $request->validate(array_filter([
            'fee_structure_id' => $withStructure ? ['required', 'uuid'] : null,
            'name' => ['required', 'string', 'max:120'],
            'fee_head_id' => ['nullable', 'uuid'],
            'late_fee_head_id' => ['required', 'uuid'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'kind' => ['required', 'string', 'in:fixed,percentage'],
            'fixed_amount' => ['nullable', 'string', 'max:20'],
            'percentage' => ['nullable', 'string', 'max:8'],
            'max_amount' => ['nullable', 'string', 'max:20'],
        ]));
    }

    /** @return array<string, string> */
    private function structureLabels(): array
    {
        $years = AcademicYear::query()->pluck('name', 'id');

        return FeeStructure::query()->get()->mapWithKeys(fn (FeeStructure $s) => [$s->id => $s->name.' · '.($years[$s->academic_year_id] ?? '')])->all();
    }
}
