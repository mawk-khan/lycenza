<?php

namespace App\Domain\AcademicStructure\Http\Controllers;

use App\Domain\AcademicStructure\Events\SubjectCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $query = Subject::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->get()->map(fn (Subject $s) => $this->present($s))->all()]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('subjects')->where('school_id', $school->id)],
            'short_name' => ['nullable', 'string', 'max:32'],
            'subject_type' => ['sometimes', Rule::in(['core', 'elective', 'co_scholastic', 'language', 'other'])],
            'academic_department_id' => ['nullable', 'string'],
        ]);

        $this->assertDepartmentBelongsToSchool($school, $validated['academic_department_id'] ?? null);

        $subject = DB::transaction(function () use ($school, $validated, $request) {
            $subject = Subject::query()->create([...$validated, 'school_id' => $school->id]);

            app(AuditRecorder::class)->school($school, 'subject.created', actor: $request->user(), subject: $subject, metadata: [
                'name' => $subject->name,
                'code' => $subject->code,
            ]);

            event(new SubjectCreated($school->id, $subject->id, $subject->name, $subject->code));

            return $subject;
        });

        return response()->json(['data' => $this->present($subject)], 201);
    }

    public function show(School $school, string $subject): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.view', $school);

        $model = Subject::query()->findOrFail($subject);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $subject): JsonResponse
    {
        $this->authorizeCapability('academics.subjects.manage', $school);

        $model = Subject::query()->findOrFail($subject);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('subjects')->where('school_id', $school->id)->ignore($model->id)],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:32'],
            'subject_type' => ['sometimes', Rule::in(['core', 'elective', 'co_scholastic', 'language', 'other'])],
            'academic_department_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (array_key_exists('academic_department_id', $validated)) {
            $this->assertDepartmentBelongsToSchool($school, $validated['academic_department_id']);
        }

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'subject.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    private function assertDepartmentBelongsToSchool(School $school, ?string $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        if (AcademicDepartment::query()->where('id', $departmentId)->doesntExist()) {
            throw ValidationException::withMessages(['academic_department_id' => ['This Academic Department does not belong to this School.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Subject $subject): array
    {
        return [
            'id' => $subject->id,
            'name' => $subject->name,
            'code' => $subject->code,
            'shortName' => $subject->short_name,
            'subjectType' => $subject->subject_type,
            'academicDepartmentId' => $subject->academic_department_id,
            'status' => $subject->status,
        ];
    }
}
