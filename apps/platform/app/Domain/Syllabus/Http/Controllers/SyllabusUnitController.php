<?php

namespace App\Domain\Syllabus\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Phase 0H.3A -- the Syllabus Unit administrative API. Exactly FOUR
 * operations: list and create nested under the owning SubjectOffering,
 * show and update flat. Deliberately NO delete, NO activate, NO
 * deactivate, NO reorder, NO bulk update, and no grading/delivery/
 * lesson-planning endpoint of any kind.
 *
 * `status` moves through the ordinary PATCH, matching every other
 * Academic Structure reference entity (Section, SubjectOffering, Room,
 * Subject, GradeLevel, AcademicDepartment) -- none of which has a
 * lifecycle route. The entities that DO have activate/deactivate
 * commands here (AcademicYear, TimetablePeriod, TimetableEntry) each
 * re-validate a real invariant on activation; a SyllabusUnit cannot
 * conflict with anything on reactivation because its unique code index
 * is unconditional, so an inactive unit already reserves its code.
 *
 * Thin by design (CLAUDE.md rule 76): validate/normalize -> write ->
 * audit -> present. No Application service exists because this entity
 * has no date-range invariant, no overlap validation, no lock, no
 * multi-row mutation, no state machine and no cross-domain
 * coordination.
 *
 * Data-minimization note: a SyllabusUnit stores no Student, Guardian,
 * Employee or teacher identity at all, which is precisely why it is
 * classified Confidential rather than Sensitive
 * (docs/security/DATA-CLASSIFICATION.md). Nothing in this controller
 * may introduce one.
 */
class SyllabusUnitController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(School $school, string $subjectOffering): JsonResponse
    {
        $this->authorizeCapability('syllabus.view', $school);

        // Resolved through the tenant-scoped query (SchoolScope + RLS),
        // so another School's Offering id is a clean 404 here rather
        // than an empty list that silently implies it exists.
        $offering = SubjectOffering::query()->findOrFail($subjectOffering);

        $units = SyllabusUnit::query()
            ->where('subject_offering_id', $offering->id)
            ->orderBy('sequence')
            ->orderByRaw('upper(code)')
            ->get();

        return response()->json([
            'data' => $units->map(fn (SyllabusUnit $unit) => $this->present($unit))->all(),
        ]);
    }

    public function store(Request $request, School $school, string $subjectOffering, AuditRecorder $audit): JsonResponse
    {
        $this->authorizeCapability('syllabus.manage', $school);

        $offering = SubjectOffering::query()->findOrFail($subjectOffering);

        // Normalize BEFORE validation so Rule::unique compares the same
        // uppercased form the database actually stores -- an ordinary
        // duplicate returns a clean 422 rather than a raw constraint
        // violation (CLAUDE.md rule 74).
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', $this->uniqueCodeRule($school, $offering)],
            'title' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(SyllabusUnit::STATUSES)],
        ]);

        $unit = DB::transaction(function () use ($school, $offering, $validated, $request, $audit) {
            $unit = SyllabusUnit::query()->create([
                'school_id' => $school->id,
                'subject_offering_id' => $offering->id,
                'code' => $validated['code'],
                'title' => $validated['title'],
                'sequence' => $validated['sequence'],
                'status' => $validated['status'] ?? 'active',
            ]);

            // Bounded metadata: ids, code and sequence only. `title` is
            // deliberately excluded from audit VALUES throughout this
            // controller -- an audit row must not become a second copy
            // of curriculum content.
            $audit->school($school, 'syllabus.unit.created', actor: $request->user(), subject: $unit, metadata: [
                'unitId' => $unit->id,
                'subjectOfferingId' => $unit->subject_offering_id,
                'code' => $unit->code,
                'sequence' => $unit->sequence,
            ]);

            return $unit;
        });

        return response()->json(['data' => $this->present($unit)], 201);
    }

    public function show(School $school, string $syllabusUnit): JsonResponse
    {
        $this->authorizeCapability('syllabus.view', $school);

        $unit = SyllabusUnit::query()->findOrFail($syllabusUnit);

        return response()->json(['data' => $this->present($unit)]);
    }

    public function update(Request $request, School $school, string $syllabusUnit, AuditRecorder $audit): JsonResponse
    {
        $this->authorizeCapability('syllabus.manage', $school);

        $unit = SyllabusUnit::query()->findOrFail($syllabusUnit);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:64', $this->uniqueCodeRule($school, $unit->subject_offering_id, $unit->id)],
            'title' => ['sometimes', 'string', 'max:255'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(SyllabusUnit::STATUSES)],
        ]);

        $before = $unit->only(array_keys($validated));

        DB::transaction(function () use ($school, $unit, $validated, $before, $request, $audit) {
            $unit->update($validated);

            $audit->school($school, 'syllabus.unit.updated', actor: $request->user(), subject: $unit, metadata: [
                'unitId' => $unit->id,
                'subjectOfferingId' => $unit->subject_offering_id,
                // Field NAMES only for the full change set...
                'changedFields' => array_keys($validated),
                // ...and before/after VALUES for the three bounded,
                // non-content fields. `title` never appears here.
                'before' => $this->auditableValues($before),
                'after' => $this->auditableValues($validated),
            ]);
        });

        return response()->json(['data' => $this->present($unit->refresh())]);
    }

    /**
     * Case-insensitive uniqueness within ONE SubjectOffering. The
     * authoritative guarantee is the database's own
     * `syllabus_units_offering_code_ci_unique` expression index; this
     * rule exists so the ordinary duplicate submission returns a clean
     * 422 instead of a raw exception. `normalizeCodeInput()` has
     * already uppercased the input, and the model uppercases on
     * assignment, so a plain column comparison matches what is stored.
     */
    private function uniqueCodeRule(School $school, SubjectOffering|string $offering, ?string $ignoreId = null): Unique
    {
        $offeringId = $offering instanceof SubjectOffering ? $offering->id : $offering;

        $rule = Rule::unique('syllabus_units', 'code')
            ->where('school_id', $school->id)
            ->where('subject_offering_id', $offeringId);

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    /**
     * Restricts an audit value snapshot to the bounded, non-content
     * fields. `title` is curriculum prose and is reported by name only.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditableValues(array $values): array
    {
        return array_intersect_key($values, array_flip(['code', 'sequence', 'status']));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SyllabusUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'subjectOfferingId' => $unit->subject_offering_id,
            'code' => $unit->code,
            'title' => $unit->title,
            'sequence' => $unit->sequence,
            'status' => $unit->status,
        ];
    }
}
