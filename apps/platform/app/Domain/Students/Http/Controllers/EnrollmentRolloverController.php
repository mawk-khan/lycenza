<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\EnrollmentRolloverReadService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 1B.7E: the administrative HTTP boundary for the rollover Plan
 * resource -- directory, create, detail, and the three explicit
 * lifecycle actions (`validate`/`start`/`resume`). Deliberately thin,
 * mirroring StudentEnrollmentController/AcademicYearController's
 * established shape: every read delegates to
 * EnrollmentRolloverReadService, every mutation delegates to the
 * already-accepted rollover Application services
 * (EnrollmentRolloverPlanService/EnrollmentRolloverDryRunService/
 * EnrollmentRolloverExecutionService) -- no dry-run logic, mapping
 * precedence, Roll Number validation, promotion rule, execution
 * ordering, resumability, or staleness handling is duplicated here.
 *
 * Every mutation route additionally carries BOTH `capability:` route
 * middleware entries (`enrollments.manage` AND
 * `enrollments.rollovers.manage`) -- see routes/api.php. Read actions
 * authorize both capabilities inline, matching
 * StudentEnrollmentController's identical index/show pattern.
 *
 * See docs/modules/STUDENT-ENROLLMENT.md ("Rollover Authorization &
 * Administrative HTTP/API") for the full dual-capability rationale and
 * the synchronous execution operational limitation `start()`/`resume()`
 * document below.
 */
class EnrollmentRolloverController extends Controller
{
    use AuthorizesCapability;

    /**
     * Server-owned bound on how many Items ONE HTTP call to start()/
     * resume() will process before returning -- never caller-controlled
     * (this checkpoint's brief, sections 33/34/92). A Plan with more
     * pending Items than this simply returns with the Plan still
     * `executing`; the client calls resume() again to continue. This is
     * NOT a redesign of the 1B.7D orchestrator -- it reuses that
     * service's own existing `$afterEachItem` extension point (Phase
     * 1B.7D's deterministic-interruption test seam) for its OTHER
     * legitimate purpose: bounding a single synchronous HTTP request's
     * duration now that a real caller (this controller) exists. See
     * EnrollmentRolloverExecutionService::start()'s own updated
     * docblock.
     */
    private const MAX_ITEMS_PER_REQUEST = 100;

    // --- Directory / detail --------------------------------------------

    public function index(Request $request, School $school, EnrollmentRolloverReadService $reads): JsonResponse
    {
        $this->authorizeCapability('enrollments.view', $school);
        $this->authorizeCapability('enrollments.rollovers.view', $school);

        $validated = $request->validate([
            'source_academic_year_id' => ['sometimes', 'uuid'],
            'target_academic_year_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['draft', 'validated', 'executing', 'completed', 'completed_with_errors', 'cancelled'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $filters = collect($validated)->except('per_page')->all();

        $paginator = $reads->directory($filters, $perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (EnrollmentRolloverPlan $p) => $this->presentSummary($p))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $rollover, EnrollmentRolloverReadService $reads): JsonResponse
    {
        $this->authorizeCapability('enrollments.view', $school);
        $this->authorizeCapability('enrollments.rollovers.view', $school);

        $plan = $reads->detail($rollover);
        abort_if($plan === null, 404);

        return response()->json(['data' => $this->presentDetail($plan, $reads)]);
    }

    // --- Create -----------------------------------------------------------

    /**
     * Accepts only source/target AcademicYear ids -- School derives
     * from route/TenantContext, actor from the authenticated User,
     * status/configuration_version/timestamps are entirely
     * EnrollmentRolloverPlanService::createDraft()'s own invariant
     * (this checkpoint's brief, section 14). A foreign-School
     * AcademicYear id fails this validation identically to a random
     * uuid -- `Rule::exists(...)->where('school_id', $school->id)`
     * never reveals whether the id exists in another School.
     */
    public function store(Request $request, School $school, EnrollmentRolloverPlanService $service): JsonResponse
    {
        $validated = $request->validate([
            'source_academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            'target_academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
        ]);

        $sourceYear = AcademicYear::query()->findOrFail($validated['source_academic_year_id']);
        $targetYear = AcademicYear::query()->findOrFail($validated['target_academic_year_id']);

        $plan = $service->createDraft($school, $sourceYear, $targetYear, $request->user());

        return response()->json(['data' => $this->presentSummary($plan)], 201);
    }

    // --- Lifecycle actions ------------------------------------------------

    /**
     * Runs EnrollmentRolloverDryRunService::run() only -- persists
     * ONLY rollover planning state (validation_result/reason/snapshots
     * on Items, status/validated_configuration_version/validated_at on
     * the Plan). Zero StudentEnrollment/Student/AcademicYear/
     * GradeLevel/Section/Campus writes (this checkpoint's brief,
     * section 30/76) -- proven directly in
     * EnrollmentRolloverApiTest::dry_run_endpoint_changes_zero_academic_state_rows.
     */
    public function validate(Request $request, School $school, string $rollover, EnrollmentRolloverDryRunService $dryRun): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        $summary = $dryRun->run($plan, $request->user());

        return response()->json(['data' => $summary]);
    }

    /**
     * Claims a `validated` Plan and processes it -- see
     * EnrollmentRolloverExecutionService::start()'s own docblock for
     * the exact claim/double-start-prevention contract. This
     * controller performs NO Item loop and calls no Enrollment-creation
     * primitive itself; it delegates the entire bounded/resumable run
     * to the orchestrator, capped at `MAX_ITEMS_PER_REQUEST` Items for
     * this ONE HTTP request (never caller-controlled). The response
     * always reflects the Plan's ACTUAL persisted state after the call
     * returns -- `planStatus` may be `completed`, still `executing`
     * (this request's item cap was reached, or an unrelated interruption
     * occurred -- call resume() to continue), or `draft` (a per-Item
     * primitive call discovered external drift and invalidated the
     * Plan mid-run; revalidate before starting again). Never fabricated
     * as "completed" merely because this HTTP request returned 200.
     *
     * OPERATIONAL LIMITATION (this checkpoint's brief, section 33): no
     * queue exists yet. A Plan with more pending Items than
     * `MAX_ITEMS_PER_REQUEST` requires the caller to issue additional
     * `resume()` calls -- there is no background worker that does this
     * automatically. This is a real, documented limitation, not a
     * silent one; see docs/modules/STUDENT-ENROLLMENT.md.
     */
    public function start(Request $request, School $school, string $rollover, EnrollmentRolloverExecutionService $execution): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        $summary = $execution->start($plan, $request->user(), afterEachItem: $this->stopAfterItemCap());

        return response()->json(['data' => $summary]);
    }

    /**
     * Continues an ALREADY-`executing` Plan -- see
     * EnrollmentRolloverExecutionService::resume()'s own docblock. A
     * duplicate/premature `start()` call against an already-executing
     * Plan is never silently treated as a resume -- it returns the
     * clean `RolloverPlanAlreadyExecutingException` domain conflict
     * (409) the execution service already provides; intentional resume
     * always uses this dedicated endpoint.
     */
    public function resume(Request $request, School $school, string $rollover, EnrollmentRolloverExecutionService $execution): JsonResponse
    {
        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        $summary = $execution->resume($plan, $request->user(), afterEachItem: $this->stopAfterItemCap());

        return response()->json(['data' => $summary]);
    }

    private function stopAfterItemCap(): callable
    {
        $processed = 0;

        return function () use (&$processed) {
            $processed++;

            return $processed >= self::MAX_ITEMS_PER_REQUEST;
        };
    }

    // --- Presentation -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(EnrollmentRolloverPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'sourceAcademicYear' => $this->presentYearRef($plan->sourceAcademicYear),
            'targetAcademicYear' => $this->presentYearRef($plan->targetAcademicYear),
            'status' => $plan->status,
            'configurationVersion' => $plan->configuration_version,
            'validatedConfigurationVersion' => $plan->validated_configuration_version,
            'createdBy' => $plan->createdBy === null ? null : ['id' => $plan->createdBy->id, 'name' => $plan->createdBy->name],
            'createdAt' => $plan->created_at->toIso8601String(),
            'validatedAt' => $plan->validated_at?->toIso8601String(),
            'executionStartedAt' => $plan->execution_started_at?->toIso8601String(),
            'completedAt' => $plan->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(EnrollmentRolloverPlan $plan, EnrollmentRolloverReadService $reads): array
    {
        return [
            ...$this->presentSummary($plan),
            'isValidatedForCurrentConfiguration' => $plan->isValidatedForCurrentConfiguration(),
            'mappings' => $plan->mappings->map(fn (EnrollmentRolloverMapping $m) => $this->presentMapping($m))->all(),
            'executionSummary' => $reads->executionSummary($plan),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMapping(EnrollmentRolloverMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'sourceGradeLevel' => $this->presentRef($mapping->sourceGradeLevel),
            'sourceSection' => $mapping->sourceSection === null ? null : $this->presentRef($mapping->sourceSection),
            'targetGradeLevel' => $this->presentRef($mapping->targetGradeLevel),
            'targetSection' => $mapping->targetSection === null ? null : $this->presentRef($mapping->targetSection),
            'isRepeat' => $mapping->isRepeat(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentYearRef(AcademicYear $year): array
    {
        return ['id' => $year->id, 'name' => $year->name, 'code' => $year->code];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRef(GradeLevel|Section $model): array
    {
        return ['id' => $model->id, 'name' => $model->name, 'code' => $model->code];
    }
}
