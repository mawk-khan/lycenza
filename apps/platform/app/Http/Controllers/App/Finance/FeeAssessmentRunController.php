<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Fees\Application\ChargeCancellationReason;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunItemNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeAssessmentRunNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeesException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeAssessmentRunException;
use App\Domain\Fees\Application\FeeAssessmentRunReadService;
use App\Domain\Fees\Application\FeeAssessmentRunService;
use App\Domain\Fees\Application\FeeAssessmentService;
use App\Domain\Fees\Application\FeeConcessionReadService;
use App\Domain\Fees\Application\FeeRunOverlapDetector;
use App\Domain\Fees\Application\FeeStructureReadService;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEE.2 (ADR 0062 §9): Finance -> Fee setup -> Assessment runs. Viewing
 * needs finance.charges.view (run results are charges); every run action
 * needs finance.fee_assessments.run; voiding an assessment needs
 * finance.charges.manage -- checked here and again in the Application
 * services. Preview never creates a charge; there is no proration control.
 *
 * UAT-C6 (UAT-FIN-20, ADR 0075 section 19): a "decision required" item
 * shows the charges that may already cover its period (description,
 * amount, due date, what is still owed -- this School's, under the page's
 * finance.charges.view) next to the charge the run would create, and takes
 * an explicit Include anyway / Exclude from this run (`decide`).
 */
class FeeAssessmentRunController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, FeeAssessmentRunReadService $reads, FeeStructureReadService $structures, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        // ORG.7G-1: finance.charges.view is the route floor (a declared whole-School operation).
        $actor = $context->actor();
        $canRun = $capabilities->canInSchool($actor, 'finance.fee_assessments.run', $school);
        $canViewStructures = $capabilities->canInSchool($actor, 'finance.fee_structures.view', $school);

        $names = $this->structureNames();

        $activeStructures = $canRun && $canViewStructures
            ? $structures->listStructures($school, ['status' => FeeStructure::STATUS_ACTIVE], $actor)
                ->map(fn (FeeStructure $s) => [
                    'id' => $s->id,
                    'label' => $names($s),
                    'periods' => $this->periodsOf($structures->getStructure($school, $s->id, $actor)),
                ])->values()->all()
            : [];

        $structureLabels = FeeStructure::query()->get()->mapWithKeys(fn (FeeStructure $s) => [$s->id => $names($s)]);

        return Inertia::render('App/Finance/FeeSetup/Runs', [
            'runs' => $reads->listRuns($school, [], $actor)->map(fn (FeeAssessmentRun $r) => [
                ...FeeSetupPresenter::run($r),
                'structureLabel' => $structureLabels[$r->fee_structure_id] ?? null,
            ])->values()->all(),
            'activeStructures' => $activeStructures,
            'canRun' => $canRun,
        ]);
    }

    public function store(Request $request, TenantContext $context, FeeAssessmentRunService $runs): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_assessments.run', $school);

        $validated = $request->validate([
            'fee_structure_id' => ['required', 'uuid'],
            'billing_period_key' => ['required', 'string', 'max:32'],
        ]);

        try {
            $run = $runs->create($school, $validated['fee_structure_id'], $validated['billing_period_key'], $context->actor());
        } catch (InvalidFeeAssessmentRunException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        } catch (FeesException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect("/app/finance/fee-runs/{$run->id}");
    }

    public function show(Request $request, TenantContext $context, FeeAssessmentRunReadService $reads, CapabilityResolver $capabilities, FeeConcessionReadService $concessions, ChargeStateReader $chargeStates, string $run): Response
    {
        $school = $context->requireSchool();
        // ORG.7G-1: finance.charges.view is the route floor (a declared whole-School operation).
        $actor = $context->actor();

        $validated = $request->validate([
            'preview_result' => ['sometimes', 'string', Rule::in(FeeAssessmentRunItem::PREVIEW_RESULTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $model = $reads->getRun($school, $run, $actor);
            $items = $reads->listItems($school, $run, array_intersect_key($validated, ['preview_result' => 1]), (int) ($validated['page'] ?? 1), $actor);
        } catch (FeeAssessmentRunNotFoundException) {
            throw new NotFoundHttpException;
        }

        $students = Student::query()->whereIn('id', collect($items->items())->pluck('student_id')->unique()->all())->get()->keyBy('id');
        $heads = FeeHead::query()->get()->keyBy('id');
        $structure = FeeStructure::query()->find($model->fee_structure_id);

        // FEE.3 (ADR 0062 §14.4): gross, concession and net per item -- only
        // for a viewer of concessions (Highly Sensitive), for items that
        // have not executed yet.
        $projection = $structure !== null && $capabilities->canInSchool($actor, FeeConcessionReadService::VIEW, $school)
            ? $concessions->previewStanding($school, $structure->academic_year_id, collect($items->items())->filter(fn (FeeAssessmentRunItem $i) => $i->preview_result === FeeAssessmentRunItem::RESULT_READY && in_array($i->execution_status, [null, FeeAssessmentRunItem::EXEC_PENDING], true)), $actor)
            : null;

        // UAT-C6 (UAT-FIN-20): the charges behind this page's overlap reviews.
        $overlapIds = collect($items->items())->flatMap(fn (FeeAssessmentRunItem $i) => $i->overlap_charge_ids ?? [])->unique()->values()->all();
        $overlapCharges = $reads->overlapCharges($school, $overlapIds, $actor);
        $owed = collect($chargeStates->forCharges($school, array_keys($overlapCharges)))->map(fn ($state) => $state->outstanding()->amount());
        $reviewed = collect($items->items())->filter(fn (FeeAssessmentRunItem $i) => ($i->overlap_charge_ids ?? []) !== []);
        $installments = FeeStructureInstallment::query()->whereIn('id', $reviewed->pluck('fee_structure_installment_id')->unique()->all())->get()->keyBy('id');
        $proposals = [];
        foreach ($reviewed as $i) {
            $head = $heads->get($i->fee_head_id);
            $installment = $installments->get($i->fee_structure_installment_id);
            if ($head !== null && $installment !== null) {
                $proposals[$i->id] = ['description' => FeeRunOverlapDetector::proposedDescription($head, $installment), 'dueDate' => $installment->due_date->toDateString()];
            }
        }

        return Inertia::render('App/Finance/FeeSetup/Run', [
            'run' => [
                ...FeeSetupPresenter::run($model, $reads->decisionRequiredCount($school, $run, $actor)),
                'structureLabel' => $structure ? ($this->structureNames())($structure) : null,
            ],
            'items' => $items->through(fn (FeeAssessmentRunItem $i) => [
                ...FeeSetupPresenter::runItem($i),
                'studentName' => ($s = $students->get($i->student_id)) ? collect([$s->first_name, $s->middle_name, $s->last_name])->filter()->implode(' ') : null,
                'studentNumber' => $students->get($i->student_id)?->student_number,
                'feeHeadCode' => $heads->get($i->fee_head_id)?->code,
                'concessionPreview' => $projection[$i->id] ?? null,
                'overlapCharges' => collect($i->overlap_charge_ids ?? [])
                    ->filter(fn (string $id) => isset($overlapCharges[$id]))
                    ->map(fn (string $id) => [...$overlapCharges[$id], 'outstanding' => $owed[$id] ?? null])
                    ->values()->all(),
                'proposedDescription' => $proposals[$i->id]['description'] ?? null,
                'proposedDueDate' => $proposals[$i->id]['dueDate'] ?? null,
            ])->appends($request->only('preview_result')),
            'filters' => ['preview_result' => $validated['preview_result'] ?? ''],
            // ORG.8G (ADR 0076 §65): the listed void reasons and whether a fresh code can be given.
            'voidReasons' => ChargeCancellationReason::CODES[ChargeCancellationReason::ASSESSMENT_VOID],
            'hasMfaFactor' => app(FreshMfaRequirement::class)->hasActiveFactor($actor),
            'canRun' => $capabilities->canInSchool($actor, 'finance.fee_assessments.run', $school),
            'canVoid' => $capabilities->canInSchool($actor, 'finance.charges.manage', $school),
        ]);
    }

    public function preview(TenantContext $context, FeeAssessmentRunService $runs, string $run): RedirectResponse
    {
        return $this->act($context, $run, fn ($school, $actor) => $runs->preview($school, $run, $actor));
    }

    public function exclude(TenantContext $context, FeeAssessmentRunService $runs, string $run, string $item): RedirectResponse
    {
        return $this->act($context, $run, fn ($school, $actor) => $runs->excludeItem($school, $run, $item, $actor));
    }

    /** UAT-C6 (UAT-FIN-20): Include anyway / Exclude from this run, for one "decision required" item. */
    public function decide(Request $request, TenantContext $context, FeeAssessmentRunService $runs, string $run, string $item): RedirectResponse
    {
        $validated = $request->validate(['decision' => ['required', 'string', 'in:include,exclude']], ['decision.*' => 'Choose "Include anyway" or "Exclude from this run".']);

        return $this->act($context, $run, fn ($school, $actor) => $runs->decideOverlap($school, $run, $item, $validated['decision'], $actor));
    }

    public function execute(TenantContext $context, FeeAssessmentRunService $runs, string $run): RedirectResponse
    {
        return $this->act($context, $run, fn ($school, $actor) => $runs->execute($school, $run, $actor));
    }

    public function resume(TenantContext $context, FeeAssessmentRunService $runs, string $run): RedirectResponse
    {
        return $this->act($context, $run, fn ($school, $actor) => $runs->resume($school, $run, $actor));
    }

    public function cancel(TenantContext $context, FeeAssessmentRunService $runs, string $run): RedirectResponse
    {
        return $this->act($context, $run, fn ($school, $actor) => $runs->cancel($school, $run, $actor));
    }

    public function void(Request $request, TenantContext $context, FeeAssessmentService $assessments, string $assessment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.charges.manage', $school);
        // ORG.8G (ADR 0076 §65): a listed reason (the note for `other`) and a fresh MFA code.
        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in(ChargeCancellationReason::CODES[ChargeCancellationReason::ASSESSMENT_VOID])],
            'reason' => ['nullable', 'required_if:reason_code,other', 'string', 'max:255'],
        ]);
        $proof = app(FreshMfaRequirement::class)->requireForAction($request, $context->actor());

        try {
            $assessments->void($school, $assessment, $context->actor(), $validated['reason_code'], $validated['reason'] ?? null, $proof);
        } catch (FeeAssessmentNotFoundException) {
            throw new NotFoundHttpException;
        } catch (FeesException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back();
    }

    /** @param callable(School, User): mixed $operation */
    private function act(TenantContext $context, string $run, callable $operation): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_assessments.run', $school);
        $actor = $context->actor();
        $url = "/app/finance/fee-runs/{$run}";

        try {
            $operation($school, $actor);
        } catch (FeeAssessmentRunNotFoundException|FeeAssessmentRunItemNotFoundException) {
            throw new NotFoundHttpException;
        } catch (FeesException $e) {
            return redirect($url)->withErrors(['action' => $e->getMessage()]);
        }

        return redirect($url);
    }

    /**
     * The distinct billing periods of a structure, in schedule order.
     *
     * @return list<array{key: string, label: string}>
     */
    private function periodsOf(FeeStructure $structure): array
    {
        $periods = [];
        foreach ($structure->lines as $line) {
            foreach ($line->installments as $installment) {
                $periods[$installment->billing_period_key] ??= ['key' => $installment->billing_period_key, 'label' => $installment->label];
            }
        }

        return array_values($periods);
    }

    /** @return callable(FeeStructure): string */
    private function structureNames(): callable
    {
        $years = AcademicYear::query()->get()->keyBy('id');
        $grades = GradeLevel::query()->get()->keyBy('id');
        $campuses = Campus::query()->get()->keyBy('id');

        return fn (FeeStructure $s) => implode(' · ', array_filter([
            $s->name,
            $years->get($s->academic_year_id)?->name,
            $grades->get($s->grade_level_id)?->name,
            $s->campus_id ? $campuses->get($s->campus_id)?->name : 'All campuses',
        ]));
    }
}
