<?php

namespace App\Domain\Examinations\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Application\ExaminationService;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Phase 0H.4A -- the Examination administrative API. Exactly FOUR
 * operations: list and create nested under the owning AcademicYear,
 * show and update flat -- matching AcademicTermController's established
 * "nested for collection, flat for instance" convention.
 *
 * An Examination is a WINDOW, not a paper. There is deliberately NO
 * paper, scheduling, marks, grade-scale, result, report-card,
 * transcript, search, bulk or reporting endpoint here; the per-Subject
 * entity is a future ExaminationPaper (Phase 0H.4B, its own
 * architecture gate).
 *
 * Deliberately NO delete, NO activate and NO deactivate route.
 * `status` moves through the ordinary PATCH exactly like every other
 * Academic Structure reference entity (Section, SubjectOffering, Room,
 * Subject, GradeLevel, SyllabusUnit) -- none of which has a lifecycle
 * route. The entities that DO have activate/deactivate here
 * (AcademicYear, TimetablePeriod, TimetableEntry) each re-validate a
 * real invariant on activation; an Examination cannot conflict with
 * anything on reactivation because its unique code index is
 * unconditional, so an inactive Examination already reserves its code.
 *
 * Thin by construction: authorize -> normalize -> validate -> delegate
 * to App\Domain\Examinations\Application\ExaminationService -> present.
 * Every invariant, the AcademicYear range check, the transaction and
 * both audit writes live in the service; this class performs no
 * Examination write of its own.
 *
 * `school_id` and `academic_year_id` are NEVER accepted from the
 * request: the School comes from the route binding + membership
 * middleware and the AcademicYear from the trusted nested route
 * (CLAUDE.md rules 19/68).
 *
 * No `Idempotency-Key` (CLAUDE.md rule 29, evaluated per endpoint):
 * duplicate creation is already prevented by
 * `examinations_year_code_ci_unique`, and PATCH is naturally
 * idempotent -- so no Examination response is ever stored in
 * `api_idempotency_keys` and rule 36's stored-response review does not
 * arise.
 *
 * Data-minimization note: an Examination stores no Student, Guardian,
 * Employee, teacher or invigilator identity at all, which is precisely
 * why it is classified Confidential rather than Sensitive
 * (docs/security/DATA-CLASSIFICATION.md). Nothing in this controller
 * may introduce one.
 */
class ExaminationController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('examinations.definitions.view', $school);

        // Resolved through the tenant-scoped query (SchoolScope + RLS),
        // so another School's AcademicYear id is a clean 404 here rather
        // than an empty list that silently implies it exists.
        $year = AcademicYear::query()->findOrFail($academicYear);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(Examination::STATUSES)],
        ]);

        $examinations = Examination::query()
            ->where('academic_year_id', $year->id)
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            // Deterministic: the window's own chronology, then
            // case-insensitive code as a stable tie-break.
            ->orderBy('starts_on')
            ->orderByRaw('upper(code)')
            ->get();

        return response()->json([
            'data' => $examinations->map(fn (Examination $e) => $this->present($e))->all(),
        ]);
    }

    public function store(Request $request, School $school, string $academicYear, ExaminationService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.definitions.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);

        // Normalize BEFORE validation so Rule::unique compares the same
        // uppercased form the database actually stores -- an ordinary
        // duplicate returns a clean 422 rather than a raw constraint
        // violation (CLAUDE.md rule 74).
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', $this->uniqueCodeRule($school, $year->id)],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Examination::STATUSES)],
        ]);

        $examination = $service->create($school, $year, $validated, $request->user());

        return response()->json(['data' => $this->present($examination)], 201);
    }

    public function show(School $school, string $examination): JsonResponse
    {
        $this->authorizeCapability('examinations.definitions.view', $school);

        $model = Examination::query()->findOrFail($examination);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $examination, ExaminationService $service): JsonResponse
    {
        $this->authorizeCapability('examinations.definitions.manage', $school);

        $model = Examination::query()->findOrFail($examination);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:64', $this->uniqueCodeRule($school, $model->academic_year_id, $model->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Examination::STATUSES)],
        ]);

        $updated = $service->update($school, $model, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * Case-insensitive uniqueness within ONE AcademicYear. The
     * authoritative guarantee is the database's own unconditional
     * `examinations_year_code_ci_unique` expression index; this rule
     * exists so the ordinary duplicate submission returns a clean 422
     * instead of a raw exception. `normalizeCodeInput()` has already
     * uppercased the input, and the model uppercases on assignment, so
     * a plain column comparison matches what is stored.
     */
    private function uniqueCodeRule(School $school, string $academicYearId, ?string $ignoreId = null): Unique
    {
        $rule = Rule::unique('examinations', 'code')
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId);

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    /**
     * `schoolId` is deliberately not exposed (it is the route's own
     * context), and there is no AcademicTerm, Campus, GradeLevel,
     * Student or paper data to expose.
     *
     * @return array<string, mixed>
     */
    private function present(Examination $examination): array
    {
        return [
            'id' => $examination->id,
            'academicYearId' => $examination->academic_year_id,
            'code' => $examination->code,
            'name' => $examination->name,
            'startsOn' => $examination->starts_on->toDateString(),
            'endsOn' => $examination->ends_on->toDateString(),
            'status' => $examination->status,
        ];
    }
}
