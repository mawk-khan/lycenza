<?php

namespace App\Http\Controllers\App\TeachingAssignments;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\TeachingAssignments\Application\ElectiveTeachingAssignmentService;
use App\Domain\TeachingAssignments\Application\Exceptions\TeachingAssignmentException;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentReadService;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TCH-E (ADR 0063 section 45) -- the administrative elective teaching
 * assignment page, beside the TCH.2 page: list an AcademicYear's elective
 * assignments, create one, end one. No edit and no delete. The same
 * capabilities as TeachingAssignments (`teaching.assignments.view` /
 * `.manage`), checked here AND by the services; teachers never see it and
 * never assign themselves. Pickers carry directory-tier fields only.
 */
class ElectiveTeachingAssignmentController extends Controller
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

        return Inertia::render('App/TeachingAssignments/Electives', [
            'academicYears' => AcademicYear::query()->where('school_id', $school->id)->orderByDesc('starts_on')->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code, 'status' => $y->status])
                ->values()->all(),
            'academicYearId' => $academicYearId ?? '',
            'assignments' => $academicYearId === null
                ? null
                : $reads->listElective($school, $academicYearId, (int) ($validated['page'] ?? 1), 50, $context->actor())->withQueryString(),
            'endReasons' => ElectiveTeachingAssignment::END_REASONS,
            'canManage' => $canManage,
            'options' => $canManage && $academicYearId !== null ? [
                'offerings' => SubjectOffering::query()->where('school_id', $school->id)->where('academic_year_id', $academicYearId)
                    ->where('status', 'active')->where('is_required', false)->with(['subject', 'gradeLevel'])->get()
                    ->map(fn (SubjectOffering $o) => [
                        'id' => $o->id, 'subjectCode' => $o->subject?->code, 'subjectName' => $o->subject?->name,
                        'gradeLevelName' => $o->gradeLevel?->name,
                    ])->sortBy('subjectCode')->values()->all(),
                'employees' => Employee::query()->where('school_id', $school->id)->where('record_status', 'active')
                    ->orderBy('full_name')->limit(self::EMPLOYEE_OPTION_LIMIT)->get()
                    ->map(fn (Employee $e) => ['id' => $e->id, 'employeeNumber' => $e->employee_number, 'fullName' => $e->full_name])
                    ->values()->all(),
            ] : null,
        ]);
    }

    public function store(Request $request, TenantContext $context, ElectiveTeachingAssignmentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(TeachingAssignmentService::CAPABILITY_MANAGE, $school);

        $validated = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'subject_offering_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $service->create($school, $validated['employee_id'], $validated['subject_offering_id'], $validated['starts_on'], $validated['ends_on'] ?? null, $request->user());
        } catch (TeachingAssignmentException $e) {
            throw ValidationException::withMessages(['starts_on' => $e->getMessage()]);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an Employee and an elective Subject Offering of this School.']);
        }

        return back();
    }

    public function end(Request $request, TenantContext $context, ElectiveTeachingAssignmentService $service, string $electiveTeachingAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(TeachingAssignmentService::CAPABILITY_MANAGE, $school);
        abort_unless(Str::isUuid($electiveTeachingAssignment), 404);

        $validated = $request->validate([
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(ElectiveTeachingAssignment::END_REASONS)],
        ]);

        try {
            $service->end($school, $electiveTeachingAssignment, $validated['ends_on'], $validated['reason'], $request->user());
        } catch (TeachingAssignmentException $e) {
            throw ValidationException::withMessages(['ends_on' => $e->getMessage()]);
        }

        return back();
    }
}
