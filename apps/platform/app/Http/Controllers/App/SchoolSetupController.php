<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Events\GradeLevelCreated;
use App\Domain\AcademicStructure\Events\SubjectCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\Campuses\Events\CampusCreated;
use App\Domain\Schools\Events\SchoolProfileUpdated;
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
 * Deliberately covers School Profile, Campuses, Academic Years, Grade
 * Levels, and Subjects only -- Terms/Sections/Academic Departments/
 * Rooms/Subject Offerings are fully implemented and tested at the API
 * layer (this checkpoint's test suite) but do not yet have a
 * dedicated screen; see the Phase 0D final report's "Technical Debt"
 * section for why this was deliberately deferred rather than rushed.
 */
class SchoolSetupController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context): Response
    {
        $school = $context->requireSchool();

        return Inertia::render('App/SchoolSetup/Index', [
            'progress' => [
                'profileConfigured' => $school->legal_name !== null || $school->email !== null,
                'campusCreated' => Campus::query()->exists(),
                'academicYearActivated' => AcademicYear::query()->where('status', 'active')->exists(),
                'gradeLevelsConfigured' => GradeLevel::query()->exists(),
                'subjectsConfigured' => Subject::query()->exists(),
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
            'campuses' => Campus::query()->orderBy('name')->get(['id', 'name', 'code', 'status'])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'school.campuses.manage', $school),
        ]);
    }

    public function storeCampus(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('school.campuses.manage', $school);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $validated['code'] = strtoupper(trim($validated['code']));

        DB::transaction(function () use ($school, $validated) {
            $campus = Campus::query()->create([...$validated, 'school_id' => $school->id]);

            app(AuditRecorder::class)->school($school, 'campus.created', subject: $campus, metadata: $validated);
            event(new CampusCreated($school->id, $campus->id, $campus->name, $campus->code));
        });

        return redirect('/app/school-setup/campuses');
    }

    public function academicYears(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.view', $school);

        return Inertia::render('App/SchoolSetup/AcademicYears', [
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get()->map(fn (AcademicYear $y) => [
                'id' => $y->id, 'name' => $y->name, 'code' => $y->code,
                'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString(),
                'status' => $y->status,
            ])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'academics.years.manage', $school),
        ]);
    }

    public function storeAcademicYear(Request $request, TenantContext $context, AcademicYearService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        $service->create($school, $validated, $context->actor());

        return redirect('/app/school-setup/academic-years');
    }

    public function activateAcademicYear(TenantContext $context, AcademicYearService $service, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);
        $service->activate($year, $context->actor());

        return redirect('/app/school-setup/academic-years');
    }

    public function closeAcademicYear(TenantContext $context, AcademicYearService $service, string $academicYear): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.years.manage', $school);

        $year = AcademicYear::query()->findOrFail($academicYear);
        $service->close($year, $context->actor());

        return redirect('/app/school-setup/academic-years');
    }

    public function gradeLevels(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.view', $school);

        return Inertia::render('App/SchoolSetup/GradeLevels', [
            'gradeLevels' => GradeLevel::query()->orderBy('sequence')->get(['id', 'name', 'code', 'sequence', 'status'])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'academics.structure.manage', $school),
        ]);
    }

    public function storeGradeLevel(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.structure.manage', $school);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'sequence' => ['required', 'integer', 'min:0'],
        ]);
        $validated['code'] = strtoupper(trim($validated['code']));

        DB::transaction(function () use ($school, $validated) {
            $gradeLevel = GradeLevel::query()->create([...$validated, 'school_id' => $school->id]);

            app(AuditRecorder::class)->school($school, 'grade_level.created', subject: $gradeLevel, metadata: $validated);
            event(new GradeLevelCreated($school->id, $gradeLevel->id, $gradeLevel->name, $gradeLevel->code));
        });

        return redirect('/app/school-setup/grade-levels');
    }

    public function subjects(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.view', $school);

        return Inertia::render('App/SchoolSetup/Subjects', [
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'code', 'subject_type', 'status'])->all(),
            'canManage' => app(CapabilityResolver::class)->canInSchool($context->actor(), 'academics.subjects.manage', $school),
        ]);
    }

    public function storeSubject(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('academics.subjects.manage', $school);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $validated['code'] = strtoupper(trim($validated['code']));

        DB::transaction(function () use ($school, $validated) {
            $subject = Subject::query()->create([...$validated, 'school_id' => $school->id]);

            app(AuditRecorder::class)->school($school, 'subject.created', subject: $subject, metadata: $validated);
            event(new SubjectCreated($school->id, $subject->id, $subject->name, $subject->code));
        });

        return redirect('/app/school-setup/subjects');
    }
}
