<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\EnrollmentRolloverReadService;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverPlanChronologyException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverPlanYearsException;
use App\Domain\Students\Application\Exceptions\OpenRolloverPlanConflictException;
use App\Domain\Students\Application\Exceptions\RolloverPlanAlreadyExecutingException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotExecutionReadyException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotResumableException;
use App\Domain\Students\Application\Exceptions\StaleRolloverConfigurationException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\EnrollmentRolloverSubjectMapping;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Rollover\BoundsRolloverExecutionRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1B.7F: session-authenticated Inertia pages for the rollover
 * Plan workspace -- mirrors StudentEnrollmentController (App)'s exact
 * convention (NOT the Bearer-token JSON API under /api/v1, which
 * remains the separate Phase 1B.7E surface). Every mutation delegates
 * to the SAME already-accepted Application services the JSON API
 * controller uses (EnrollmentRolloverPlanService/
 * EnrollmentRolloverDryRunService/EnrollmentRolloverExecutionService);
 * every non-trivial read delegates to EnrollmentRolloverReadService.
 * No dry-run logic, mapping precedence, Roll Number validation,
 * promotion rule, execution ordering, resumability, or staleness
 * handling is duplicated here -- and this Vue/Inertia layer never
 * calls the JSON API over HTTP (CLAUDE.md's "no browser-side business
 * logic" + this checkpoint's brief, section 63).
 *
 * Every domain exception a user can plausibly trigger is translated to
 * Laravel's own ValidationException (StudentEnrollmentController's
 * established pattern) so Inertia's `form.errors` renders it inline --
 * the exception's own already-safe, already-crafted message is reused
 * as-is, never a raw class name/SQLSTATE.
 *
 * Capability checks live inside every action (AuthorizesCapability
 * trait), matching every other App/ controller's pattern -- every
 * action re-derives the active School from TenantContext, never a
 * client-supplied id. Both `enrollments.view`/`.manage` AND
 * `enrollments.rollovers.view`/`.manage` are required, independently
 * (docs/modules/STUDENT-ENROLLMENT.md, "Rollover Authorization &
 * Administrative HTTP/API").
 */
class EnrollmentRolloverController extends Controller
{
    use AuthorizesCapability, BoundsRolloverExecutionRequest;

    // --- Directory / create -------------------------------------------

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, EnrollmentRolloverReadService $reads): Response
    {
        $school = $context->requireSchool();
        $this->authorizeRolloverView($school);

        $validated = $request->validate([
            'source_academic_year_id' => ['sometimes', 'uuid'],
            'target_academic_year_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['draft', 'validated', 'executing', 'completed', 'completed_with_errors', 'cancelled'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $filters = collect($validated)->except('page')->all();
        /** @var LengthAwarePaginator<int, EnrollmentRolloverPlan> $paginator */
        $paginator = $reads->directory($filters, 20)->withQueryString();

        return Inertia::render('App/EnrollmentRollovers/Index', [
            'plans' => $paginator->through(fn (EnrollmentRolloverPlan $p) => $this->presentSummary($p)),
            'filters' => [
                'source_academic_year_id' => $validated['source_academic_year_id'] ?? '',
                'target_academic_year_id' => $validated['target_academic_year_id'] ?? '',
                'status' => $validated['status'] ?? '',
            ],
            'academicYears' => $this->academicYearOptions(),
            'canManage' => $this->canManageRollovers($capabilities, $context->actor(), $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeRolloverManage($school);

        return Inertia::render('App/EnrollmentRollovers/Create', [
            'academicYears' => $this->academicYearOptions(),
        ]);
    }

    public function store(Request $request, TenantContext $context, EnrollmentRolloverPlanService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeRolloverManage($school);

        $validated = $request->validate([
            'source_academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            'target_academic_year_id' => ['required', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
        ]);

        $sourceYear = AcademicYear::query()->findOrFail($validated['source_academic_year_id']);
        $targetYear = AcademicYear::query()->findOrFail($validated['target_academic_year_id']);

        try {
            $plan = $service->createDraft($school, $sourceYear, $targetYear, $context->actor());
        } catch (InvalidRolloverPlanYearsException|OpenRolloverPlanConflictException|CrossSchoolRolloverPlanException $e) {
            throw ValidationException::withMessages(['target_academic_year_id' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }

    // --- Plan workspace -------------------------------------------------

    public function show(Request $request, TenantContext $context, CapabilityResolver $capabilities, EnrollmentRolloverReadService $reads, string $rollover): Response
    {
        $school = $context->requireSchool();
        $this->authorizeRolloverView($school);

        $plan = $reads->detail($rollover);
        abort_if($plan === null, 404);

        $validated = $request->validate([
            'validation_result' => ['sometimes', Rule::in(['ready', 'excluded', 'already_enrolled', 'review', 'blocked'])],
            'execution_status' => ['sometimes', Rule::in(['succeeded', 'reconciled', 'skipped', 'failed'])],
            'decision' => ['sometimes', Rule::in(['undecided', 'promote', 'repeat', 'exclude', 'manual_review'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $itemFilters = collect($validated)->except('page')->all();
        /** @var LengthAwarePaginator<int, EnrollmentRolloverItem> $itemsPaginator */
        $itemsPaginator = $reads->items($plan, $itemFilters, 20)->withQueryString();

        return Inertia::render('App/EnrollmentRollovers/Show', [
            'plan' => $this->presentDetail($plan, $reads),
            'items' => $itemsPaginator->through(fn (EnrollmentRolloverItem $i) => $this->presentItem($i)),
            'itemFilters' => [
                'validation_result' => $validated['validation_result'] ?? '',
                'execution_status' => $validated['execution_status'] ?? '',
                'decision' => $validated['decision'] ?? '',
            ],
            'canManage' => $this->canManageRollovers($capabilities, $context->actor(), $school),
            'gradeLevels' => $this->gradeLevelOptions(),
            'sourceSections' => $this->sectionOptionsForYear($plan->source_academic_year_id),
            'targetSections' => $this->sectionOptionsForYear($plan->target_academic_year_id),
            'sourceSubjectOfferings' => $this->subjectOfferingOptionsForYear($plan->source_academic_year_id),
            'targetSubjectOfferings' => $this->subjectOfferingOptionsForYear($plan->target_academic_year_id),
        ]);
    }

    // --- Lifecycle actions ------------------------------------------------

    public function validate(TenantContext $context, EnrollmentRolloverDryRunService $dryRun, string $rollover): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeRolloverManage($school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        try {
            $dryRun->run($plan, $context->actor());
        } catch (RolloverPlanNoLongerConfigurableException|InvalidRolloverPlanChronologyException|StaleRolloverConfigurationException $e) {
            throw ValidationException::withMessages(['execution' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }

    /**
     * Delegates entirely to EnrollmentRolloverExecutionService::start()
     * -- no Item loop, no Enrollment-creation call, in this controller.
     * Bounded to `BoundsRolloverExecutionRequest::MAX_ITEMS_PER_REQUEST`
     * Items for this ONE request (server-owned, never caller-controlled
     * -- no `batch_size`/`max_items` accepted anywhere in this class).
     */
    public function start(TenantContext $context, EnrollmentRolloverExecutionService $execution, string $rollover): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeRolloverManage($school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        try {
            $execution->start($plan, $context->actor(), afterEachItem: $this->stopAfterItemCap());
        } catch (RolloverPlanNotExecutionReadyException|RolloverPlanAlreadyExecutingException $e) {
            throw ValidationException::withMessages(['execution' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }

    public function resume(TenantContext $context, EnrollmentRolloverExecutionService $execution, string $rollover): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeRolloverManage($school);

        $plan = EnrollmentRolloverPlan::query()->findOrFail($rollover);

        try {
            $execution->resume($plan, $context->actor(), afterEachItem: $this->stopAfterItemCap());
        } catch (RolloverPlanNotResumableException $e) {
            throw ValidationException::withMessages(['execution' => [$e->getMessage()]]);
        }

        return redirect("/app/enrollment-rollovers/{$plan->id}");
    }

    // --- Authorization helpers ----------------------------------------

    private function authorizeRolloverView(School $school): void
    {
        $this->authorizeCapability('enrollments.view', $school);
        $this->authorizeCapability('enrollments.rollovers.view', $school);
    }

    private function authorizeRolloverManage(School $school): void
    {
        $this->authorizeCapability('enrollments.manage', $school);
        $this->authorizeCapability('enrollments.rollovers.manage', $school);
    }

    private function canManageRollovers(CapabilityResolver $capabilities, User $actor, School $school): bool
    {
        return $capabilities->canInSchool($actor, 'enrollments.manage', $school)
            && $capabilities->canInSchool($actor, 'enrollments.rollovers.manage', $school);
    }

    // --- Reference data for pickers -----------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sectionOptionsForYear(string $academicYearId): array
    {
        return Section::query()
            ->where('academic_year_id', $academicYearId)
            ->with(['gradeLevel', 'campus'])
            ->get()
            ->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name}",
                'gradeLevelId' => $s->grade_level_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Bounded reference-data option list for the subject-mapping
     * picker -- mirrors `sectionOptionsForYear()`'s identical shape
     * (whole-year, not search-as-you-type, matching the existing
     * Grade/Section mapping picker's own established convention rather
     * than inventing a live-search endpoint). Elective-only
     * (`is_required = false`, this checkpoint's brief, section 14/15)
     * -- required Offerings are never a valid mapping source or target.
     * Deliberately includes INACTIVE Offerings (no `status` filter,
     * exactly like `sectionOptionsForYear()` already does for
     * Sections) -- the `status` field lets the Vue picker render an
     * inactive warning without hiding an existing stale mapping's
     * target from view (section 16).
     *
     * @return array<int, array<string, mixed>>
     */
    private function subjectOfferingOptionsForYear(string $academicYearId): array
    {
        return SubjectOffering::query()
            ->where('academic_year_id', $academicYearId)
            ->where('is_required', false)
            ->with(['subject', 'gradeLevel', 'campus', 'electiveGroup'])
            ->orderBy('sequence')
            ->limit(100)
            ->get()
            ->map(fn (SubjectOffering $o) => $this->presentOffering($o))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function gradeLevelOptions(): array
    {
        return GradeLevel::query()->orderBy('sequence')->get()
            ->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function academicYearOptions(): array
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get()
            ->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name, 'code' => $y->code, 'startsOn' => $y->starts_on->toDateString(), 'endsOn' => $y->ends_on->toDateString(), 'status' => $y->status])
            ->all();
    }

    // --- Presentation -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(EnrollmentRolloverPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'sourceAcademicYear' => ['id' => $plan->sourceAcademicYear->id, 'name' => $plan->sourceAcademicYear->name, 'code' => $plan->sourceAcademicYear->code],
            'targetAcademicYear' => ['id' => $plan->targetAcademicYear->id, 'name' => $plan->targetAcademicYear->name, 'code' => $plan->targetAcademicYear->code],
            'status' => $plan->status,
            'configurationVersion' => $plan->configuration_version,
            'validatedConfigurationVersion' => $plan->validated_configuration_version,
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
            'subjectMappings' => $plan->subjectMappings->map(fn (EnrollmentRolloverSubjectMapping $m) => $this->presentSubjectMapping($m))->all(),
            'unmappedSourceSubjectOfferings' => $reads->unmappedSourceSubjectOfferings($plan)->map(fn (SubjectOffering $o) => $this->presentOffering($o))->all(),
            'executionSummary' => $reads->executionSummary($plan),
            'validationSummary' => $reads->validationSummary($plan),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSubjectMapping(EnrollmentRolloverSubjectMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'sourceSubjectOffering' => $this->presentOffering($mapping->sourceSubjectOffering),
            'state' => $mapping->isExplicitOmit() ? 'omit' : 'mapped',
            'targetSubjectOffering' => $mapping->isExplicitOmit() ? null : $this->presentOffering($mapping->targetSubjectOffering),
        ];
    }

    /**
     * Deliberately excludes Student PII -- Subject name/code and
     * academic dimensions (GradeLevel/Campus/ElectiveGroup) are
     * non-PII School reference/configuration data (this checkpoint's
     * brief, section 17/59).
     *
     * @return array<string, mixed>
     */
    private function presentOffering(SubjectOffering $offering): array
    {
        return [
            'id' => $offering->id,
            'subject' => $offering->subject === null ? null : ['id' => $offering->subject->id, 'name' => $offering->subject->name, 'code' => $offering->subject->code],
            'gradeLevel' => $offering->gradeLevel === null ? null : ['id' => $offering->gradeLevel->id, 'name' => $offering->gradeLevel->name],
            'campus' => $offering->campus === null ? null : ['id' => $offering->campus->id, 'name' => $offering->campus->name],
            'status' => $offering->status,
            'electiveGroup' => $offering->electiveGroup === null ? null : ['id' => $offering->electiveGroup->id, 'name' => $offering->electiveGroup->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMapping(EnrollmentRolloverMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'sourceGradeLevel' => ['id' => $mapping->sourceGradeLevel->id, 'name' => $mapping->sourceGradeLevel->name],
            'sourceSection' => $mapping->sourceSection === null ? null : ['id' => $mapping->sourceSection->id, 'name' => $mapping->sourceSection->name],
            'targetGradeLevel' => ['id' => $mapping->targetGradeLevel->id, 'name' => $mapping->targetGradeLevel->name],
            'targetSection' => $mapping->targetSection === null ? null : ['id' => $mapping->targetSection->id, 'name' => $mapping->targetSection->name],
            'isRepeat' => $mapping->isRepeat(),
        ];
    }

    /**
     * Deliberately excludes date_of_birth/Guardian PII -- the same
     * privacy boundary as the Phase 1B.7E JSON API's presenter.
     *
     * @return array<string, mixed>
     */
    private function presentItem(EnrollmentRolloverItem $item): array
    {
        return [
            'id' => $item->id,
            'student' => [
                'id' => $item->student->id,
                'studentNumber' => $item->student->student_number,
                'firstName' => $item->student->first_name,
                'middleName' => $item->student->middle_name,
                'lastName' => $item->student->last_name,
            ],
            'sourceEnrollment' => [
                'id' => $item->source_enrollment_id,
                'section' => $item->sourceEnrollment?->section === null ? null : [
                    'id' => $item->sourceEnrollment->section->id,
                    'name' => $item->sourceEnrollment->section->name,
                ],
            ],
            'decision' => $item->decision,
            'targetSection' => $item->targetSection === null ? null : ['id' => $item->targetSection->id, 'name' => $item->targetSection->name],
            'rollNumberStrategy' => $item->roll_number_strategy,
            'targetRollNumber' => $item->target_roll_number,
            'validationResult' => $item->validation_result,
            'validationReason' => $item->validation_reason,
            'executionStatus' => $item->execution_status,
            'targetEnrollmentId' => $item->target_enrollment_id,
            'executedAt' => $item->executed_at?->toIso8601String(),
        ];
    }
}
