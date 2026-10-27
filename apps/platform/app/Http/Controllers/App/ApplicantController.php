<?php

namespace App\Http\Controllers\App;

use App\Domain\Admissions\Application\ApplicantReadService;
use App\Domain\Admissions\Application\ApplicantService;
use App\Domain\Admissions\Application\Exceptions\AdmissionCorrectionNotPermittedException;
use App\Domain\Admissions\Application\Exceptions\AdmissionRecordChangedException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\DateOfBirth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1D.6: session-authenticated Inertia pages for Applicant
 * identity -- mirrors StudentController's exact shape (NOT the
 * Bearer-token JSON API under /api/v1, which remains the separate
 * Phase 1D.5 surface). Every mutation delegates to
 * App\Domain\Admissions\Application\ApplicantService (Phase 1D.2,
 * unmodified); every read delegates to
 * App\Domain\Admissions\Application\ApplicantReadService (Phase 1D.4,
 * unmodified) -- the SAME services the Phase 1D.5 JSON API controller
 * already uses. No search/query logic is duplicated here.
 */
class ApplicantController extends Controller
{
    public function index(Request $request, TenantContext $context, CampusScopeResolver $scopes, ApplicantReadService $reads): Response
    {
        $school = $context->requireSchool();
        // ORG.7D-3: a declared whole-School-only operation -- authorized by the
        // route's ordinary `capability:admissions.view` floor (ADR 0076 §39).

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        // ApplicantReadService::search() is typed to the
        // LengthAwarePaginator CONTRACT but always returns the concrete
        // Eloquent paginator at runtime -- StudentEnrollmentController::index()'s
        // identical annotation-only PHPStan narrowing (the concrete
        // class carries ->through(), the contract does not).
        /** @var LengthAwarePaginator<int, Applicant> $paginator */
        $paginator = $reads->search($validated['name'] ?? null, 20)->withQueryString();

        return Inertia::render('App/Admissions/Applicants/Index', [
            'applicants' => $paginator->through(fn (Applicant $a) => $this->presentSummary($a)),
            'filters' => [
                'name' => $validated['name'] ?? '',
            ],
            'canManage' => $scopes->campusScope($context->actor(), 'admissions.manage', $school)->isWholeSchool(),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        // ORG.7D-3: a declared whole-School-only operation -- authorized by the
        // route's ordinary `capability:admissions.manage` floor (ADR 0076 §39).

        // SIS 3.2: the School's date bounds the date-of-birth input.
        return Inertia::render('App/Admissions/Applicants/Create', ['today' => SchoolTimezone::today($school)]);
    }

    public function store(Request $request, TenantContext $context, ApplicantService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        // ORG.7D-3: a declared whole-School-only operation -- authorized by the
        // route's ordinary `capability:admissions.manage` floor (ADR 0076 §39).

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => DateOfBirth::rules($school),
        ], DateOfBirth::messages());

        $applicant = $service->create($school, $validated, $context->actor());

        return redirect("/app/admissions/applicants/{$applicant->id}");
    }

    public function show(TenantContext $context, CampusScopeResolver $scopes, ApplicantReadService $reads, string $applicant): Response
    {
        $school = $context->requireSchool();
        // ORG.7D-3: a declared whole-School-only operation -- authorized by the
        // route's ordinary `capability:admissions.view` floor (ADR 0076 §39).

        $model = $reads->detail($applicant);
        abort_if($model === null, 404);

        $history = $reads->applications($model);

        $canManage = $scopes->campusScope($context->actor(), 'admissions.manage', $school)->isWholeSchool();
        $admitted = $history->contains(fn (AdmissionApplication $a) => $a->status === 'converted');

        return Inertia::render('App/Admissions/Applicants/Show', [
            'applicant' => $this->presentDetail($model),
            'applications' => $history->map(fn (AdmissionApplication $a) => $this->presentApplicationSummary($a))->values()->all(),
            'canManage' => $canManage,
            // SIS 3.1: identity is correctable until an application is converted.
            'canCorrect' => $canManage && ! $admitted,
            'admitted' => $admitted,
        ]);
    }

    /**
     * SIS 3.1: the identity correction form. A declared whole-School-only
     * operation (Applicants have no Campus), authorized by the route's
     * `capability:admissions.manage` floor.
     */
    public function edit(TenantContext $context, ApplicantReadService $reads, string $applicant): Response|RedirectResponse
    {
        $school = $context->requireSchool();

        $model = $reads->detail($applicant);
        abort_if($model === null, 404);

        if ($reads->applications($model)->contains(fn (AdmissionApplication $a) => $a->status === 'converted')) {
            return redirect("/app/admissions/applicants/{$model->id}")
                ->withErrors(['correction' => AdmissionCorrectionNotPermittedException::applicantAdmitted()->getMessage()]);
        }

        return Inertia::render('App/Admissions/Applicants/Edit', [
            'applicant' => [
                ...$this->presentDetail($model),
                'updatedAt' => $model->updated_at->toIso8601String(),
            ],
            'today' => SchoolTimezone::today($school),
        ]);
    }

    public function update(Request $request, TenantContext $context, ApplicantReadService $reads, ApplicantService $service, string $applicant): RedirectResponse
    {
        $school = $context->requireSchool();

        $model = $reads->detail($applicant);
        abort_if($model === null, 404);

        // The same rules as creation (store()) -- never weaker.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => DateOfBirth::rules($school),
            'expected_updated_at' => ['required', 'string', 'max:64'],
        ], DateOfBirth::messages());

        try {
            $service->correct($model, $validated, $validated['expected_updated_at'], $context->actor());
        } catch (AdmissionCorrectionNotPermittedException|AdmissionRecordChangedException $e) {
            throw ValidationException::withMessages(['correction' => [$e->getMessage()]]);
        }

        return redirect("/app/admissions/applicants/{$model->id}")->with('flash', 'Applicant details corrected.');
    }

    /**
     * List row -- deliberately excludes `dateOfBirth`, matching
     * ApplicantReadService::search()'s own list-level column exclusion
     * (Highly Sensitive data of a minor).
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
            'academicYear' => ['id' => $application->academicYear->id, 'name' => $application->academicYear->name],
            'campus' => ['id' => $application->campus->id, 'name' => $application->campus->name],
            'gradeLevel' => ['id' => $application->gradeLevel->id, 'name' => $application->gradeLevel->name],
            'createdAt' => $application->created_at->toIso8601String(),
        ];
    }
}
