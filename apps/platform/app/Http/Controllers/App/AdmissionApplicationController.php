<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Application\AdmissionApplicationReadService;
use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Application\Exceptions\AdmissionApplicationAlreadyConvertedException;
use App\Domain\Admissions\Application\Exceptions\AdmissionGuardianSelectionRequiredException;
use App\Domain\Admissions\Application\Exceptions\CrossSchoolAdmissionReferenceException;
use App\Domain\Admissions\Application\Exceptions\IncompatibleConversionSectionException;
use App\Domain\Admissions\Application\Exceptions\InvalidAdmissionApplicationTransitionException;
use App\Domain\Admissions\Application\Exceptions\OpenAdmissionApplicationExistsException;
use App\Domain\Admissions\Application\GuardianConversionInstruction;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Guardians\Application\Exceptions\CrossSchoolRelationshipException;
use App\Domain\Guardians\Application\Exceptions\DuplicateRelationshipException;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Application\Exceptions\ActiveEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Phase 1D.6: session-authenticated Inertia pages for AdmissionApplication
 * -- directory, creation (nested under an Applicant, mirroring
 * StudentEnrollmentController's "create nested under a Student"
 * convention), lifecycle transitions, and accepted -> converted
 * conversion. Every mutation delegates to the SAME Phase 1D.2/1D.3
 * Application-layer services (AdmissionApplicationService /
 * AdmissionConversionService) the Phase 1D.5 JSON API controller
 * already uses, never duplicated or reimplemented here; every
 * non-trivial read delegates to AdmissionApplicationReadService (Phase
 * 1D.4).
 *
 * Every domain exception a user can plausibly trigger is translated to
 * Laravel's ValidationException (StudentEnrollmentController's exact
 * pattern) so Inertia's `form.errors` renders it inline -- unlike the
 * /api/v1 JSON surface, this /app surface has no automatic exception
 * envelope, so every reachable domain exception is caught explicitly
 * here.
 *
 * searchGuardians()/candidateGuardians() are a narrow, deliberate
 * exception to "reuse an existing endpoint": the existing
 * StudentGuardianRelationshipController equivalents live under
 * /app/students/{student}/guardians/... and require BOTH
 * students.manage and guardians.manage -- structurally wrong here
 * (conversion has no Student yet, and every other Admissions action
 * gates on admissions.manage alone, never transitively requiring the
 * capabilities of the services it composes). These two actions reuse
 * the IDENTICAL query logic
 * (Guardian::query() name search / GuardianContactService::
 * findCandidatesBySchool()), gated only by admissions.manage -- see
 * docs/admissions/PHASE-1D-6-ADMINISTRATIVE-UI.md.
 */
class AdmissionApplicationController extends Controller
{
    use AuthorizesCapability;

    // --- Directory ------------------------------------------------------

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, AdmissionApplicationReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'submitted', 'accepted', 'rejected', 'withdrawn', 'converted'])],
            'academic_year_id' => ['sometimes', 'uuid'],
            'campus_id' => ['sometimes', 'uuid'],
            'grade_level_id' => ['sometimes', 'uuid'],
            'applicant_name' => ['sometimes', 'string', 'max:255'],
        ]);

        /** @var LengthAwarePaginator<int, AdmissionApplication> $paginator */
        $paginator = $reads->directory($validated, 20)->withQueryString();

        return Inertia::render('App/Admissions/Index', [
            'applications' => $paginator->through(fn (AdmissionApplication $a) => $this->presentSummary($a)),
            'filters' => [
                'status' => $validated['status'] ?? '',
                'academic_year_id' => $validated['academic_year_id'] ?? '',
                'campus_id' => $validated['campus_id'] ?? '',
                'grade_level_id' => $validated['grade_level_id'] ?? '',
                'applicant_name' => $validated['applicant_name'] ?? '',
            ],
            'academicYears' => $this->academicYearOptions(),
            'campuses' => $this->campusOptions(),
            'gradeLevels' => $this->gradeLevelOptions(),
            'canManage' => $capabilities->canInSchool($context->actor(), 'admissions.manage', $school),
        ]);
    }

    // --- Create (nested under an Applicant) ------------------------------

    public function create(TenantContext $context, string $applicant): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.manage', $school);

        $applicantModel = Applicant::query()->findOrFail($applicant);

        return Inertia::render('App/Admissions/Create', [
            'applicant' => $this->presentApplicantRef($applicantModel),
            'academicYears' => $this->academicYearOptions(),
            'campuses' => $this->campusOptions(),
            'gradeLevels' => $this->gradeLevelOptions(),
        ]);
    }

    public function store(Request $request, TenantContext $context, AdmissionApplicationService $service, string $applicant): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.manage', $school);

        $applicantModel = Applicant::query()->findOrFail($applicant);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            'campus_id' => ['required', 'uuid', Rule::exists('campuses', 'id')->where('school_id', $school->id)],
            'grade_level_id' => ['required', 'uuid', Rule::exists('grade_levels', 'id')->where('school_id', $school->id)],
        ]);

        $academicYear = AcademicYear::query()->findOrFail($validated['academic_year_id']);
        $campus = Campus::query()->findOrFail($validated['campus_id']);
        $gradeLevel = GradeLevel::query()->findOrFail($validated['grade_level_id']);

        try {
            $application = $service->create($applicantModel, $academicYear, $campus, $gradeLevel, $context->actor());
        } catch (CrossSchoolAdmissionReferenceException|OpenAdmissionApplicationExistsException $e) {
            // CrossSchoolAdmissionReferenceException is defense-in-depth
            // only -- the Rule::exists() checks above already make it
            // unreachable via this form (StudentEnrollmentController::store()'s
            // identical reasoning for section_id).
            throw ValidationException::withMessages(['grade_level_id' => [$e->getMessage()]]);
        }

        return redirect("/app/admissions/{$application->id}");
    }

    // --- Directory / detail -----------------------------------------------

    public function show(TenantContext $context, CapabilityResolver $capabilities, AdmissionApplicationReadService $reads, string $admissionApplication): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.view', $school);

        $model = $reads->detail($admissionApplication);
        abort_if($model === null, 404);

        $canManage = $capabilities->canInSchool($context->actor(), 'admissions.manage', $school);

        return Inertia::render('App/Admissions/Show', [
            'application' => $this->presentDetail($model),
            'canManage' => $canManage,
            'canViewStudents' => $capabilities->canInSchool($context->actor(), 'students.view', $school),
            // Only the accepted state ever needs a Section picker -- a
            // UI convenience only (AdmissionConversionService remains
            // authoritative regardless), matching
            // StudentEnrollmentController::sectionOptions()'s
            // `onlyEligible` convenience-filtering precedent.
            'compatibleSections' => ($canManage && $model->status === 'accepted') ? $this->compatibleSectionOptions($model) : [],
        ]);
    }

    // --- Lifecycle ------------------------------------------------------

    public function submit(TenantContext $context, AdmissionApplicationService $service, string $admissionApplication): RedirectResponse
    {
        return $this->transition($context, $admissionApplication, fn (AdmissionApplication $a) => $service->submit($a, $context->actor()));
    }

    public function accept(Request $request, TenantContext $context, AdmissionApplicationService $service, string $admissionApplication): RedirectResponse
    {
        $validated = $request->validate(['decision_note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return $this->transition($context, $admissionApplication, fn (AdmissionApplication $a) => $service->accept($a, $validated['decision_note'] ?? null, $context->actor()));
    }

    public function reject(Request $request, TenantContext $context, AdmissionApplicationService $service, string $admissionApplication): RedirectResponse
    {
        $validated = $request->validate(['decision_note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return $this->transition($context, $admissionApplication, fn (AdmissionApplication $a) => $service->reject($a, $validated['decision_note'] ?? null, $context->actor()));
    }

    public function withdraw(TenantContext $context, AdmissionApplicationService $service, string $admissionApplication): RedirectResponse
    {
        return $this->transition($context, $admissionApplication, fn (AdmissionApplication $a) => $service->withdraw($a, $context->actor()));
    }

    /**
     * Shared shape for the four lifecycle actions above -- each still
     * calls its OWN named AdmissionApplicationService method (never a
     * generic status setter). `status` is a synthetic error-bag key (no
     * real form field backs these one-click actions), matching
     * StudentEnrollmentController::transition()'s identical use of
     * `ends_on` for its own one-field lifecycle actions -- the implicit
     * `back()` redirect (Referer) lands the actor back on the Show page
     * they clicked the action from either way.
     *
     * @param  callable(AdmissionApplication): AdmissionApplication  $apply
     */
    private function transition(TenantContext $context, string $admissionApplication, callable $apply): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.manage', $school);

        $model = AdmissionApplication::query()->findOrFail($admissionApplication);

        try {
            $apply($model);
        } catch (InvalidAdmissionApplicationTransitionException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return redirect("/app/admissions/{$model->id}");
    }

    // --- Conversion -------------------------------------------------------

    public function convert(Request $request, TenantContext $context, AdmissionConversionService $service, string $admissionApplication): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.manage', $school);

        $model = AdmissionApplication::query()->findOrFail($admissionApplication);

        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:255'],
            'section_id' => ['required', 'uuid', Rule::exists('sections', 'id')->where('school_id', $school->id)],
            'roll_number' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],

            'guardian' => ['sometimes', 'nullable', 'array'],
            'guardian.mode' => ['required_with:guardian', Rule::in(['create', 'link_existing'])],
            'guardian.relationship_type' => ['required_with:guardian', Rule::enum(RelationshipType::class)],
            'guardian.is_legal_guardian' => ['sometimes', 'boolean'],
            'guardian.is_emergency_contact' => ['sometimes', 'boolean'],
            'guardian.is_authorized_pickup' => ['sometimes', 'boolean'],
            'guardian.first_name' => ['required_if:guardian.mode,create', 'string', 'max:255'],
            'guardian.middle_name' => ['nullable', 'string', 'max:255'],
            'guardian.last_name' => ['nullable', 'string', 'max:255'],
            'guardian.contact_type' => ['sometimes', 'nullable', Rule::enum(ContactType::class)],
            'guardian.contact_value' => ['required_with:guardian.contact_type', 'nullable', 'string', 'max:255'],
            'guardian.guardian_id' => ['required_if:guardian.mode,link_existing', 'uuid', Rule::exists('guardians', 'id')->where('school_id', $school->id)],
        ]);

        $section = Section::query()->findOrFail($validated['section_id']);
        $guardianInstruction = $this->buildGuardianInstruction($validated['guardian'] ?? null);

        try {
            $result = $service->convert(
                $model,
                $validated['student_number'],
                $section,
                $validated['roll_number'],
                $validated['starts_on'],
                $guardianInstruction,
                $context->actor(),
            );
        } catch (IncompatibleConversionSectionException|ActiveEnrollmentConflictException|CrossSchoolEnrollmentException $e) {
            throw ValidationException::withMessages(['section_id' => [$e->getMessage()]]);
        } catch (AdmissionApplicationAlreadyConvertedException|InvalidAdmissionApplicationTransitionException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        } catch (DuplicateStudentNumberException $e) {
            throw ValidationException::withMessages(['student_number' => [$e->getMessage()]]);
        } catch (DuplicateEnrollmentRollNumberException|InvalidEnrollmentRollNumberException $e) {
            throw ValidationException::withMessages(['roll_number' => [$e->getMessage()]]);
        } catch (InvalidEnrollmentDateRangeException $e) {
            throw ValidationException::withMessages(['starts_on' => [$e->getMessage()]]);
        } catch (AdmissionGuardianSelectionRequiredException $e) {
            throw ValidationException::withMessages(['guardian.contact_value' => [$e->getMessage()]]);
        } catch (CrossSchoolRelationshipException|DuplicateRelationshipException $e) {
            throw ValidationException::withMessages(['guardian.guardian_id' => [$e->getMessage()]]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['guardian.contact_value' => [$e->getMessage()]]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'guardian.contact_value' => ['This contact conflicts with an existing record for this Guardian.'],
            ]);
        }

        return redirect("/app/admissions/{$result->application->id}");
    }

    private function buildGuardianInstruction(?array $guardian): ?GuardianConversionInstruction
    {
        if ($guardian === null || $guardian === []) {
            return null;
        }

        $relationshipType = RelationshipType::from($guardian['relationship_type']);
        $isLegalGuardian = $guardian['is_legal_guardian'] ?? false;
        $isEmergencyContact = $guardian['is_emergency_contact'] ?? false;
        $isAuthorizedPickup = $guardian['is_authorized_pickup'] ?? false;

        if ($guardian['mode'] === 'create') {
            return GuardianConversionInstruction::create(
                firstName: $guardian['first_name'],
                middleName: $guardian['middle_name'] ?? null,
                lastName: $guardian['last_name'] ?? null,
                relationshipType: $relationshipType,
                contactType: isset($guardian['contact_type']) ? ContactType::from($guardian['contact_type']) : null,
                contactValue: $guardian['contact_value'] ?? null,
                isLegalGuardian: $isLegalGuardian,
                isEmergencyContact: $isEmergencyContact,
                isAuthorizedPickup: $isAuthorizedPickup,
            );
        }

        $existingGuardian = Guardian::query()->findOrFail($guardian['guardian_id']);

        return GuardianConversionInstruction::linkExisting(
            existingGuardian: $existingGuardian,
            relationshipType: $relationshipType,
            isLegalGuardian: $isLegalGuardian,
            isEmergencyContact: $isEmergencyContact,
            isAuthorizedPickup: $isAuthorizedPickup,
        );
    }

    // --- Guardian-picker adapter (see class docblock) ----------------------

    public function searchGuardians(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.manage', $school);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);

        $term = '%'.$validated['q'].'%';
        $guardians = Guardian::query()
            ->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $guardians->map(fn (Guardian $g) => $this->presentGuardianCandidate($g))->all(),
        ]);
    }

    public function candidateGuardians(Request $request, TenantContext $context, GuardianContactService $service): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('admissions.manage', $school);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ContactType::class)],
            'value' => ['required', 'string', 'max:255'],
        ]);

        try {
            $candidates = $service->findCandidatesBySchool($school, ContactType::from($validated['type']), $validated['value']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => [$e->getMessage()]]);
        }

        return response()->json([
            'data' => $candidates->map(fn (Guardian $g) => $this->presentGuardianCandidate($g))->all(),
        ]);
    }

    // --- Reference data for filters/pickers --------------------------------

    /**
     * Sections whose academic context exactly matches this
     * AdmissionApplication's committed AcademicYear/Campus/GradeLevel
     * -- AdmissionConversionService::convert() enforces this same match
     * authoritatively regardless (IncompatibleConversionSectionException);
     * this is a UI convenience to steer staff toward a valid choice, not
     * a second source of truth.
     *
     * @return array<int, array<string, mixed>>
     */
    private function compatibleSectionOptions(AdmissionApplication $application): array
    {
        return Section::query()
            ->with(['academicYear', 'campus', 'gradeLevel'])
            ->where('academic_year_id', $application->academic_year_id)
            ->where('campus_id', $application->campus_id)
            ->where('grade_level_id', $application->grade_level_id)
            ->where('status', 'active')
            ->get()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name} · {$s->academicYear->name}",
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function academicYearOptions(): array
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get()
            ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function campusOptions(): array
    {
        return Campus::query()->orderBy('name')->get()
            ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function gradeLevelOptions(): array
    {
        return GradeLevel::query()->orderBy('sequence')->get()
            ->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])
            ->all();
    }

    // --- Presentation -----------------------------------------------------

    /**
     * List row -- deliberately excludes `decisionNote` (Phase 1D.4
     * policy: list excludes, detail includes).
     *
     * @return array<string, mixed>
     */
    private function presentSummary(AdmissionApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'applicant' => $this->presentApplicantRef($application->applicant),
            'academicYear' => $this->presentRef($application->academicYear),
            'campus' => $this->presentRef($application->campus),
            'gradeLevel' => $this->presentRef($application->gradeLevel),
            'createdAt' => $application->created_at->toIso8601String(),
            'convertedAt' => $application->converted_at?->toIso8601String(),
        ];
    }

    /**
     * `convertedStudentId`/`convertedStudentEnrollmentId` stay bare ids
     * only -- never a broader converted-Student projection (name/
     * number), exactly matching the Phase 1D.5 JSON API's identical
     * privacy boundary: an admissions.view actor does not necessarily
     * hold students.view, so this page never re-exposes Student PII;
     * `canViewStudents` (computed in show()) is what the Vue page uses
     * to decide whether to render a "View converted Student" link at
     * all (the destination re-checks authorization server-side
     * regardless).
     *
     * @return array<string, mixed>
     */
    private function presentDetail(AdmissionApplication $application): array
    {
        return [
            ...$this->presentSummary($application),
            'decisionNote' => $application->decision_note,
            'convertedStudentId' => $application->converted_student_id,
            'convertedStudentEnrollmentId' => $application->converted_student_enrollment_id,
            'updatedAt' => $application->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentApplicantRef(Applicant $applicant): array
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
    private function presentGuardianCandidate(Guardian $guardian): array
    {
        return [
            'id' => $guardian->id,
            'firstName' => $guardian->first_name,
            'middleName' => $guardian->middle_name,
            'lastName' => $guardian->last_name,
            'status' => $guardian->status,
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
