<?php

namespace App\Http\Controllers\App\Examinations;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Application\ExaminationPaperService;
use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Http\Controllers\Controller;
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
 * Phase 0H.4B -- the session-authenticated administrative ExaminationPaper
 * surface, a drill-down from one Examination: list its Papers in
 * chronological order, create one against an active SubjectOffering
 * within the same AcademicYear, edit its schedule/marks/status.
 *
 * There is deliberately no marks entry, no grade-scale editor, no results
 * view, no Student roster and no teacher/invigilator/room UI anywhere on
 * this page.
 *
 * Every write delegates to
 * App\Domain\Examinations\Application\ExaminationPaperService; this
 * controller performs no ExaminationPaper write of its own. Domain
 * failures surface as ordinary form errors, the established Inertia
 * convention (App\Http\Controllers\App\Examinations\ExaminationController).
 */
class ExaminationPaperController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, CapabilityResolver $capabilities, string $examination): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.papers.view', $school);

        $examinationModel = Examination::query()->findOrFail($examination);

        $papers = ExaminationPaper::query()
            ->where('examination_id', $examinationModel->id)
            ->orderBy('scheduled_on')
            ->orderBy('starts_at')
            ->get();

        // Convenience-only selector scope: active Offerings within the
        // SAME AcademicYear as the Examination, both required and
        // elective. The service independently enforces active-Offering
        // on write -- this filter only saves a round trip.
        $subjectOfferings = SubjectOffering::query()
            ->where('academic_year_id', $examinationModel->academic_year_id)
            ->where('status', 'active')
            ->with(['campus', 'gradeLevel', 'subject'])
            ->get();

        return Inertia::render('App/Examinations/Papers/Index', [
            'examination' => [
                'id' => $examinationModel->id,
                'code' => $examinationModel->code,
                'name' => $examinationModel->name,
                'startsOn' => $examinationModel->starts_on->toDateString(),
                'endsOn' => $examinationModel->ends_on->toDateString(),
                'status' => $examinationModel->status,
            ],
            'subjectOfferings' => $subjectOfferings->map(fn (SubjectOffering $o) => [
                'id' => $o->id,
                'label' => trim(($o->campus->name ?? '').' / '.($o->gradeLevel->name ?? '').' / '.($o->subject->name ?? ''), ' /'),
                'isRequired' => $o->is_required,
            ])->values()->all(),
            'papers' => $papers->map(fn (ExaminationPaper $p) => [
                'id' => $p->id,
                'subjectOfferingId' => $p->subject_offering_id,
                'scheduledOn' => $p->scheduled_on->toDateString(),
                'startsAt' => $p->starts_at,
                'endsAt' => $p->ends_at,
                'maxMarks' => (string) $p->max_marks,
                'status' => $p->status,
            ])->values()->all(),
            'statuses' => ExaminationPaper::STATUSES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'examinations.papers.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, string $examination, ExaminationPaperService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.papers.manage', $school);

        $examinationModel = Examination::query()->findOrFail($examination);

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'scheduled_on' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i:s'],
            'ends_at' => ['required', 'date_format:H:i:s'],
            'max_marks' => ['required', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(ExaminationPaper::STATUSES)],
        ]);

        try {
            $service->create($school, $examinationModel, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['scheduled_on' => $e->getMessage()]);
        }

        return back();
    }

    public function update(
        Request $request,
        TenantContext $context,
        string $examination,
        string $examinationPaper,
        ExaminationPaperService $service,
    ): RedirectResponse {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.papers.manage', $school);

        // Scoped to the route's own Examination as well as the tenant --
        // a Paper id belonging to a different Examination is a clean 404
        // rather than a silent cross-Examination edit.
        $model = ExaminationPaper::query()
            ->where('examination_id', $examination)
            ->findOrFail($examinationPaper);

        $validated = $request->validate([
            'scheduled_on' => ['sometimes', 'date_format:Y-m-d'],
            'starts_at' => ['sometimes', 'date_format:H:i:s'],
            'ends_at' => ['sometimes', 'date_format:H:i:s'],
            'max_marks' => ['sometimes', 'numeric', 'gt:0'],
            'status' => ['sometimes', Rule::in(ExaminationPaper::STATUSES)],
        ]);

        try {
            $service->update($school, $model, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['scheduled_on' => $e->getMessage()]);
        }

        return back();
    }
}
