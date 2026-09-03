<?php

namespace App\Http\Controllers\App\Examinations;

use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Application\GradeScaleService;
use App\Domain\Examinations\Infrastructure\GradeBand;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0H.4C -- the session-authenticated administrative GradeScale
 * surface. One page: list every GradeScale in the School with its
 * bands, create a draft scale (optionally with initial bands), edit a
 * draft's bands, change name, and drive the lifecycle
 * (activate/deactivate/reactivate) through the ordinary update.
 *
 * School-only -- no AcademicYear filter, no Examination context of any
 * kind, unlike every other Examinations admin page.
 *
 * Every write delegates to
 * App\Domain\Examinations\Application\GradeScaleService; this
 * controller performs no write of its own. Domain failures surface as
 * ordinary form errors, the established Inertia convention.
 */
class GradeScaleController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.grade_scales.view', $school);

        $scales = GradeScale::query()->with('bands')->orderBy('code')->get();

        return Inertia::render('App/Examinations/GradeScales/Index', [
            'gradeScales' => $scales->map(fn (GradeScale $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'status' => $s->status,
                'bands' => $s->bands->map(fn (GradeBand $b) => [
                    'id' => $b->id,
                    'minPercentage' => (string) $b->min_percentage,
                    'label' => $b->label,
                ])->values()->all(),
            ])->values()->all(),
            'statuses' => GradeScale::STATUSES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'examinations.grade_scales.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, GradeScaleService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'bands' => ['sometimes', 'array'],
            'bands.*.min_percentage' => ['required_with:bands', 'numeric', 'between:0,100'],
            'bands.*.label' => ['required_with:bands', 'string', 'max:32'],
        ]);

        try {
            $service->create($school, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return back();
    }

    public function update(Request $request, TenantContext $context, string $gradeScale, GradeScaleService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(GradeScale::STATUSES)],
        ]);

        try {
            $service->update($school, $model, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back();
    }

    public function storeBand(Request $request, TenantContext $context, string $gradeScale, GradeScaleService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);

        $validated = $request->validate([
            'min_percentage' => ['required', 'numeric', 'between:0,100'],
            'label' => ['required', 'string', 'max:32'],
        ]);

        try {
            $service->addBand($school, $model, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['min_percentage' => $e->getMessage()]);
        }

        return back();
    }

    public function updateBand(
        Request $request,
        TenantContext $context,
        string $gradeScale,
        string $gradeBand,
        GradeScaleService $service,
    ): RedirectResponse {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);
        $band = GradeBand::query()->where('grade_scale_id', $model->id)->findOrFail($gradeBand);

        $validated = $request->validate([
            'min_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'label' => ['sometimes', 'string', 'max:32'],
        ]);

        try {
            $service->updateBand($school, $model, $band, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['min_percentage' => $e->getMessage()]);
        }

        return back();
    }

    public function destroyBand(
        Request $request,
        TenantContext $context,
        string $gradeScale,
        string $gradeBand,
        GradeScaleService $service,
    ): RedirectResponse {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.grade_scales.manage', $school);

        $model = GradeScale::query()->findOrFail($gradeScale);
        $band = GradeBand::query()->where('grade_scale_id', $model->id)->findOrFail($gradeBand);

        try {
            $service->removeBand($school, $model, $band, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['bands' => $e->getMessage()]);
        }

        return back();
    }
}
