<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Fees\Application\Exceptions\FeeHeadNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeOptionalSelectionNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeesException;
use App\Domain\Fees\Application\Exceptions\FeeStructureLineNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeStructureNotFoundException;
use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Application\FeeOptionalSelectionService;
use App\Domain\Fees\Application\FeeStructureReadService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Http\FeeSetupPresenter;
use App\Domain\Fees\Http\TranslatesFeeSetupErrors;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FEE.1 (ADR 0062 §5-§8): the Finance -> Fee setup pages. Thin: each action
 * checks its capability, validates, calls one Application service (which
 * checks the capability again) and redirects. Reads need
 * finance.fee_structures.view; every write finance.fee_structures.manage.
 * A field-level domain rejection becomes a form error; any other domain
 * conflict (not a draft, activation lost a race, ...) is shown as an
 * `action` error on the same page.
 */
class FeeSetupController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput, TranslatesFeeSetupErrors;

    public function index(TenantContext $context, FeeStructureReadService $reads, LedgerService $ledger, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.view', $school);
        $actor = $context->actor();
        $canManage = $capabilities->canInSchool($actor, 'finance.fee_structures.manage', $school);

        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $grades = GradeLevel::query()->orderBy('sequence')->get();
        $campuses = Campus::query()->orderBy('name')->get();

        $pickers = fn (string $type) => $ledger->activeAccountsOfType($school, $type)
            ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])
            ->values()->all();

        return Inertia::render('App/Finance/FeeSetup/Index', [
            'feeHeads' => $reads->listFeeHeads($school, $actor)->map(fn (FeeHead $h) => FeeSetupPresenter::feeHead($h))->values()->all(),
            'structures' => $reads->listStructures($school, [], $actor)->map(fn (FeeStructure $s) => [
                ...FeeSetupPresenter::structure($s),
                'academicYearName' => $years->firstWhere('id', $s->academic_year_id)?->name,
                'gradeLevelName' => $grades->firstWhere('id', $s->grade_level_id)?->name,
                'campusName' => $s->campus_id ? $campuses->firstWhere('id', $s->campus_id)?->name : null,
            ])->values()->all(),
            'receivableAccounts' => $canManage ? $pickers('asset') : [],
            'revenueAccounts' => $canManage ? $pickers('income') : [],
            'academicYears' => $years->whereIn('status', ['draft', 'active'])
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'status' => $y->status])->values()->all(),
            'gradeLevels' => $grades->where('status', 'active')
                ->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])->values()->all(),
            'campuses' => $campuses->where('status', 'active')
                ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'canManage' => $canManage,
        ]);
    }

    // --- fee heads ------------------------------------------------------------

    public function storeFeeHead(Request $request, TenantContext $context, FeeHeadService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', $this->caseInsensitiveUniqueCode('fee_heads', $school)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'receivable_ledger_account_id' => ['required', 'uuid'],
            'revenue_ledger_account_id' => ['required', 'uuid'],
        ]);

        return $this->act(fn () => $service->create($school, $validated, $context->actor()), '/app/finance/fee-setup');
    }

    public function updateFeeHead(Request $request, TenantContext $context, FeeHeadService $service, string $feeHead): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'receivable_ledger_account_id' => ['sometimes', 'uuid'],
            'revenue_ledger_account_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(FeeHead::STATUSES)],
        ]);

        return $this->act(function () use ($school, $feeHead, $validated, $service, $context) {
            $fields = array_intersect_key($validated, array_flip(['name', 'description', 'receivable_ledger_account_id', 'revenue_ledger_account_id']));
            if ($fields !== []) {
                $service->update($school, $feeHead, $fields, $context->actor());
            }

            match ($validated['status'] ?? null) {
                FeeHead::STATUS_INACTIVE => $service->deactivate($school, $feeHead, $context->actor()),
                FeeHead::STATUS_ACTIVE => $service->reactivate($school, $feeHead, $context->actor()),
                default => null,
            };
        }, '/app/finance/fee-setup');
    }

    // --- structures -------------------------------------------------------------

    public function storeStructure(Request $request, TenantContext $context, FeeStructureService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'uuid'],
            'grade_level_id' => ['required', 'uuid'],
            'campus_id' => ['nullable', 'uuid'],
            'code' => ['required', 'string', 'max:32', $this->caseInsensitiveUniqueCode('fee_structures', $school, [
                'academic_year_id' => (string) $request->input('academic_year_id'),
            ])],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $structure = $this->translatingFeeSetupErrors(fn () => $service->createDraft($school, $validated, $context->actor()));

        return redirect("/app/finance/fee-setup/structures/{$structure->id}");
    }

    public function showStructure(TenantContext $context, FeeStructureReadService $reads, CapabilityResolver $capabilities, string $feeStructure): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.view', $school);
        $actor = $context->actor();

        try {
            $structure = $reads->getStructure($school, $feeStructure, $actor);
        } catch (FeeStructureNotFoundException) {
            throw new NotFoundHttpException;
        }

        $optionalLineIds = $structure->lines->where('is_optional', true)->pluck('id')->values()->all();
        $selections = $optionalLineIds === [] ? collect() : $reads->listSelections($school, [
            'fee_structure_line_ids' => $optionalLineIds,
            'status' => FeeOptionalSelection::STATUS_ACTIVE,
        ], $actor);
        $students = Student::query()->whereIn('id', $selections->pluck('student_id')->unique()->all())->get()->keyBy('id');

        $year = AcademicYear::query()->find($structure->academic_year_id);
        $successor = FeeStructure::query()->where('supersedes_fee_structure_id', $structure->id)
            ->orderByDesc('created_at')->first();

        return Inertia::render('App/Finance/FeeSetup/Structure', [
            'structure' => [
                ...FeeSetupPresenter::structure($structure, withLines: true),
                'academicYearName' => $year?->name,
                'academicYearStartsOn' => $year?->starts_on->toDateString(),
                'academicYearEndsOn' => $year?->ends_on->toDateString(),
                'gradeLevelName' => GradeLevel::query()->find($structure->grade_level_id)?->name,
                'campusName' => $structure->campus_id ? Campus::query()->find($structure->campus_id)?->name : null,
                'successorId' => $successor?->id,
                'successorStatus' => $successor?->status,
            ],
            'feeHeads' => $reads->listFeeHeads($school, $actor)->map(fn (FeeHead $h) => [
                'id' => $h->id, 'code' => $h->code, 'name' => $h->name, 'status' => $h->status,
            ])->values()->all(),
            'academicTerms' => AcademicTerm::query()->where('academic_year_id', $structure->academic_year_id)
                ->orderBy('sequence')->get()
                ->map(fn (AcademicTerm $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            'selections' => $selections->map(fn (FeeOptionalSelection $s) => [
                ...FeeSetupPresenter::selection($s),
                'studentName' => ($student = $students->get($s->student_id)) ? $this->fullName($student) : null,
                'studentNumber' => $students->get($s->student_id)?->student_number,
            ])->values()->all(),
            'frequencies' => FeeStructureLine::GENERATED_FREQUENCIES,
            'canManage' => $capabilities->canInSchool($actor, 'finance.fee_structures.manage', $school),
        ]);
    }

    public function updateStructure(Request $request, TenantContext $context, FeeStructureService $service, string $feeStructure): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        return $this->act(fn () => $service->updateDraft($school, $feeStructure, $validated, $context->actor()), $this->structureUrl($feeStructure));
    }

    public function activate(TenantContext $context, FeeStructureService $service, string $feeStructure): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        return $this->act(fn () => $service->activate($school, $feeStructure, $context->actor()), $this->structureUrl($feeStructure));
    }

    public function retire(TenantContext $context, FeeStructureService $service, string $feeStructure): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        return $this->act(fn () => $service->retire($school, $feeStructure, $context->actor()), $this->structureUrl($feeStructure));
    }

    public function successor(Request $request, TenantContext $context, FeeStructureService $service, string $feeStructure): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $successor = null;
        $response = $this->act(function () use ($school, $feeStructure, $validated, $service, $context, &$successor) {
            $successor = $service->createSuccessor($school, $feeStructure, $validated, $context->actor());
        }, $this->structureUrl($feeStructure));

        return $successor !== null ? redirect($this->structureUrl($successor->id)) : $response;
    }

    // --- lines and instalments ----------------------------------------------------

    public function storeLine(Request $request, TenantContext $context, FeeStructureService $service, string $feeStructure): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        $validated = $request->validate([
            'fee_head_id' => ['required', 'uuid'],
            'amount' => ['required', 'string'],
            'is_optional' => ['sometimes', 'boolean'],
        ]);

        return $this->act(fn () => $service->addLine($school, $feeStructure, $validated, $context->actor()), $this->structureUrl($feeStructure));
    }

    public function updateLine(Request $request, TenantContext $context, FeeStructureService $service, string $feeStructure, string $line): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        $validated = $request->validate([
            'amount' => ['sometimes', 'string'],
            'is_optional' => ['sometimes', 'boolean'],
        ]);

        return $this->act(fn () => $service->updateLine($school, $feeStructure, $line, $validated, $context->actor()), $this->structureUrl($feeStructure));
    }

    public function removeLine(TenantContext $context, FeeStructureService $service, string $feeStructure, string $line): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        return $this->act(fn () => $service->removeLine($school, $feeStructure, $line, $context->actor()), $this->structureUrl($feeStructure));
    }

    public function replaceInstallments(Request $request, TenantContext $context, FeeStructureService $service, string $feeStructure, string $line): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        $validated = $request->validate([
            'installments' => ['required', 'array', 'min:1', 'max:24'],
            'installments.*.label' => ['required', 'string', 'max:64'],
            'installments.*.billing_period_key' => ['required', 'string', 'max:32'],
            'installments.*.period_starts_on' => ['required', 'date_format:Y-m-d'],
            'installments.*.period_ends_on' => ['required', 'date_format:Y-m-d'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d'],
            'installments.*.academic_term_id' => ['nullable', 'uuid'],
            'installments.*.amount' => ['required', 'string'],
        ]);

        return $this->act(
            fn () => $service->replaceInstallments($school, $feeStructure, $line, $validated['installments'], $context->actor()),
            $this->structureUrl($feeStructure),
        );
    }

    public function generateInstallments(Request $request, TenantContext $context, FeeStructureService $service, string $feeStructure, string $line): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        $validated = $request->validate([
            'frequency' => ['required', 'string', Rule::in(FeeStructureLine::GENERATED_FREQUENCIES)],
        ]);

        return $this->act(
            fn () => $service->generateInstallments($school, $feeStructure, $line, $validated['frequency'], $context->actor()),
            $this->structureUrl($feeStructure),
        );
    }

    // --- optional selections --------------------------------------------------------

    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);
        $term = '%'.$validated['q'].'%';

        $students = Student::query()
            ->where(fn ($q) => $q->where('first_name', 'ilike', $term)
                ->orWhere('last_name', 'ilike', $term)
                ->orWhere('student_number', 'ilike', $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'studentNumber' => $s->student_number,
                'name' => $this->fullName($s),
            ])->all(),
        ]);
    }

    public function storeSelection(Request $request, TenantContext $context, FeeOptionalSelectionService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'uuid'],
            'fee_structure_line_id' => ['required', 'uuid'],
        ]);

        return $this->act(
            fn () => $service->select($school, $validated['student_id'], $validated['fee_structure_line_id'], $context->actor()),
            null,
        );
    }

    public function withdrawSelection(TenantContext $context, FeeOptionalSelectionService $service, string $selection): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('finance.fee_structures.manage', $school);

        return $this->act(fn () => $service->withdraw($school, $selection, $context->actor()), null);
    }

    // --- helpers --------------------------------------------------------------------

    /**
     * Runs one write and redirects (to $url, or back). A missing record is a
     * 404; a field-level rejection is a form error (via
     * translatingFeeSetupErrors); any other domain conflict is an `action`
     * error.
     */
    private function act(callable $operation, ?string $url): RedirectResponse
    {
        try {
            $this->translatingFeeSetupErrors($operation);
        } catch (FeeHeadNotFoundException|FeeStructureNotFoundException|FeeStructureLineNotFoundException|FeeOptionalSelectionNotFoundException) {
            throw new NotFoundHttpException;
        } catch (FeesException $e) {
            return ($url !== null ? redirect($url) : back())->withErrors(['action' => $e->getMessage()]);
        }

        return $url !== null ? redirect($url) : back();
    }

    private function structureUrl(string $feeStructureId): string
    {
        return "/app/finance/fee-setup/structures/{$feeStructureId}";
    }

    private function fullName(Student $student): string
    {
        return collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ');
    }
}
