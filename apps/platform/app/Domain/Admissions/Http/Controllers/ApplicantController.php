<?php

namespace App\Domain\Admissions\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Application\ApplicantReadService;
use App\Domain\Admissions\Application\ApplicantService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 1D.5: the administrative HTTP boundary for Applicant identity --
 * thin by construction, mirroring StudentController's exact shape.
 * Every mutation delegates to
 * App\Domain\Admissions\Application\ApplicantService (authorization-
 * neutral, Phase 1D.2); every read delegates to
 * App\Domain\Admissions\Application\ApplicantReadService (Phase 1D.4).
 * No search/query logic is duplicated here.
 *
 * `$this->authorizeCapability(...)` runs on every action, deliberately
 * redundant with the `capability:` route middleware already applied to
 * mutation routes in routes/api.php (defense in depth, matching every
 * other administrative controller in this codebase).
 */
class ApplicantController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school, ApplicantReadService $reads): JsonResponse
    {
        $this->authorizeCapability('admissions.view', $school);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $reads->search($validated['name'] ?? null, $validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Applicant $a) => $this->presentSummary($a))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $applicant, ApplicantReadService $reads): JsonResponse
    {
        $this->authorizeCapability('admissions.view', $school);

        $model = $reads->detail($applicant);
        abort_if($model === null, 404);

        return response()->json(['data' => $this->presentDetail($model)]);
    }

    public function store(Request $request, School $school, ApplicantService $service): JsonResponse
    {
        $this->authorizeCapability('admissions.manage', $school);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
        ]);

        $applicant = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->presentDetail($applicant)], 201);
    }

    /**
     * Full reapplication history -- a SEPARATE nested read endpoint,
     * not embedded in show()'s response, matching
     * StudentEnrollmentController's `/students/{student}/enrollments`
     * precedent (a Student's own detail response never embeds its
     * Enrollment history either).
     */
    public function applications(School $school, string $applicant, ApplicantReadService $reads): JsonResponse
    {
        $this->authorizeCapability('admissions.view', $school);

        $model = $reads->detail($applicant);
        abort_if($model === null, 404);

        $history = $reads->applications($model);

        return response()->json(['data' => $history->map(fn (AdmissionApplication $a) => $this->presentApplicationSummary($a))->values()->all()]);
    }

    /**
     * List/search row -- deliberately excludes `dateOfBirth`, matching
     * `ApplicantReadService::search()`'s own list-level column
     * exclusion and `StudentController::presentSummary()`'s identical
     * precedent for Highly Sensitive children's data.
     *
     * @return array<string, mixed>
     */
    private function presentSummary(Applicant $applicant): array
    {
        return [
            'id' => $applicant->id,
            'firstName' => $applicant->first_name,
            'middleName' => $applicant->middle_name,
            'lastName' => $applicant->last_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Applicant $applicant): array
    {
        return [
            ...$this->presentSummary($applicant),
            'dateOfBirth' => $applicant->date_of_birth->toDateString(),
            'createdAt' => $applicant->created_at->toIso8601String(),
            'updatedAt' => $applicant->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentApplicationSummary(AdmissionApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'academicYear' => $this->presentRef($application->academicYear),
            'campus' => $this->presentRef($application->campus),
            'gradeLevel' => $this->presentRef($application->gradeLevel),
            'createdAt' => $application->created_at->toIso8601String(),
            'convertedAt' => $application->converted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRef(AcademicYear|Campus|GradeLevel $model): array
    {
        return ['id' => $model->id, 'name' => $model->name, 'code' => $model->code];
    }
}
