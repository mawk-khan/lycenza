<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\ClosedYearPlacementClosureService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V11.3 (ADR 0075 section 11): preview, then complete, the placements a
 * closed academic year left open. Thin: validates input and calls
 * ClosedYearPlacementClosureService (enrollments.manage), which completes
 * each row through StudentEnrollmentService::complete().
 */
class ClosedYearPlacementController extends Controller
{
    use AuthorizesCapability;

    /** Session key for the last run's per-row outcomes (read here, never shared globally). */
    private const string RESULTS = 'closed_year_placement_results';

    public function index(Request $request, TenantContext $context, ClosedYearPlacementClosureService $closure): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(ClosedYearPlacementClosureService::CAPABILITY, $school);
        $validated = $request->validate(['academic_year_id' => ['sometimes', 'nullable', 'uuid']]);
        $actor = $context->actor();

        $years = $closure->yearsWithOpenPlacements($school, $actor);
        $yearId = $validated['academic_year_id'] ?? $years->first()?->id;
        $preview = $yearId === null ? null : $closure->preview($school, $yearId, $actor);

        return Inertia::render('App/Enrollments/ClosedYearPlacements', [
            'years' => $years->map(fn ($y) => ['id' => $y->id, 'name' => $y->name, 'endsOn' => $y->ends_on->toDateString()])->values()->all(),
            'selectedYearId' => $preview === null ? '' : $preview['year']->id,
            'preview' => $preview === null ? null : [
                'yearName' => $preview['year']->name,
                'endsOn' => $preview['year']->ends_on->toDateString(),
                'total' => $preview['total'],
                'placements' => $preview['placements']->map(fn (StudentEnrollment $e) => [
                    'id' => $e->id,
                    'studentId' => $e->student_id,
                    'student' => self::studentLabel($e),
                    'class' => trim(implode(' · ', array_filter([$e->gradeLevel?->name, $e->section?->name]))),
                    'rollNumber' => $e->roll_number,
                    'startsOn' => $e->starts_on->toDateString(),
                ])->values()->all(),
            ],
            'maxBatch' => ClosedYearPlacementClosureService::MAX_BATCH,
            'results' => $request->session()->get(self::RESULTS),
        ]);
    }

    public function complete(Request $request, TenantContext $context, ClosedYearPlacementClosureService $closure): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability(ClosedYearPlacementClosureService::CAPABILITY, $school);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'uuid'],
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:'.ClosedYearPlacementClosureService::MAX_BATCH],
            'enrollment_ids.*' => ['required', 'uuid', 'distinct'],
            'confirm' => ['accepted'],
        ]);

        // Labels for the report, read before the run (display-safe columns only).
        $before = $closure->preview($school, $validated['academic_year_id'], $context->actor());
        $labels = collect($before === null ? [] : $before['placements']->all())
            ->mapWithKeys(fn (StudentEnrollment $e) => [$e->id => self::studentLabel($e)]);

        $outcomes = $closure->completeSelected($school, $validated['academic_year_id'], $validated['enrollment_ids'], $context->actor());
        if ($outcomes === null) {
            throw ValidationException::withMessages(['academic_year_id' => ['Choose one of this School\'s closed academic years.']]);
        }

        $request->session()->flash(self::RESULTS, [
            'rows' => collect($outcomes)->map(fn (string $outcome, string $id) => [
                'id' => $id,
                'student' => $labels->get($id),
                'outcome' => $outcome,
            ])->values()->all(),
        ]);

        return redirect('/app/enrollments/closed-year-placements?academic_year_id='.$validated['academic_year_id']);
    }

    private static function studentLabel(StudentEnrollment $e): string
    {
        $s = $e->student;

        return $s === null ? 'Unknown Student' : trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name])))." ({$s->student_number})";
    }
}
