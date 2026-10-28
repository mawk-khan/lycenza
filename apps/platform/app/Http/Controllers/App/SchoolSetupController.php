<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarDatesFixedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearOverlapException;
use App\Domain\AcademicStructure\Application\GradeLevelAdministrationService;
use App\Domain\AcademicStructure\Application\SubjectAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\Campuses\Application\CampusAdministrationService;
use App\Domain\Campuses\Application\CampusServiceType;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Schools\Application\SchoolIdentityChangeReason;
use App\Domain\Schools\Application\SchoolIdentityService;
use App\Domain\Schools\Application\SchoolProfileService;
use App\Http\Controllers\App\Concerns\MapsDomainRefusals;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\EducationBoard;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0D sections 69-76: the minimal "School Setup" administration
 * area -- session-authenticated Inertia pages against the ambient
 * active School (TenantContext::requireSchool()), the same convention
 * SchoolSettingsController already established, NOT the Bearer-token
 * JSON API under /api/v1 (that surface exists for Flutter/external
 * consumers, ADR-documented separately).
 *
 * Covers School Profile, Campuses, Academic Years, Grade Levels and
 * Subjects. *(SWC.2, ADR 0073 section 18: the Phase 0D deferral is closed.
 * Terms, Sections, Academic Departments and Rooms have screens in
 * SchoolSetupStructureController, Subject Offerings in
 * SubjectOfferingAdminController and Elective Groups in
 * ElectiveGroupController. Every write here now goes through the same
 * Application service as the `/api/v1` endpoint -- one implementation,
 * normalized case-insensitive codes, domain refusals shown as form errors.)*
 */
class SchoolSetupController extends Controller
{
    use AuthorizesCapability, MapsDomainRefusals;

    /** ORG.12C: the Campus fields a web edit may submit (CampusAdministrationService validates them). */
    private const array CAMPUS_FIELDS = [
        'name', 'code', 'status', 'phone', 'email',
        'address_line1', 'address_line2', 'city', 'state_region', 'postal_code', 'country_code',
        'latitude', 'longitude', 'services',
    ];

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        // POR.1 (ADR 0070 §9.6): School setup is School administration state
        // -- the same read capability its sibling setup pages use. A
        // membership alone (a Guardian, a role-less member) is not enough.
        $this->authorizeCapability('school.profile.view', $school);

        return Inertia::render('App/SchoolSetup/Index', [
            // SWC.2: the remaining structure screens, shown by the capability each page itself checks.
            // ORG.12B (ADR 0080 §7): setup progress moved to the Setup & Readiness
            // Center, derived from the owning modules -- no inline model reads here.
            'can' => [
                'viewYears' => $this->can($context, 'academics.years.view'),
                'viewStructure' => $this->can($context, 'academics.structure.view'),
                'viewSubjects' => $this->can($context, 'academics.subjects.view'),
            ],
        ]);
    }

    public function profile(TenantContext $context, FreshMfaRequirement $mfa): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.profile.view', $school);

        // ORG.12C (ADR 0080 §8): sections -- identity, contact, address,
        // institutional details, operating defaults. Timezone is edited in
        // Settings; currency is Finance's single, database-enforced currency.
        return Inertia::render('App/SchoolSetup/Profile', [
            'school' => [
                'name' => $school->name,
                'legalName' => $school->legal_name,
                'code' => $school->code,
                'status' => $school->status,
                'email' => $school->email,
                'phone' => $school->phone,
                'website' => $school->website,
                'addressLine1' => $school->address_line1,
                'addressLine2' => $school->address_line2,
                'city' => $school->city,
                'stateRegion' => $school->state_region,
                'postalCode' => $school->postal_code,
                'countryCode' => $school->country_code,
                'educationBoardId' => $school->education_board_id,
                'registrationReference' => $school->registration_reference,
                'affiliationReference' => $school->affiliation_reference,
                'timezone' => $school->timezone,
                'currency' => LedgerService::SUPPORTED_CURRENCY,
            ],
            'educationBoards' => EducationBoard::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->all(),
            'identityReasons' => SchoolIdentityChangeReason::options(),
            'canManage' => $this->can($context, 'school.profile.manage'),
            'hasMfaFactor' => $mfa->hasActiveFactor($context->actor()),
        ]);
    }

    public function updateProfile(Request $request, TenantContext $context, SchoolProfileService $profiles): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.profile.manage', $school);

        $profiles->update($school, $request->only(SchoolProfileService::FIELDS), $context->actor());

        return redirect('/app/school-setup/profile');
    }

    /**
     * ORG.12C (ADR 0080 §8): legal name, registration and affiliation
     * references -- capability first, then a fresh MFA code outside the
     * transaction (rule 95), then SchoolIdentityService (closed reason,
     * current authority under the School lock, stale-form check, audit).
     */
    public function updateIdentity(Request $request, TenantContext $context, FreshMfaRequirement $mfa, SchoolIdentityService $identity): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.profile.manage', $school);

        $validated = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_reference' => ['nullable', 'string', 'max:100'],
            'affiliation_reference' => ['nullable', 'string', 'max:100'],
            'expected_legal_name' => ['present', 'nullable', 'string'],
            'expected_registration_reference' => ['present', 'nullable', 'string'],
            'expected_affiliation_reference' => ['present', 'nullable', 'string'],
            'reason_code' => ['required', Rule::enum(SchoolIdentityChangeReason::class)],
        ]);
        $proof = $mfa->requireForAction($request, $context->actor());

        $clean = fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);
        $identity->change(
            $school,
            array_combine(SchoolIdentityService::FIELDS, array_map(fn (string $f) => $clean($validated[$f] ?? null), SchoolIdentityService::FIELDS)),
            array_combine(SchoolIdentityService::FIELDS, array_map(fn (string $f) => $clean($validated["expected_{$f}"] ?? null), SchoolIdentityService::FIELDS)),
            SchoolIdentityChangeReason::from($validated['reason_code']),
            $context->actor(),
            $proof,
        );

        return redirect('/app/school-setup/profile');
    }

    public function campuses(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.view', $school);

        // ORG.12C: list rows stay short -- name, code, city, status, services (one eager query).
        return Inertia::render('App/SchoolSetup/Campuses', [
            'campuses' => Campus::query()->with('services:id,campus_id,service_code')->orderBy('name')->get()->map(fn (Campus $c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'status' => $c->status, 'city' => $c->city,
                'services' => $c->services->pluck('service_code')->sort()->values()->all(),
            ])->all(),
            'serviceOptions' => CampusServiceType::options(),
            'canManage' => $this->can($context, 'school.campuses.manage'),
        ]);
    }

    /** ORG.12C (ADR 0080 §8): one Campus's profile -- identity, address, contact, location, services. */
    public function showCampus(TenantContext $context, string $campus): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.view', $school);
        $model = Campus::query()->with('services:id,campus_id,service_code')->findOrFail($campus);

        return Inertia::render('App/SchoolSetup/Campus', [
            'campus' => [
                'id' => $model->id, 'name' => $model->name, 'code' => $model->code, 'status' => $model->status,
                'phone' => $model->phone, 'email' => $model->email,
                'addressLine1' => $model->address_line1, 'addressLine2' => $model->address_line2, 'city' => $model->city,
                'stateRegion' => $model->state_region, 'postalCode' => $model->postal_code, 'countryCode' => $model->country_code,
                'latitude' => $model->latitude, 'longitude' => $model->longitude,
                'services' => $model->services->pluck('service_code')->sort()->values()->all(),
            ],
            'schoolCountryCode' => $school->country_code,
            'serviceOptions' => CampusServiceType::options(),
            'canManage' => $this->can($context, 'school.campuses.manage'),
        ]);
    }

    public function storeCampus(Request $request, TenantContext $context, CampusAdministrationService $campuses): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.manage', $school);

        $campuses->create($school, $request->only(['name', 'code']), $context->actor());

        return redirect('/app/school-setup/campuses');
    }

    /** SWC.2: edit and deactivate/reactivate (`status`) -- reversible, never a delete (rule 73). */
    public function updateCampus(Request $request, TenantContext $context, CampusAdministrationService $campuses, string $campus): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.manage', $school);

        $campuses->update($school, $campus, $request->only(self::CAMPUS_FIELDS), $context->actor());

        return redirect()->back(fallback: '/app/school-setup/campuses');
    }

    public function academicYears(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.view', $school);

        return Inertia::render('App/SchoolSetup/AcademicYears', [
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get()->each->setRelation('school', $school)->map(fn (AcademicYear $y) => [
                'id' => $y->id, 'name' => $y->name, 'code' => $y->code,
                'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString(),
                'status' => $y->status,
                // UAT-YR-07: a draft Year is activatable only from its start date (School calendar).
                'hasStarted' => AcademicYearService::hasStarted($y),
            ])->all(),
            'canManage' => $this->can($context, 'academics.years.manage'),
        ]);
    }

    public function storeAcademicYear(Request $request, TenantContext $context, AcademicYearService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        // SWC.2: the overlap refusal is a form error on the dates, not a 500.
        $this->withFormErrors('starts_on', fn () => $service->createFromInput($school, $request->only(['name', 'code', 'starts_on', 'ends_on']), $context->actor()));

        return redirect('/app/school-setup/academic-years');
    }

    /** SWC.2: the label/code edit the API already offered (dates and status have their own paths). */
    public function renameAcademicYear(Request $request, TenantContext $context, AcademicYearService $service, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $service->rename($school, $academicYear, $request->only(['name', 'code']), $context->actor());

        return redirect('/app/school-setup/academic-years');
    }

    /**
     * SIS 5.1 (MC-3, ADR 0077): the date-correction page of a DRAFT year. An
     * active or closed year has no such page: its dates are fixed.
     */
    public function editAcademicYearDates(TenantContext $context, string $academicYear): Response|RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);
        if ($year->status !== 'draft') {
            return redirect('/app/school-setup/academic-years')
                ->withErrors(['refusal' => (new AcademicCalendarDatesFixedException($year->status))->getMessage()]);
        }

        return Inertia::render('App/SchoolSetup/AcademicYearDates', [
            'academicYear' => [
                'id' => $year->id, 'name' => $year->name, 'code' => $year->code, 'status' => $year->status,
                'startsOn' => $year->starts_on->toDateString(), 'endsOn' => $year->ends_on->toDateString(),
                'updatedAt' => $year->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * SIS 5.1: dates only. Date order and an overlap with another year are
     * field errors; a fixed (non-draft) year, a stale form or dependent records
     * outside the new dates are refusals (LC-1).
     */
    public function correctAcademicYearDates(Request $request, TenantContext $context, AcademicYearService $service, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        try {
            $service->correctDraftDates($school, $academicYear, $request->only(['starts_on', 'ends_on', 'expected_updated_at']), $context->actor());
        } catch (AcademicYearOverlapException $e) {
            throw ValidationException::withMessages(['starts_on' => [$e->getMessage()]]);
        }

        return redirect('/app/school-setup/academic-years')->with('flash', 'Academic year dates corrected.');
    }

    public function activateAcademicYear(TenantContext $context, AcademicYearService $service, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);
        // SWC.2: a stale transition or a concurrent activation is a visible refusal, not a 500.
        $this->withFormErrors('academic_year', fn () => $service->activate($year, $context->actor()));

        return redirect('/app/school-setup/academic-years');
    }

    public function closeAcademicYear(TenantContext $context, AcademicYearService $service, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);
        $this->withFormErrors('academic_year', fn () => $service->close($year, $context->actor()));

        return redirect('/app/school-setup/academic-years');
    }

    public function gradeLevels(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.view', $school);

        return Inertia::render('App/SchoolSetup/GradeLevels', [
            'gradeLevels' => GradeLevel::query()->orderBy('sequence')->get()->map(fn (GradeLevel $g) => [
                'id' => $g->id, 'name' => $g->name, 'code' => $g->code, 'sequence' => $g->sequence,
                'educationStage' => $g->education_stage, 'status' => $g->status,
            ])->all(),
            'canManage' => $this->can($context, 'academics.structure.manage'),
        ]);
    }

    public function storeGradeLevel(Request $request, TenantContext $context, GradeLevelAdministrationService $gradeLevels): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $gradeLevels->create($school, $request->only(['name', 'code', 'sequence', 'education_stage']), $context->actor());

        return redirect('/app/school-setup/grade-levels');
    }

    public function updateGradeLevel(Request $request, TenantContext $context, GradeLevelAdministrationService $gradeLevels, string $gradeLevel): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $gradeLevels->update($school, $gradeLevel, $request->only(['name', 'code', 'sequence', 'education_stage', 'status']), $context->actor());

        return redirect('/app/school-setup/grade-levels');
    }

    public function subjects(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.view', $school);

        return Inertia::render('App/SchoolSetup/Subjects', [
            'subjects' => Subject::query()->with('academicDepartment')->orderBy('name')->get()->map(fn (Subject $s) => [
                'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'shortName' => $s->short_name,
                'subjectType' => $s->subject_type, 'academicDepartmentId' => $s->academic_department_id,
                'academicDepartmentName' => $s->academicDepartment?->name, 'status' => $s->status,
            ])->all(),
            // A Subject's Department is a choice among this School's ACTIVE Departments.
            'departments' => AcademicDepartment::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code'])->all(),
            'subjectTypes' => SubjectAdministrationService::SUBJECT_TYPES,
            'canManage' => $this->can($context, 'academics.subjects.manage'),
        ]);
    }

    public function storeSubject(Request $request, TenantContext $context, SubjectAdministrationService $subjects): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $subjects->create($school, $request->only(['name', 'code', 'short_name', 'subject_type', 'academic_department_id']), $context->actor());

        return redirect('/app/school-setup/subjects');
    }

    public function updateSubject(Request $request, TenantContext $context, SubjectAdministrationService $subjects, string $subject): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $subjects->update($school, $subject, $request->only(['name', 'code', 'short_name', 'subject_type', 'academic_department_id', 'status']), $context->actor());

        return redirect('/app/school-setup/subjects');
    }

    private function can(TenantContext $context, string $capability): bool
    {
        return app(CapabilityResolver::class)->canInSchool($context->actor(), $capability, $context->requireSchool());
    }
}
