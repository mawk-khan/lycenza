<?php

namespace App\Http\Controllers\App\LMS;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\Exceptions\LmsException;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Infrastructure\LearningContent;
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
 * Phase 0I.2 -- the session-authenticated administrative Learning
 * Content surface. One page: resolve an AcademicYear -> SubjectOffering
 * context, list that Offering's content in display order, create a
 * resource, edit title/description/sequence, publish/archive.
 *
 * The AcademicYear/Offering context reuses the EXACT filter shape
 * App\Http\Controllers\App\SubjectOfferingController::index() already
 * established (an optional `academic_year_id`, defaulting to the
 * School's active year) -- the same reuse
 * App\Http\Controllers\App\Syllabus\SyllabusUnitController already made,
 * so there is no new Offering search or discovery API here either.
 *
 * Deliberately absent: Student roster, Assignment, Submission, grading,
 * and any Student- or Guardian-facing view. File attachment (the
 * Documents `learning_content` owner arm) is reachable through the
 * shared `/api/v1` Documents surface, not duplicated in this Inertia
 * controller.
 */
class LearningContentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.content.view', $school);

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
        $content = [];

        if ($selectedOfferingId !== null && $offerings->contains('id', $selectedOfferingId)) {
            $content = LearningContent::query()
                ->where('subject_offering_id', $selectedOfferingId)
                ->orderBy('sequence')
                ->orderBy('created_at')
                ->get()
                ->map(fn (LearningContent $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'description' => $c->description,
                    'sequence' => $c->sequence,
                    'status' => $c->status,
                ])->values()->all();
        } else {
            $selectedOfferingId = null;
        }

        return Inertia::render('App/LMS/Index', [
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
            'content' => $content,
            'statuses' => LearningContent::STATUSES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'lms.content.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, LearningContentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.content.manage', $school);

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ]);

        $offeringId = $validated['subject_offering_id'];
        $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($offeringId);

        $service->create($school, $offeringId, [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sequence' => $validated['sequence'] ?? 0,
        ], $request->user());

        return redirect()
            ->route('app.learning-content.index', [
                'academic_year_id' => $offering->academic_year_id,
                'subject_offering_id' => $offering->id,
            ])
            ->with('status', 'Learning content created.');
    }

    public function update(Request $request, TenantContext $context, LearningContentService $service, string $learningContent): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.content.manage', $school);

        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ]);

        $updated = $service->update($school, $content, $validated, $request->user());

        return $this->backToOffering($updated, 'Learning content updated.');
    }

    public function publish(Request $request, TenantContext $context, LearningContentService $service, string $learningContent): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.content.manage', $school);

        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);

        try {
            $updated = $service->publish($school, $content, $request->user());
        } catch (LmsException $e) {
            // Converts an illegal-transition domain exception into an
            // ordinary Inertia form error -- matching
            // App\Http\Controllers\App\CurriculumDelivery\CurriculumDeliveryController's
            // established convention for the identical situation.
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return $this->backToOffering($updated, 'Learning content published.');
    }

    public function archive(Request $request, TenantContext $context, LearningContentService $service, string $learningContent): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('lms.content.manage', $school);

        $content = LearningContent::query()->where('school_id', $school->id)->findOrFail($learningContent);

        try {
            $updated = $service->archive($school, $content, $request->user());
        } catch (LmsException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return $this->backToOffering($updated, 'Learning content archived.');
    }

    private function backToOffering(LearningContent $content, string $status): RedirectResponse
    {
        $offering = SubjectOffering::query()->find($content->subject_offering_id);

        return redirect()
            ->route('app.learning-content.index', [
                'academic_year_id' => $offering?->academic_year_id,
                'subject_offering_id' => $content->subject_offering_id,
            ])
            ->with('status', $status);
    }
}
