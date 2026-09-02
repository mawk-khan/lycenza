<?php

namespace App\Http\Controllers\App\Examinations;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Examinations\Application\ExaminationService;
use App\Domain\Examinations\Application\Exceptions\ExaminationException;
use App\Domain\Examinations\Infrastructure\Examination;
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
 * Phase 0H.4A -- the session-authenticated administrative Examination
 * surface. One page: resolve an AcademicYear (defaulting to the
 * School's active year), list that year's examination windows in
 * chronological order, create one, edit its code/name/dates/status.
 *
 * An Examination is a WINDOW, not a paper -- there is deliberately no
 * paper editor, no scheduling grid, no marks entry, no grade-scale
 * editor, no results view, no Student roster, no teacher UI and no
 * report cards anywhere on this page.
 *
 * The AcademicYear filter reuses the EXACT shape
 * App\Http\Controllers\App\SubjectOfferingController::index() already
 * established and App\Http\Controllers\App\Syllabus\SyllabusUnitController
 * and .../CurriculumDelivery/CurriculumDeliveryController already copy --
 * an optional `academic_year_id` defaulting to the School's active
 * year. There is no new lookup or discovery API here.
 *
 * Every write delegates to
 * App\Domain\Examinations\Application\ExaminationService; this
 * controller performs no Examination write of its own. Domain failures
 * surface as ordinary form errors, the established Inertia convention
 * (App\Http\Controllers\App\Attendance\AttendanceController); the /api
 * surface renders the same exceptions with their real HTTP status
 * through bootstrap/app.php's envelope instead.
 */
class ExaminationController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.definitions.view', $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
        ]);

        $academicYearId = $validated['academic_year_id']
            ?? AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->value('id');

        $examinations = $academicYearId === null ? collect() : Examination::query()
            ->where('academic_year_id', $academicYearId)
            ->orderBy('starts_on')
            ->orderByRaw('upper(code)')
            ->get();

        return Inertia::render('App/Examinations/Index', [
            'academicYears' => AcademicYear::query()
                ->where('school_id', $school->id)
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code])
                ->values()->all(),
            'filters' => ['academicYearId' => $academicYearId ?? ''],
            'examinations' => $examinations->map(fn (Examination $e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name' => $e->name,
                'startsOn' => $e->starts_on->toDateString(),
                'endsOn' => $e->ends_on->toDateString(),
                'status' => $e->status,
            ])->values()->all(),
            'statuses' => Examination::STATUSES,
            'canManage' => $capabilities->canInSchool($context->actor(), 'examinations.definitions.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, ExaminationService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.definitions.manage', $school);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Examination::STATUSES)],
        ]);

        // Resolved tenant-scoped: another School's year is a clean 404.
        $year = AcademicYear::query()->findOrFail($validated['academic_year_id']);

        try {
            $service->create($school, $year, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['starts_on' => $e->getMessage()]);
        }

        return back();
    }

    public function update(
        Request $request,
        TenantContext $context,
        string $examination,
        ExaminationService $service,
    ): RedirectResponse {
        $school = $context->requireSchool();
        $this->authorizeCapability('examinations.definitions.manage', $school);

        $model = Examination::query()->findOrFail($examination);

        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Examination::STATUSES)],
        ]);

        try {
            $service->update($school, $model, $validated, $request->user());
        } catch (ExaminationException $e) {
            throw ValidationException::withMessages(['starts_on' => $e->getMessage()]);
        }

        return back();
    }
}
