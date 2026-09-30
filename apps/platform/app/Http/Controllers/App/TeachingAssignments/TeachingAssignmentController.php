<?php

namespace App\Http\Controllers\App\TeachingAssignments;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentReadService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TCH.2 (ADR 0063 section 23) -- the session-authenticated administrative
 * TeachingAssignment page: list an AcademicYear's assignments, create one,
 * end one. No edit, no delete, no teacher portal, no "my classes" page.
 *
 * Capabilities are checked here (AuthorizesCapability) AND again by the
 * services. The pickers are shown only to `teaching.assignments.manage`
 * holders and carry directory-tier fields only (the Timetable teacher
 * lookup precedent: Employee id, number and name; never HR profile data),
 * all read tenant-scoped. They are an affordance: the service re-validates
 * every choice.
 */
class TeachingAssignmentController extends Controller
{
    use AuthorizesCapability;

    private const int EMPLOYEE_OPTION_LIMIT = 1000;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, TeachingAssignmentReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(TeachingAssignmentService::CAPABILITY_VIEW, $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $academicYearId = $validated['academic_year_id']
            ?? AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->value('id');

        $canManage = $capabilities->canInSchool($context->actor(), TeachingAssignmentService::CAPABILITY_MANAGE, $school);

        $assignments = $academicYearId === null
            ? null
            : $reads->list($school, ['academic_year_id' => $academicYearId], (int) ($validated['page'] ?? 1), 50, $context->actor())->withQueryString();

        return Inertia::render('App/TeachingAssignments/Index', [
            'academicYears' => AcademicYear::query()->where('school_id', $school->id)->orderByDesc('starts_on')->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code, 'status' => $y->status])
                ->values()->all(),
            'academicYearId' => $academicYearId ?? '',
            'assignments' => $assignments,
            'endReasons' => TeachingAssignment::END_REASONS,
            'canManage' => $canManage,
            'options' => $canManage && $academicYearId !== null ? $this->options($school->id, $academicYearId) : null,
        ]);
    }

    public function store(Request $request, TenantContext $context, TeachingAssignmentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(TeachingAssignmentService::CAPABILITY_MANAGE, $school);

        $validated = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'section_id' => ['required', 'uuid'],
            'subject_offering_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $service->create(
                $school,
                $validated['employee_id'],
                $validated['section_id'],
                $validated['subject_offering_id'],
                $validated['starts_on'],
                $validated['ends_on'] ?? null,
                $request->user(),
            );
        } catch (TeachingAssignmentException $e) {
            throw ValidationException::withMessages(['starts_on' => $e->getMessage()]);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an Employee, Section and Subject Offering of this School.']);
        }

        return back();
    }

    public function end(Request $request, TenantContext $context, TeachingAssignmentService $service, string $teachingAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(TeachingAssignmentService::CAPABILITY_MANAGE, $school);

        $validated = $request->validate([
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(TeachingAssignment::END_REASONS)],
        ]);

        try {
            $service->end($school, $teachingAssignment, $validated['ends_on'], $validated['reason'], $request->user());
        } catch (TeachingAssignmentException $e) {
            throw ValidationException::withMessages(['ends_on' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Pickers for one AcademicYear: active Sections, active REQUIRED
     * Offerings (with their context ids so the page can pair them), and
     * active Employees -- directory-tier only.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function options(string $schoolId, string $academicYearId): array
    {
        return [
            'sections' => Section::query()->where('school_id', $schoolId)->where('academic_year_id', $academicYearId)
                ->where('status', 'active')->with('gradeLevel')->orderByRaw('upper(code)')->get()
                ->map(fn (Section $s) => [
                    'id' => $s->id, 'name' => $s->name, 'code' => $s->code,
                    'campusId' => $s->campus_id, 'gradeLevelId' => $s->grade_level_id, 'gradeLevelName' => $s->gradeLevel?->name,
                ])->values()->all(),
            'offerings' => SubjectOffering::query()->where('school_id', $schoolId)->where('academic_year_id', $academicYearId)
                ->where('status', 'active')->where('is_required', true)->with('subject')->get()
                ->map(fn (SubjectOffering $o) => [
                    'id' => $o->id, 'subjectCode' => $o->subject?->code, 'subjectName' => $o->subject?->name,
                    'campusId' => $o->campus_id, 'gradeLevelId' => $o->grade_level_id,
                ])->sortBy('subjectCode')->values()->all(),
            'employees' => Employee::query()->where('school_id', $schoolId)->where('record_status', 'active')
                ->orderBy('full_name')->limit(self::EMPLOYEE_OPTION_LIMIT)->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'employeeNumber' => $e->employee_number, 'fullName' => $e->full_name])
                ->values()->all(),
        ];
    }
}
