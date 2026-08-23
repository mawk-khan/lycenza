<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Events\SubjectOfferingCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0D sections 37-40. Deliberately NOT attached to Section --
 * offered to a (AcademicYear, Campus, GradeLevel), inherited by that
 * Grade's Sections (see docs/modules/ACADEMIC-STRUCTURE.md).
 */
class SubjectOfferingController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);

        $offerings = SubjectOffering::query()->where('academic_year_id', $year->id)->get();

        return response()->json(['data' => $offerings->map(fn (SubjectOffering $o) => $this->present($o))->all()]);
    }

    public function store(Request $request, School $school, string $academicYear): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);

        $validated = $request->validate([
            'campus_id' => ['required', 'string'],
            'grade_level_id' => ['required', 'string'],
            'subject_id' => ['required', 'string'],
            'is_required' => ['sometimes', 'boolean'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'weekly_periods_target' => ['nullable', 'integer', 'min:0'],
        ]);

        Campus::query()->findOrFail($validated['campus_id']);
        GradeLevel::query()->findOrFail($validated['grade_level_id']);
        Subject::query()->findOrFail($validated['subject_id']);

        $offering = DB::transaction(function () use ($school, $year, $validated, $request) {
            $offering = SubjectOffering::query()->create([
                ...$validated,
                // Explicit default (matching the migration's DB-level
                // default) -- create() does not re-hydrate DB-defaulted
                // columns onto the in-memory model automatically.
                'is_required' => $validated['is_required'] ?? true,
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
            ]);

            app(AuditRecorder::class)->school($school, 'subject_offering.created', actor: $request->user(), subject: $offering, metadata: [
                'academicYearId' => $year->id,
                'gradeLevelId' => $offering->grade_level_id,
                'subjectId' => $offering->subject_id,
            ]);

            event(new SubjectOfferingCreated(
                $school->id, $offering->id, $year->id, $offering->grade_level_id, $offering->subject_id,
            ));

            return $offering;
        });

        return response()->json(['data' => $this->present($offering)], 201);
    }

    public function show(School $school, string $subjectOffering): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $model = SubjectOffering::query()->findOrFail($subjectOffering);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $subjectOffering): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = SubjectOffering::query()->findOrFail($subjectOffering);

        $validated = $request->validate([
            'is_required' => ['sometimes', 'boolean'],
            'sequence' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'weekly_periods_target' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'subject_offering.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->id,
            'academicYearId' => $offering->academic_year_id,
            'campusId' => $offering->campus_id,
            'gradeLevelId' => $offering->grade_level_id,
            'subjectId' => $offering->subject_id,
            'isRequired' => $offering->is_required,
            'sequence' => $offering->sequence,
            'weeklyPeriodsTarget' => $offering->weekly_periods_target,
            'status' => $offering->status,
        ];
    }
}
