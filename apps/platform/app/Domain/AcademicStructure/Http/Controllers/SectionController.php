<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Events\SectionCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0D sections 25-28, 36. Nested under a specific AcademicYear
 * for listing/creation -- a Section belongs to exactly one year
 * (historical-safety rule, section 28). Uniqueness across (School,
 * AcademicYear, Campus, GradeLevel, code) and the composite FKs
 * protecting cross-School references are database-enforced (the
 * migration), not re-checked here.
 */
class SectionController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);

        $sections = Section::query()->where('academic_year_id', $year->id)->orderBy('name')->get();

        return response()->json(['data' => $sections->map(fn (Section $s) => $this->present($s))->all()]);
    }

    public function store(Request $request, School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'campus_id' => ['required', 'string'],
            'grade_level_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        // SchoolScope (RLS + Eloquent global scope) already makes a
        // cross-School id 404 here -- these two lookups exist to fail
        // with a clear validation-style error rather than a raw FK
        // violation surfacing as a 500 for a genuinely-missing id.
        Campus::query()->findOrFail($validated['campus_id']);
        GradeLevel::query()->findOrFail($validated['grade_level_id']);

        // Composite uniqueness (School, AcademicYear, Campus,
        // GradeLevel, code) checked explicitly for a clean 422 --
        // Rule::unique() can't reference sibling fields validated in
        // the SAME pass this cleanly, and the migration's own unique
        // constraint remains the authoritative backstop regardless.
        if (Section::query()
            ->where('academic_year_id', $year->id)
            ->where('campus_id', $validated['campus_id'])
            ->where('grade_level_id', $validated['grade_level_id'])
            ->where('code', $validated['code'])
            ->exists()) {
            throw ValidationException::withMessages(['code' => ['A Section with this code already exists for this Grade, Campus, and Academic Year.']]);
        }

        $section = DB::transaction(function () use ($school, $year, $validated, $request) {
            $section = Section::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
            ]);

            app(AuditRecorder::class)->school($school, 'section.created', actor: $request->user(), subject: $section, metadata: [
                'academicYearId' => $year->id,
                'name' => $section->name,
            ]);

            event(new SectionCreated($school->id, $section->id, $year->id, $section->grade_level_id, $section->name));

            return $section;
        });

        return response()->json(['data' => $this->present($section)], 201);
    }

    public function show(School $school, string $section): JsonResponse
    {
        $this->authorizeCapability('academics.structure.view', $school);

        $model = Section::query()->findOrFail($section);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $section): JsonResponse
    {
        $this->authorizeCapability('academics.structure.manage', $school);

        $model = Section::query()->findOrFail($section);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'section.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Section $section): array
    {
        return [
            'id' => $section->id,
            'academicYearId' => $section->academic_year_id,
            'campusId' => $section->campus_id,
            'gradeLevelId' => $section->grade_level_id,
            'name' => $section->name,
            'code' => $section->code,
            'capacity' => $section->capacity,
            'status' => $section->status,
        ];
    }
}
