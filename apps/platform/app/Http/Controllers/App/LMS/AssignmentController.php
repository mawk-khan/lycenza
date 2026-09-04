<?php

namespace App\Http\Controllers\App\LMS;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\Exceptions\LmsException;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0I.3 -- the session-authenticated administrative Assignment
 * surface. One page: resolve an AcademicYear -> SubjectOffering
 * context, list that Offering's assignments in due-date order, create
 * an assignment, edit title/instructions/due date, publish/close.
 * Structurally identical to
 * App\Http\Controllers\App\LMS\LearningContentController.
 *
 * Deliberately absent: Submission, grading, scoring, and any Student-
 * or Guardian-facing view. File attachment (the Documents
 * `assignment_id` owner arm) is reachable through the shared
 * `/api/v1` Documents surface, not duplicated in this Inertia
 * controller.
 */
class AssignmentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.assignments.view', $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'subject_offering_id' => ['sometimes', 'uuid'],
        ]);

        $academicYearId = $validated['academic_year_id']
            ?? AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->value('id');

        $offerings = SubjectOffering::query()
            ->with(['subject', 'gradeLevel', 'campus'])
            ->when($academicYearId, fn ($q) => $q->where('academic_year_id', $academicYearId))
            ->where('status', 'active')
            ->get();

        $selectedOfferingId = $validated['subject_offering_id'] ?? null;
        $assignments = [];

        if ($selectedOfferingId !== null && $offerings->contains('id', $selectedOfferingId)) {
            $assignments = Assignment::query()
                ->where('subject_offering_id', $selectedOfferingId)
                ->orderBy('due_on')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Assignment $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'instructions' => $a->instructions,
                    'dueOn' => $a->due_on?->toDateString(),
                    'status' => $a->status,
                ])->values()->all();
        } else {
            $selectedOfferingId = null;
        }

        return Inertia::render('App/LMS/Assignments/Index', [
            'academicYears' => AcademicYear::query()
                ->where('school_id', $school->id)
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code])
                ->values()->all(),
            'offerings' => $offerings->map(fn (SubjectOffering $o) => [
                'id' => $o->id,
                'subjectCode' => $o->subject?->code,
                'subjectName' => $o->subject?->name,
                'gradeLevelName' => $o->gradeLevel?->name,
                'campusName' => $o->campus?->name,
                'isRequired' => $o->is_required,
            ])->values()->all(),
            'filters' => [
                'academicYearId' => $academicYearId ?? '',
                'subjectOfferingId' => $selectedOfferingId ?? '',
            ],
            'assignments' => $assignments,
            'statuses' => Assignment::STATUSES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'lms.assignments.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, AssignmentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.assignments.manage', $school);

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'due_on' => ['nullable', 'date'],
        ]);

        $offeringId = $validated['subject_offering_id'];
        $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($offeringId);

        try {
            $service->create($school, $offeringId, [
                'title' => $validated['title'],
                'instructions' => $validated['instructions'] ?? null,
                'due_on' => $validated['due_on'] ?? null,
            ], $request->user());
        } catch (LmsException $e) {
            throw ValidationException::withMessages(['due_on' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assignments.index', [
                'academic_year_id' => $offering->academic_year_id,
                'subject_offering_id' => $offering->id,
            ])
            ->with('status', 'Assignment created.');
    }

    public function update(Request $request, TenantContext $context, AssignmentService $service, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.assignments.manage', $school);

        $model = Assignment::query()->where('school_id', $school->id)->findOrFail($assignment);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_on' => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $updated = $service->update($school, $model, $validated, $request->user());
        } catch (LmsException $e) {
            throw ValidationException::withMessages(['due_on' => $e->getMessage()]);
        }

        return $this->backToOffering($updated, 'Assignment updated.');
    }

    public function publish(Request $request, TenantContext $context, AssignmentService $service, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.assignments.manage', $school);

        $model = Assignment::query()->where('school_id', $school->id)->findOrFail($assignment);

        try {
            $updated = $service->publish($school, $model, $request->user());
        } catch (LmsException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return $this->backToOffering($updated, 'Assignment published.');
    }

    public function close(Request $request, TenantContext $context, AssignmentService $service, string $assignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.assignments.manage', $school);

        $model = Assignment::query()->where('school_id', $school->id)->findOrFail($assignment);

        try {
            $updated = $service->close($school, $model, $request->user());
        } catch (LmsException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return $this->backToOffering($updated, 'Assignment closed.');
    }

    private function backToOffering(Assignment $assignment, string $status): RedirectResponse
    {
        $offering = SubjectOffering::query()->find($assignment->subject_offering_id);

        return redirect()
            ->route('app.assignments.index', [
                'academic_year_id' => $offering?->academic_year_id,
                'subject_offering_id' => $assignment->subject_offering_id,
            ])
            ->with('status', $status);
    }
}
