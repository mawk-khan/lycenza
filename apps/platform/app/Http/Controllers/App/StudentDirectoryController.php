<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\CampusStudentOnboardingService;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\PossibleDuplicateStudentException;
use App\Domain\Students\Application\Exceptions\StudentOnboardingUnavailableException;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentDirectoryOrder;
use App\Domain\Students\Application\StudentDirectoryQuery;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentNameEditService;
use App\Domain\Students\Application\StudentProfileVersion;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\App\Concerns\PresentsDuplicateStudentWarning;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\StudentReports\StudentReportAccess;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\DateOfBirth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.7F-1 (ADR 0076 §45): the Campus-aware SIS read surfaces -- the
 * Student Directory and the Campus-safe operational overview. Every Student
 * and placement is read through StudentAccess, which applies the actor's
 * CampusScope in SQL before any row is returned.
 *
 * - Whole School: the existing Directory (every Student, any status), rows
 *   linking to the full Profile.
 * - Campus set: only Students whose CURRENT operational Campus is in scope,
 *   rows limited to the safe operational fields and linking to the
 *   overview. Out of scope is NOT FOUND, like an unknown id.
 *
 * Neither surface carries date of birth, Guardian data, history, the account
 * link, processing authorizations or any other domain's data: the full
 * Profile (StudentController::show) stays a declared whole-School operation.
 */
class StudentDirectoryController extends Controller
{
    use AuthorizesCampusScope, PresentsDuplicateStudentWarning;

    public function index(Request $request, TenantContext $context, StudentAccess $access, CurrentAcademicYearResolver $years, StudentDirectoryQuery $directory, StudentReportAccess $reports): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('students.view', $school);
        $actor = $context->actor();

        $validated = $request->validate(StudentDirectoryQuery::rules());
        // V11.2 (ADR 0075): the actor's enrollments.view scope (the
        // independent-capability rule) gates both the class column and,
        // SIS.EXP-1, the Campus / Grade / Section filters of the CURRENT
        // placement. SIS 11.4: one query shared with the governed export.
        $placementScope = $access->scopeOf($actor, 'enrollments.view', $school);
        $query = $directory->build($school, $scope, $placementScope, $validated);

        $paginator = $query->paginate(StudentDirectoryOrder::PER_PAGE)->withQueryString();

        // V11.2: each row's current class, loaded for the whole page in one query.
        $placements = $access->currentPlacements($school, $placementScope, $paginator->getCollection()->pluck('id')->all());
        $campusView = ! $scope->isWholeSchool();

        return Inertia::render('App/Students/Index', [
            'students' => $paginator->through(fn (Student $s) => ($campusView ? $this->presentCampusRow($s) : $this->presentSummary($s)) + (
                $placementScope->isNone() ? [] : ['currentPlacement' => $this->presentPlacement($placements->get($s->id), $campusView)]
            )),
            'canViewEnrollments' => ! $placementScope->isNone(),
            'campusView' => $campusView,
            // SIS 11.4 (D-11): governed export/print (re-checked on the request).
            'canExport' => $reports->mayExport($reports->viewer($actor, $school), 'students.directory'),
            'filters' => [
                'student_number' => $validated['student_number'] ?? '',
                'name' => $validated['name'] ?? '',
                'status' => $validated['status'] ?? '',
                'campus_id' => $validated['campus_id'] ?? '',
                'grade_level_id' => $validated['grade_level_id'] ?? '',
                'section_id' => $validated['section_id'] ?? '',
                'sort' => $validated['sort'] ?? '',
            ],
            // SIS.EXP-1: options cut to the viewer's reach (null without placement read).
            'placementFilters' => $this->placementFilterOptions($school, $placementScope->intersect($scope), $years),
            // ORG.7F-2: the legacy create form is whole-School; Campus
            // onboarding needs both keys (and re-decides everything itself).
            'canManage' => ! $campusView && $access->holdsWholeSchool($actor, 'students.manage', $school),
            'canOnboard' => ! $access->scopeOf($actor, 'students.manage', $school)->isNone() && ! $access->scopeOf($actor, 'enrollments.manage', $school)->isNone(),
            // SIS 11.7: the Houses page opens on students.houses.view (any scope).
            'canViewHouses' => ! $access->scopeOf($actor, 'students.houses.view', $school)->isNone(),
        ]);
    }

    /**
     * The Campus-safe operational overview: identity reference and the
     * CURRENT placement only (owner decisions F1-D1, F1-D2: no date of birth,
     * no Guardian block).
     */
    public function overview(TenantContext $context, StudentAccess $access, string $student): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('students.view', $school);
        $actor = $context->actor();

        $model = $access->student($school, $scope, $student);
        $placementScope = $access->scopeOf($actor, 'enrollments.view', $school);
        $placement = $access->currentPlacements($school, $placementScope, [$model->id])->get($model->id);

        return Inertia::render('App/Students/Overview', [
            'student' => [
                'id' => $model->id,
                'studentNumber' => $model->student_number,
                'firstName' => $model->first_name,
                'middleName' => $model->middle_name,
                'lastName' => $model->last_name,
            ],
            'canViewPlacement' => ! $placementScope->isNone(),
            'currentPlacement' => $this->presentPlacement($placement, true),
            // The full Profile is a declared whole-School operation.
            'canOpenProfile' => $scope->isWholeSchool(),
            // ORG.7F-2: names only, when the edit scope reaches this Student
            // (the update re-decides it under lock).
            'canEditNames' => $access->students($school, $access->scopeOf($actor, 'students.manage', $school))->whereKey($model->id)->exists(),
            // ORG.7F-3: a Campus transfer, when the enrollments.manage scope
            // reaches the current placement and offers another Campus (the
            // transfer re-decides everything under lock).
            'canTransfer' => $this->canTransfer($access, $actor, $school, $model->id),
        ]);
    }

    /**
     * ORG.7F-2 (ADR 0076 §46): the minimal name form -- names and the Student
     * number only (never date of birth, status or anything else). A Campus
     * editor reaches only a Student whose CURRENT operational Campus is in
     * the students.manage scope (out of scope is NOT FOUND).
     */
    public function editNames(TenantContext $context, StudentAccess $access, string $student): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('students.manage', $school);
        $model = $access->student($school, $scope, $student);

        return Inertia::render('App/Students/EditNames', [
            'student' => [
                'id' => $model->id,
                'studentNumber' => $model->student_number,
                'firstName' => $model->first_name,
                'middleName' => $model->middle_name,
                'lastName' => $model->last_name,
            ],
            // SIS.EXP-1 (L-2): the stale-form token, checked under the lock on save.
            'version' => StudentProfileVersion::of($model),
        ]);
    }

    /**
     * ORG.7F-2: first, middle and last name only (StudentNameEditService,
     * under StudentAccess::lockedWriteTarget()). Other input is ignored.
     */
    public function updateNames(Request $request, TenantContext $context, StudentAccess $access, StudentNameEditService $names, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('students.manage', $school);
        // Out of scope is NOT FOUND before any input is judged (the locked
        // write target below re-decides it under the lock).
        $access->student($school, $scope, $student);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'expected_version' => ['required', 'string', 'size:64'],
        ]);
        $model = $names->edit($school, $scope, $student, $validated, $context->actor(), $validated['expected_version']);

        return redirect("/app/students/{$model->id}/overview")->with('flash', 'Names corrected.');
    }

    /**
     * ORG.7F-2: the atomic Campus onboarding form. It needs BOTH
     * students.manage and enrollments.manage; destinations are active
     * Sections of the active year on an active Campus in both scopes. The
     * Student number is never an input (it is allocated by the server).
     */
    public function onboard(Request $request, TenantContext $context, CampusStudentOnboardingService $onboarding): Response
    {
        $school = $context->requireSchool();
        $studentScope = $this->authorizeCampusScope('students.manage', $school);
        $enrollmentScope = $this->authorizeCampusScope('enrollments.manage', $school);

        return Inertia::render('App/Students/Onboard', [
            'sections' => $onboarding->destinations($school, $studentScope, $enrollmentScope),
            // SIS 2.3: only Students this actor may already see (Campus surface).
            'duplicateWarning' => $this->duplicateWarningFor($request, $school, $context->actor(), 'student-onboard', campusSurface: true),
            'today' => SchoolTimezone::today($school),
        ]);
    }

    public function storeOnboarding(Request $request, TenantContext $context, CampusStudentOnboardingService $onboarding, StudentAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $studentScope = $this->authorizeCampusScope('students.manage', $school);
        $enrollmentScope = $this->authorizeCampusScope('enrollments.manage', $school);

        // Exactly these fields (plus SIS 2.3's opaque duplicate acknowledgement):
        // never a Student number, an existing Student id,
        // a status, a Guardian or anything else (owner decisions F2-D5, F2-D9,
        // F2-D12); date of birth is write-only (F2-D13).
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => DateOfBirth::rules($school),
            'section_id' => ['required', 'uuid'],
            'roll_number' => ['required', 'string', 'max:255'],
            'duplicate_acknowledgement' => ['nullable', 'string', 'max:128'],
        ], DateOfBirth::messages());

        // SIS 2.3: the duplicate-risk check compares only with the Students
        // this actor may already see (their students.view Campus scope).
        $viewScope = $access->scopeOf($context->actor(), 'students.view', $school);

        try {
            $enrollment = $onboarding->onboard($school, $studentScope, $enrollmentScope, Arr::except($validated, ['duplicate_acknowledgement']), $context->actor(), $viewScope, $validated['duplicate_acknowledgement'] ?? null);
        } catch (PossibleDuplicateStudentException $e) {
            $this->refuseAsPossibleDuplicate($request, 'student-onboard', $e);
        } catch (StudentOnboardingUnavailableException $e) {
            throw ValidationException::withMessages(['section_id' => [$e->getMessage()]]);
        } catch (DuplicateEnrollmentRollNumberException|InvalidEnrollmentRollNumberException $e) {
            throw ValidationException::withMessages(['roll_number' => [$e->getMessage()]]);
        }

        return redirect("/app/students/{$enrollment->student_id}/overview")->with('flash', 'Student added and placed.');
    }

    private function canTransfer(StudentAccess $access, User $actor, School $school, string $studentId): bool
    {
        $scope = $access->scopeOf($actor, 'enrollments.manage', $school);
        $placement = $access->currentPlacements($school, $scope, [$studentId])->get($studentId);

        return $placement !== null && $access->students($school, $scope)->whereKey($studentId)->exists()
            && $access->transferDestinations($school, $scope, $placement) !== [];
    }

    /**
     * Whole-School list row -- deliberately excludes dateOfBirth (Highly
     * Sensitive children's data, docs/security/DATA-CLASSIFICATION.md).
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
     * Campus row: the safe operational identity only (ADR 0076 §45).
     *
     * @return array<string, mixed>
     */
    private function presentCampusRow(Student $student): array
    {
        return [
            'id' => $student->id,
            'studentNumber' => $student->student_number,
            'firstName' => $student->first_name,
            'middleName' => $student->middle_name,
            'lastName' => $student->last_name,
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function presentPlacement(?StudentEnrollment $enrollment, bool $withCampus): ?array
    {
        if ($enrollment === null) {
            return null;
        }

        return [
            ...($withCampus ? ['campus' => $enrollment->campus?->name] : []),
            'gradeLevel' => $enrollment->gradeLevel?->name,
            'section' => $enrollment->section?->name,
            'rollNumber' => $enrollment->roll_number,
        ];
    }

    /**
     * SIS.EXP-1: the directory's placement filter options -- the active
     * year's Sections (and their Campuses and Grades) the viewer's placement
     * scope reaches. Null without placement read or an active year.
     *
     * SIS.HARDEN-1 (R-5): only Sections that can match a current placement --
     * active ones, plus an inactive one that still holds an active placement
     * (deactivating a Section never ends its placements), labelled
     * "(inactive)". Options only: a forged id still just narrows.
     *
     * @return array{campuses: list<array{id: string, name: string}>, gradeLevels: list<array{id: string, name: string}>, sections: list<array<string, string>>}|null
     */
    private function placementFilterOptions(School $school, CampusScope $scope, CurrentAcademicYearResolver $years): ?array
    {
        $year = $scope->isNone() ? null : $years->tryResolve($school);
        if ($year === null) {
            return null;
        }
        $sections = $scope->restrict(Section::query()->where('sections.school_id', $school->id)->where('sections.academic_year_id', $year->id), 'sections.campus_id')
            ->tap(fn ($q) => app(StudentEnrollmentReadService::class)->matchableSections($q, $year->id))
            ->with(['campus:id,name', 'gradeLevel:id,name,sequence'])
            ->get()
            ->sortBy(fn (Section $s) => sprintf('%08d|%s|%s', $s->gradeLevel->sequence, $s->name, $s->campus->name))
            ->values();

        return [
            'campuses' => $sections->pluck('campus')->unique('id')->sortBy('name')->map(fn (Campus $c) => ['id' => (string) $c->id, 'name' => $c->name])->values()->all(),
            'gradeLevels' => $sections->pluck('gradeLevel')->unique('id')->sortBy('sequence')->map(fn (GradeLevel $g) => ['id' => (string) $g->id, 'name' => $g->name])->values()->all(),
            'sections' => $sections->map(fn (Section $s) => [
                'id' => (string) $s->id,
                'label' => SectionLabel::of($s).' · '.$s->campus->name.($s->status === 'active' ? '' : ' (inactive)'),
                'campusId' => (string) $s->campus_id,
                'gradeLevelId' => (string) $s->grade_level_id,
            ])->all(),
        ];
    }
}
