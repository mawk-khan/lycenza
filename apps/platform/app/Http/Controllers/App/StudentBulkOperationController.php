<?php

namespace App\Http\Controllers\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Bulk\BulkPlacementNotExecutedException;
use App\Domain\Students\Application\Bulk\BulkPlacementOperation;
use App\Domain\Students\Application\Bulk\BulkPlacementRefusedException;
use App\Domain\Students\Application\Bulk\BulkPlacementRequest;
use App\Domain\Students\Application\Bulk\BulkStudentPlacementService;
use App\Domain\Students\Application\SectionOccupancyReader;
use App\Domain\Students\Application\StudentAccess;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SIS 11.13: Students → Bulk operations -- Change Section and Transfer Campus
 * for a reviewed selection of one class. Thin: the floor is
 * `enrollments.manage` in any scope (Campus-adopted); the service decides
 * what that scope may do (a Section change is whole-School, a Campus-scoped
 * actor transfers between its own Campuses, today) and re-reads authority
 * fresh at execution. The confirmation checks the signed preview BEFORE a
 * fresh MFA code is consumed (the import's order).
 */
class StudentBulkOperationController extends Controller
{
    use AuthorizesCampusScope;

    /** A Section's roster is listed whole (no pagination, so nothing selected is ever off-screen). */
    private const LIST_LIMIT = 200;

    private const SESSION_KEY = 'bulkPlacementRequest';

    public function index(Request $request, TenantContext $context, StudentAccess $access, SectionOccupancyReader $occupancy, BulkStudentPlacementService $bulk): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope(BulkStudentPlacementService::CAPABILITY, $school);
        $validated = $request->validate([
            'operation' => ['nullable', Rule::enum(BulkPlacementOperation::class)],
            'section_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $operations = $scope->isWholeSchool() ? BulkPlacementOperation::cases() : [BulkPlacementOperation::CampusTransfer];
        $operation = BulkPlacementOperation::tryFrom((string) ($validated['operation'] ?? '')) ?? $operations[0];
        if (! in_array($operation, $operations, true)) {
            $operation = $operations[0];
        }

        $data = $context->withSchool($school, function () use ($school, $scope, $access, $occupancy, $bulk, $validated, $operation): array {
            $sections = $this->sourceSections($school, $scope);
            $source = $sections->firstWhere('id', $validated['section_id'] ?? null);

            return [
                'years' => AcademicYear::query()->where('school_id', $school->id)->where('status', '!=', 'closed')->orderBy('starts_on')->get(['id', 'name', 'status'])
                    ->map(fn (AcademicYear $y) => ['id' => (string) $y->id, 'name' => $y->name, 'status' => $y->status])->all(),
                'campuses' => $scope->restrict(Campus::query()->where('school_id', $school->id)->where('status', 'active'), 'campuses.id')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Campus $c) => ['id' => (string) $c->id, 'name' => $c->name])->all(),
                'gradeLevels' => GradeLevel::query()->where('school_id', $school->id)->where('status', 'active')->orderBy('sequence')->get(['id', 'name'])
                    ->map(fn (GradeLevel $g) => ['id' => (string) $g->id, 'name' => $g->name])->all(),
                'sections' => $sections->map(fn (Section $s) => [
                    'id' => (string) $s->id, 'label' => $this->label($s),
                    'academicYearId' => (string) $s->academic_year_id, 'campusId' => (string) $s->campus_id, 'gradeLevelId' => (string) $s->grade_level_id,
                ])->values()->all(),
                'source' => $source === null ? null : ['id' => (string) $source->id, 'label' => $this->label($source), 'academicYearId' => (string) $source->academic_year_id],
                'students' => $source === null ? [] : $bulk->sourceRoster($school, $scope, $source, (string) ($validated['q'] ?? ''), self::LIST_LIMIT),
                'targets' => $source === null ? [] : $this->targets($school, $scope, $access, $occupancy, $source, $operation),
            ];
        });

        return Inertia::render('App/Students/BulkOperations/Index', [
            ...$data,
            'operations' => array_map(fn (BulkPlacementOperation $o) => ['value' => $o->value, 'label' => $o->label()], $operations),
            'filters' => ['operation' => $operation->value, 'section_id' => $validated['section_id'] ?? '', 'q' => $validated['q'] ?? ''],
            'today' => SchoolTimezone::today($school),
            'effectiveDateEditable' => $scope->isWholeSchool(),
            'limits' => ['students' => BulkStudentPlacementService::MAX_STUDENTS, 'list' => self::LIST_LIMIT],
            // A refusal that belongs to Academic-Year Rollover links to it.
            'rolloverHint' => (bool) $request->session()->get('bulkPlacementRollover', false),
        ]);
    }

    /** Keeps the selection (ids and roll numbers only, never names) and shows its review. */
    public function preview(Request $request, TenantContext $context, BulkStudentPlacementService $bulk): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope(BulkStudentPlacementService::CAPABILITY, $school);
        $bulkRequest = $this->bulkRequest($request);

        try {
            // Evaluated once here so an impossible move (another year, wrong class) goes straight back to the selection.
            $bulk->preview($school, $context->actor(), $scope, $bulkRequest);
        } catch (BulkPlacementRefusedException $e) {
            return $this->refused($bulkRequest, $e);
        }
        $request->session()->put(self::SESSION_KEY, ['schoolId' => $school->id, 'actorId' => $context->actor()->id, 'request' => $bulkRequest->canonical()]);

        return redirect('/app/students/bulk-operations/review');
    }

    /**
     * The review of the kept selection, re-evaluated on every visit (a
     * refresh or Back re-checks; it never executes). The preview commits
     * nothing (BulkStudentPlacementService::preview()).
     */
    public function review(Request $request, TenantContext $context, BulkStudentPlacementService $bulk, FreshMfaRequirement $mfa): Response|RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $scope = $this->authorizeCampusScope(BulkStudentPlacementService::CAPABILITY, $school);
        $kept = $request->session()->get(self::SESSION_KEY);
        $bulkRequest = is_array($kept) && ($kept['schoolId'] ?? null) === $school->id && ($kept['actorId'] ?? null) === $actor->id
            ? BulkPlacementRequest::fromCanonical($kept['request'] ?? null)
            : null;
        if ($bulkRequest === null) {
            return redirect('/app/students/bulk-operations');
        }

        try {
            $preview = $bulk->preview($school, $actor, $scope, $bulkRequest);
        } catch (BulkPlacementRefusedException $e) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->refused($bulkRequest, $e);
        }

        return Inertia::render('App/Students/BulkOperations/Review', [
            'preview' => $preview,
            'refusal' => $request->session()->get('bulkPlacementRefusal'),
            'hasMfaFactor' => $mfa->hasActiveFactor($actor),
            'previewMinutes' => BulkStudentPlacementService::PREVIEW_TTL_MINUTES,
        ]);
    }

    public function execute(Request $request, TenantContext $context, BulkStudentPlacementService $bulk, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $scope = $this->authorizeCampusScope(BulkStudentPlacementService::CAPABILITY, $school);
        $bulkRequest = $this->bulkRequest($request);
        $validated = $request->validate([
            'issued_at' => ['required', 'string', 'max:40'],
            'signature' => ['required', 'string', 'size:64'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that you reviewed every Student before moving them.']);

        try {
            $bulk->assertSigned($school, $actor, $scope, $bulkRequest, $validated['issued_at'], $validated['signature']);
        } catch (BulkPlacementRefusedException $e) {
            return redirect('/app/students/bulk-operations/review')->with('bulkPlacementRefusal', $e->getMessage());
        }
        // After authority and the signature, outside the business transaction.
        $proof = $mfa->requireForAction($request, $actor);

        try {
            $result = $bulk->execute($school, $actor, $bulkRequest, $validated['issued_at'], $validated['signature'], $proof);
        } catch (BulkPlacementNotExecutedException|BulkPlacementRefusedException $e) {
            // Nothing was moved: the review re-evaluates the same selection
            // (kept again, e.g. for a repeated confirmation after a success).
            $request->session()->put(self::SESSION_KEY, ['schoolId' => $school->id, 'actorId' => $actor->id, 'request' => $bulkRequest->canonical()]);

            return redirect('/app/students/bulk-operations/review')->with('bulkPlacementRefusal', $e->getMessage());
        }
        $request->session()->forget(self::SESSION_KEY);

        return redirect('/app/students/bulk-operations/result')->with('bulkPlacementResult', $result);
    }

    public function result(Request $request, TenantContext $context): Response|RedirectResponse
    {
        $this->authorizeCampusScope(BulkStudentPlacementService::CAPABILITY, $context->requireSchool());
        $result = $request->session()->get('bulkPlacementResult');
        if (! is_array($result)) {
            return redirect('/app/students/bulk-operations');
        }

        return Inertia::render('App/Students/BulkOperations/Result', ['result' => $result]);
    }

    // --- Helpers ------------------------------------------------------------

    private function bulkRequest(Request $request): BulkPlacementRequest
    {
        $validated = $request->validate([
            'operation' => ['required', Rule::enum(BulkPlacementOperation::class)],
            'source_section_id' => ['required', 'uuid'],
            'target_section_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'min:1', 'max:'.BulkStudentPlacementService::MAX_STUDENTS],
            'rows.*' => ['required', 'array:student_id,enrollment_id,roll_number'],
            'rows.*.student_id' => ['required', 'uuid', 'distinct'],
            'rows.*.enrollment_id' => ['required', 'uuid'],
            'rows.*.roll_number' => ['nullable', 'string', 'max:255'],
        ], [
            'rows.required' => 'Select at least one Student.',
            'rows.min' => 'Select at least one Student.',
            'rows.max' => 'A bulk move takes at most '.BulkStudentPlacementService::MAX_STUDENTS.' Students at a time.',
            'rows.*.student_id.distinct' => 'Each Student can be selected only once.',
        ]);

        return new BulkPlacementRequest(
            BulkPlacementOperation::from($validated['operation']),
            $validated['source_section_id'],
            $validated['target_section_id'],
            $validated['effective_date'],
            array_map(fn (array $r) => [
                'studentId' => (string) $r['student_id'],
                'enrollmentId' => (string) $r['enrollment_id'],
                'rollNumber' => (string) ($r['roll_number'] ?? ''),
            ], array_values($validated['rows'])),
        );
    }

    private function refused(BulkPlacementRequest $request, BulkPlacementRefusedException $e): RedirectResponse
    {
        $query = http_build_query(['operation' => $request->operation->value, 'section_id' => $request->sourceSectionId]);

        return redirect("/app/students/bulk-operations?{$query}")
            ->withErrors(['refusal' => $e->getMessage()])
            ->with('bulkPlacementRollover', $e->rollover);
    }

    /**
     * Source classes: active Sections the scope may move Students out of --
     * whole School: every year not closed; Campus scope: the active year on
     * in-scope Campuses (a Campus transfer is of the CURRENT placement).
     *
     * @return Collection<int, Section>
     */
    private function sourceSections(School $school, CampusScope $scope): Collection
    {
        $query = Section::query()->where('sections.school_id', $school->id)->where('sections.status', 'active')
            ->whereHas('academicYear', fn ($q) => $scope->isWholeSchool() ? $q->where('status', '!=', 'closed') : $q->where('status', 'active'))
            ->whereHas('campus', fn ($q) => $q->where('status', 'active'))
            ->with(['academicYear', 'campus', 'gradeLevel']);
        $scope->restrict($query, 'sections.campus_id');

        return $query->get()->sortBy(fn (Section $s) => [$s->academicYear->starts_on->toDateString(), $s->campus->name, $s->gradeLevel->sequence, $s->name])->values();
    }

    /**
     * Target classes for the operation: a Section change -- active Sections
     * of the same year, Campus and Grade (whole School); a Campus transfer --
     * StudentAccess::transferDestinationsFor() (another active, in-scope
     * Campus). Each with SIS 11.3's advisory occupancy. Advisory only: the
     * preview and the execution re-decide everything.
     *
     * @return list<array<string, mixed>>
     */
    private function targets(School $school, CampusScope $scope, StudentAccess $access, SectionOccupancyReader $occupancy, Section $source, BulkPlacementOperation $operation): array
    {
        if ($operation === BulkPlacementOperation::CampusTransfer) {
            return $access->transferDestinationsFor($school, $scope, $source->academic_year_id, $source->grade_level_id, $source->campus_id);
        }
        if (! $scope->isWholeSchool()) {
            return [];
        }
        $sections = Section::query()->where('sections.school_id', $school->id)->where('academic_year_id', $source->academic_year_id)
            ->where('campus_id', $source->campus_id)->where('grade_level_id', $source->grade_level_id)
            ->where('status', 'active')->whereKeyNot($source->id)->with(['academicYear', 'campus', 'gradeLevel'])->orderBy('name')->get();
        $occupied = $occupancy->forSections($school, $sections);

        return $sections->map(fn (Section $s) => ['id' => (string) $s->id, 'label' => "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name}", 'occupancy' => $occupied[(string) $s->id]->label()])->values()->all();
    }

    private function label(Section $s): string
    {
        return "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name} · {$s->academicYear->name}";
    }
}
