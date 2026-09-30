<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeAdjustmentNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeesException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeConcessionException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeSettingsException;
use App\Domain\Fees\Application\FeeConcessionReadService;
use App\Domain\Fees\Application\FeeConcessionService;
use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Fees\Http\Controllers\FeeConcessionController as ApiFeeConcessionController;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEE.3 (ADR 0062 §14, §19): Finance -> Concessions. Viewing needs
 * finance.fee_concessions.view (Highly Sensitive; every read is audited by
 * the read service); requesting and withdrawing need .request; approving,
 * rejecting, revoking and cancelling an adjustment need .approve; the
 * concession account needs finance.fee_structures.manage. Each is checked
 * here and again in the Application service; a hidden button is never the
 * only protection. The request form carries a server-issued idempotency
 * key, so a double submit replays instead of creating a second request.
 * There is no free-text note (owner decision M).
 */
class FeeConcessionController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, FeeConcessionReadService $reads, FeeSettingsService $settings, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(FeeConcessionReadService::VIEW, $school);
        $actor = $context->actor();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([...FeeConcession::STATUSES, 'all'])],
            'scope' => ['sometimes', 'nullable', 'string', Rule::in([FeeConcession::SCOPE_TARGETED, FeeConcession::SCOPE_STANDING])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $status = $validated['status'] ?? FeeConcession::STATUS_PENDING;
        $filters = array_filter(['status' => $status === 'all' ? null : $status, 'scope' => $validated['scope'] ?? null]);

        $page = $reads->list($school, $filters, (int) ($validated['page'] ?? 1), $actor);
        $students = $this->studentNames(collect($page->items())->pluck('student_id')->unique()->values()->all());

        $canViewSettings = $capabilities->canInSchool($actor, 'finance.fee_structures.view', $school);
        $canManageSettings = $capabilities->canInSchool($actor, 'finance.fee_structures.manage', $school);

        return Inertia::render('App/Finance/Concessions/Index', [
            'concessions' => $page->through(fn (FeeConcession $c) => [
                ...FeeSetupPresenter::concession($c),
                'studentName' => $students[$c->student_id] ?? null,
            ]),
            'filters' => ['status' => $status, 'scope' => $validated['scope'] ?? ''],
            'canRequest' => $capabilities->canInSchool($actor, FeeConcessionService::REQUEST, $school),
            'settings' => $canViewSettings ? [
                'concessionLedgerAccountId' => $settings->concessionAccountId($school, $actor),
                'options' => $settings->concessionAccountOptions($school, $actor)
                    ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'label' => "{$a->code} · {$a->name}"])->values()->all(),
                'canManage' => $canManageSettings,
            ] : null,
        ]);
    }

    public function create(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(FeeConcessionService::REQUEST, $school);

        $validated = $request->validate([
            'charge_id' => ['sometimes', 'nullable', 'uuid'],
            'student_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $charge = isset($validated['charge_id']) ? Charge::query()->whereNull('cancelled_at')->find($validated['charge_id']) : null;
        $student = Student::query()->find($charge->student_id ?? $validated['student_id'] ?? null);

        return Inertia::render('App/Finance/Concessions/Create', [
            'idempotencyKey' => (string) Str::uuid(),
            'charge' => $charge === null ? null : [
                'id' => $charge->id,
                'description' => $charge->description,
                'amount' => $charge->amount,
                'currency' => $charge->currency,
            ],
            'student' => $student === null ? null : ['id' => $student->id, 'name' => $this->fullName($student), 'studentNumber' => $student->student_number],
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString()])->values()->all(),
            'feeHeads' => FeeHead::query()->where('status', FeeHead::STATUS_ACTIVE)->orderBy('code')->get()
                ->map(fn (FeeHead $h) => ['id' => $h->id, 'label' => "{$h->code} · {$h->name}"])->values()->all(),
            'categories' => FeeConcession::CATEGORIES,
        ]);
    }

    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(FeeConcessionService::REQUEST, $school);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);
        $term = '%'.$validated['q'].'%';

        return response()->json(['data' => Student::query()
            ->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term)->orWhere('student_number', 'ilike', $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get()
            ->map(fn (Student $s) => ['id' => $s->id, 'name' => $this->fullName($s), 'studentNumber' => $s->student_number])
            ->values()->all()]);
    }

    public function store(Request $request, TenantContext $context, FeeConcessionService $concessions): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(FeeConcessionService::REQUEST, $school);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'scope' => ['required', 'string', Rule::in([FeeConcession::SCOPE_TARGETED, FeeConcession::SCOPE_STANDING])],
            'category' => ['required', 'string', Rule::in(FeeConcession::CATEGORIES)],
            'kind' => ['required', 'string', Rule::in([FeeConcession::KIND_FIXED, FeeConcession::KIND_PERCENTAGE])],
            'fixed_amount' => ['nullable', 'string', 'max:20'],
            'percentage' => ['nullable', 'string', 'max:8'],
            'charge_id' => ['nullable', 'uuid'],
            'student_id' => ['nullable', 'uuid'],
            'academic_year_id' => ['nullable', 'uuid'],
            'fee_head_id' => ['nullable', 'uuid'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $result = $concessions->request($school, ApiFeeConcessionController::requestData($validated), $context->actor());
        } catch (InvalidFeeConcessionException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        } catch (ChargeNotFoundException) {
            throw ValidationException::withMessages(['charge_id' => ['That charge was not found.']]);
        } catch (FeesException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect("/app/finance/concessions/{$result['concession']->id}");
    }

    public function show(TenantContext $context, FeeConcessionReadService $reads, CapabilityResolver $capabilities, string $concession): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(FeeConcessionReadService::VIEW, $school);
        $actor = $context->actor();

        try {
            ['concession' => $model, 'adjustments' => $adjustments] = $reads->get($school, $concession, $actor);
        } catch (FeeConcessionNotFoundException) {
            throw new NotFoundHttpException;
        }

        $canApprove = $capabilities->canInSchool($actor, FeeConcessionService::APPROVE, $school);
        $isRequester = $model->requested_by_user_id === $actor->id;
        $pending = $model->status === FeeConcession::STATUS_PENDING;
        $charges = Charge::query()->whereIn('id', array_filter([$model->charge_id, ...$adjustments->pluck('charge_id')->all()]))->get()->keyBy('id');
        $people = User::query()->whereIn('id', array_filter([$model->requested_by_user_id, $model->decided_by_user_id, $model->revoked_by_user_id]))->pluck('name', 'id');
        $student = Student::query()->find($model->student_id);

        return Inertia::render('App/Finance/Concessions/Show', [
            'concession' => [
                ...FeeSetupPresenter::concession($model),
                'studentName' => $student ? $this->fullName($student) : null,
                'studentNumber' => $student?->student_number,
                'academicYearName' => AcademicYear::query()->find($model->academic_year_id)?->name,
                'feeHeadLabel' => $model->fee_head_id ? FeeHead::query()->find($model->fee_head_id)?->name : null,
                'chargeDescription' => $model->charge_id ? $charges->get($model->charge_id)?->description : null,
                'requestedByName' => $people[$model->requested_by_user_id] ?? null,
                'decidedByName' => $model->decided_by_user_id ? ($people[$model->decided_by_user_id] ?? null) : null,
            ],
            'adjustments' => $adjustments->map(fn (FeeAdjustment $a) => [
                ...FeeSetupPresenter::adjustment($a),
                'chargeDescription' => $charges->get($a->charge_id)?->description,
            ])->values()->all(),
            'can' => [
                'decide' => $canApprove && $pending && ! $isRequester,
                'withdraw' => $pending && $isRequester && $capabilities->canInSchool($actor, FeeConcessionService::REQUEST, $school),
                'revoke' => $canApprove && $model->status === FeeConcession::STATUS_APPROVED && ! $model->isTargeted(),
                'cancelAdjustments' => $canApprove,
                'isRequester' => $isRequester,
            ],
        ]);
    }

    public function withdraw(TenantContext $context, FeeConcessionService $concessions, string $concession): RedirectResponse
    {
        return $this->act($context, FeeConcessionService::REQUEST, $concession, fn ($school, $actor) => $concessions->withdraw($school, $concession, $actor));
    }

    public function approve(TenantContext $context, FeeConcessionService $concessions, string $concession): RedirectResponse
    {
        return $this->act($context, FeeConcessionService::APPROVE, $concession, fn ($school, $actor) => $concessions->approve($school, $concession, $actor));
    }

    public function reject(TenantContext $context, FeeConcessionService $concessions, string $concession): RedirectResponse
    {
        return $this->act($context, FeeConcessionService::APPROVE, $concession, fn ($school, $actor) => $concessions->reject($school, $concession, $actor));
    }

    public function revoke(TenantContext $context, FeeConcessionService $concessions, string $concession): RedirectResponse
    {
        return $this->act($context, FeeConcessionService::APPROVE, $concession, fn ($school, $actor) => $concessions->revoke($school, $concession, $actor));
    }

    public function cancelAdjustment(Request $request, TenantContext $context, FeeConcessionService $concessions, string $adjustment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(FeeConcessionService::APPROVE, $school);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $concessions->cancelAdjustment($school, $adjustment, $context->actor(), $validated['reason'] ?? null);
        } catch (FeeAdjustmentNotFoundException) {
            throw new NotFoundHttpException;
        } catch (FeesException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back();
    }

    public function updateSettings(Request $request, TenantContext $context, FeeSettingsService $settings): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $validated = $request->validate(['concession_ledger_account_id' => ['required', 'uuid']]);

        try {
            $settings->setConcessionAccount($school, $validated['concession_ledger_account_id'], $context->actor());
        } catch (InvalidFeeSettingsException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }

        return back();
    }

    /** @param callable(School, User): mixed $operation */
    private function act(TenantContext $context, string $capability, string $concession, callable $operation): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability($capability, $school);
        $url = "/app/finance/concessions/{$concession}";

        try {
            $operation($school, $context->actor());
        } catch (FeeConcessionNotFoundException) {
            throw new NotFoundHttpException;
        } catch (FeesException $e) {
            return redirect($url)->withErrors(['action' => $e->getMessage()]);
        }

        return redirect($url);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function studentNames(array $ids): array
    {
        return Student::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Student $s) => [$s->id => $this->fullName($s)])->all();
    }

    private function fullName(Student $student): string
    {
        return collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ');
    }
}
