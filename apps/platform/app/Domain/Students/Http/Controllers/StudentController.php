<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1A.5: the administrative HTTP boundary for Student identity --
 * thin by construction. Every mutation delegates to
 * App\Domain\Students\Application\StudentService (authorization-neutral,
 * Phase 1A.4); this controller's only jobs are authorization, tenant-
 * safe resolution, input validation, and response shaping. Never
 * `Student::create($request->all())`/`$model->update($request->all())`
 * -- every write uses an explicit validated whitelist.
 *
 * `$this->authorizeCapability(...)` runs on every action (matching
 * AcademicYearController/CampusController's established pattern) --
 * for mutation actions this is deliberately redundant with the
 * `capability:` route middleware already applied in routes/api.php
 * (defense in depth, the same double-check every existing mutation
 * controller in this codebase already performs).
 */
class StudentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('students.view', $school);

        $validated = $request->validate([
            'student_number' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Student::query()->orderBy('first_name')->orderBy('last_name');

        if (isset($validated['student_number'])) {
            $query->where('student_number', $validated['student_number']);
        }

        if (isset($validated['name'])) {
            $term = '%'.$validated['name'].'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term));
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Student $s) => $this->presentSummary($s))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $student): JsonResponse
    {
        $this->authorizeCapability('students.view', $school);

        $model = Student::query()->findOrFail($student);

        return response()->json(['data' => $this->presentDetail($model)]);
    }

    public function store(Request $request, School $school, StudentService $service): JsonResponse
    {
        $this->authorizeCapability('students.manage', $school);

        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
        ]);

        // StudentService::create() catches the database's own
        // unique(school_id, student_number) violation and translates it
        // to DuplicateStudentNumberException -- rendered automatically
        // by bootstrap/app.php's generic exception handler (it defines
        // getStatusCode()/errorCode()). No Rule::unique() duplicated
        // here -- the canonical normalizer/service remains final
        // authority (this checkpoint's brief, section 5/11/19).
        $student = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->presentDetail($student)], 201);
    }

    public function update(Request $request, School $school, string $student, StudentService $service): JsonResponse
    {
        $this->authorizeCapability('students.manage', $school);

        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'student_number' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'date'],
        ]);

        $updated = $service->update($model, $validated, $request->user());

        return response()->json(['data' => $this->presentDetail($updated)]);
    }

    public function changeStatus(Request $request, School $school, string $student, StudentService $service): JsonResponse
    {
        $this->authorizeCapability('students.manage', $school);

        $model = Student::query()->findOrFail($student);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $updated = $service->changeStatus($model, $validated['status'], $request->user());

        return response()->json(['data' => $this->presentDetail($updated)]);
    }

    /**
     * List/search row -- deliberately excludes `dateOfBirth`
     * (Highly Sensitive children's data,
     * docs/security/DATA-CLASSIFICATION.md: "minimized default
     * visibility... not included in broad list/summary views by
     * default"). Full detail is available via show()/store()/update(),
     * a single-record view where the capability check already gates
     * exactly the actor who needs it.
     *
     * @return array<string, mixed>
     */
    private function presentSummary(Student $student): array
    {
        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
            'status' => $student->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Student $student): array
    {
        return [
            ...$this->presentSummary($student),
            'dateOfBirth' => $student->date_of_birth->toDateString(),
            'createdAt' => $student->created_at->toIso8601String(),
            'updatedAt' => $student->updated_at->toIso8601String(),
        ];
    }
}
