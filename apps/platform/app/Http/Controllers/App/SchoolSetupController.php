<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\GradeLevelAdministrationService;
use App\Domain\AcademicStructure\Application\SubjectAdministrationService;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\Campuses\Application\CampusAdministrationService;
use App\Domain\Schools\Events\SchoolProfileUpdated;
use App\Http\Controllers\App\Concerns\MapsDomainRefusals;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\EducationBoard;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        // POR.1 (ADR 0070 §9.6): setup progress is School administration
        // state -- the same read capability its sibling setup pages use. A
        // membership alone (a Guardian, a role-less member) is not enough.
        $this->authorizeCapability('school.profile.view', $school);

        return Inertia::render('App/SchoolSetup/Index', [
            'progress' => [
                'profileConfigured' => $school->legal_name !== null || $school->email !== null,
                'campusCreated' => Campus::query()->exists(),
                'academicYearActivated' => AcademicYear::query()->where('status', 'active')->exists(),
                'gradeLevelsConfigured' => GradeLevel::query()->exists(),
                'subjectsConfigured' => Subject::query()->exists(),
            ],
            // SWC.2: the remaining structure screens, shown by the capability each page itself checks.
            'can' => [
                'viewYears' => $this->can($context, 'academics.years.view'),
                'viewStructure' => $this->can($context, 'academics.structure.view'),
                'viewSubjects' => $this->can($context, 'academics.subjects.view'),
            ],
        ]);
    }

    public function profile(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.profile.view', $school);

        return Inertia::render('App/SchoolSetup/Profile', [
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'legalName' => $school->legal_name,
                'code' => $school->code,
                'email' => $school->email,
                'phone' => $school->phone,
                'website' => $school->website,
                'addressLine1' => $school->address_line1,
                'city' => $school->city,
                'stateRegion' => $school->state_region,
                'postalCode' => $school->postal_code,
                'countryCode' => $school->country_code,
                'educationBoardId' => $school->education_board_id,
            ],
            'educationBoards' => EducationBoard::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'school.profile.manage', $school),
        ]);
    }

    public function updateProfile(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.profile.manage', $school);

        $validated = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state_region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'education_board_id' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($school, $validated) {
            $school->update($validated);

            app(AuditRecorder::class)->school($school, 'school.profile_updated', subject: $school, metadata: [
                'after' => $validated,
            ]);

            event(new SchoolProfileUpdated($school->id, array_keys($validated)));
        });

        return redirect('/app/school-setup/profile');
    }

    public function campuses(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.view', $school);

        return Inertia::render('App/SchoolSetup/Campuses', [
            'campuses' => Campus::query()->orderBy('name')->get()->map(fn (Campus $c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'status' => $c->status,
                'phone' => $c->phone, 'email' => $c->email, 'address' => $c->address,
            ])->all(),
            'canManage' => $this->can($context, 'school.campuses.manage'),
        ]);
    }

    public function storeCampus(Request $request, TenantContext $context, CampusAdministrationService $campuses): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.manage', $school);

        $campuses->create($school, $request->only(['name', 'code', 'phone', 'email', 'address']), $context->actor());

        return redirect('/app/school-setup/campuses');
    }

    /** SWC.2: edit and deactivate/reactivate (`status`) -- reversible, never a delete (rule 73). */
    public function updateCampus(Request $request, TenantContext $context, CampusAdministrationService $campuses, string $campus): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.manage', $school);

        $campuses->update($school, $campus, $request->only(['name', 'code', 'phone', 'email', 'address', 'status']), $context->actor());

        return redirect('/app/school-setup/campuses');
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
