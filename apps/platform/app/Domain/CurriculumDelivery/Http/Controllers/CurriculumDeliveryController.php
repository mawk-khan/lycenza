<?php

namespace App\Domain\CurriculumDelivery\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 0H.3B -- the Curriculum Delivery administrative API. Exactly
 * FIVE operations: list and start nested under the owning
 * SubjectOffering, show/correct/transition flat.
 *
 * Deliberately NO delete, NO archive, NO activate/deactivate, NO bulk,
 * NO reorder, NO search or discovery helper, NO reporting/aggregate
 * endpoint, NO teacher route and NO Student route. These rows are
 * historical instructional activity, so there is no hard-delete API at
 * all (CLAUDE.md rule 73).
 *
 * `status` moves ONLY through the dedicated transition operation,
 * never through PATCH. That command exists -- unlike Syllabus's
 * lifecycle-free PATCH -- because completing or reopening is guarded
 * by an expected-status compare-and-swap that an ordinary PATCH cannot
 * express: without it a stale administrator would silently overwrite a
 * colleague's completion. This is the same criterion that gives
 * AcademicYear/TimetablePeriod/TimetableEntry their own commands and
 * denies SyllabusUnit one.
 *
 * Thin by construction: authorize -> validate -> delegate to
 * App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService
 * -> present. Every invariant, every date rule, the row lock, the
 * transaction and every audit write live in the service; this class
 * performs no CurriculumDelivery write of its own.
 *
 * No `Idempotency-Key` (CLAUDE.md rule 29, evaluated per endpoint):
 * duplicate creation is already prevented by
 * `curriculum_deliveries_section_unit_unique`, a duplicate transition
 * fails closed on the compare-and-swap, and PATCH is naturally
 * idempotent -- so no CurriculumDelivery response is ever stored in
 * `api_idempotency_keys` and rule 36's stored-response review does not
 * arise.
 *
 * Data-minimization note: a CurriculumDelivery stores no Student,
 * Guardian, Employee or teacher identity at all, which is precisely
 * why it is classified Confidential rather than Sensitive
 * (docs/security/DATA-CLASSIFICATION.md). Nothing in this controller
 * may introduce one, and the presented projection deliberately omits
 * the four structural integrity pins -- they are database correctness
 * machinery, not client data.
 */
class CurriculumDeliveryController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school, string $subjectOffering): JsonResponse
    {
        $this->authorizeCapability('curriculum.delivery.view', $school);

        // Resolved through the tenant-scoped query (SchoolScope + RLS),
        // so another School's Offering id is a clean 404 rather than an
        // empty list that silently implies it exists.
        $offering = SubjectOffering::query()->findOrFail($subjectOffering);

        $validated = $request->validate([
            'section_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(CurriculumDelivery::STATUSES)],
        ]);

        $deliveries = CurriculumDelivery::query()
            // Ordered by the CATALOGUE's own teaching order, not by
            // anything stored here -- `syllabus_units.sequence` then
            // upper(code), the identical deterministic ordering the
            // Syllabus index uses (docs/modules/ACADEMICS.md §12).
            // Every column below is table-qualified: `subject_offering_id`
            // and `status` exist on BOTH joined tables, so an unqualified
            // reference is ambiguous SQL, not merely unclear.
            ->join('syllabus_units', 'syllabus_units.id', '=', 'curriculum_deliveries.syllabus_unit_id')
            ->where('curriculum_deliveries.subject_offering_id', $offering->id)
            ->when(isset($validated['section_id']),
                fn ($q) => $q->where('curriculum_deliveries.section_id', $validated['section_id']))
            ->when(isset($validated['status']),
                fn ($q) => $q->where('curriculum_deliveries.status', $validated['status']))
            ->orderBy('syllabus_units.sequence')
            ->orderByRaw('upper(syllabus_units.code)')
            ->select('curriculum_deliveries.*')
            ->get();

        return response()->json([
            'data' => $deliveries->map(fn (CurriculumDelivery $d) => $this->present($d))->all(),
        ]);
    }

    public function store(
        Request $request,
        School $school,
        string $subjectOffering,
        CurriculumDeliveryService $service,
    ): JsonResponse {
        $this->authorizeCapability('curriculum.delivery.manage', $school);

        $offering = SubjectOffering::query()->findOrFail($subjectOffering);

        // Only the three facts a user actually chooses. The Offering is
        // the route's, and every structural pin is derived server-side
        // by the service from the resolved parents -- never accepted
        // here (CLAUDE.md rule 19's principle).
        $validated = $request->validate([
            'section_id' => ['required', 'uuid'],
            'syllabus_unit_id' => ['required', 'uuid'],
            'started_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $delivery = $service->start(
            $school,
            $offering->id,
            $validated['section_id'],
            $validated['syllabus_unit_id'],
            $validated['started_on'],
            $request->user(),
        );

        return response()->json(['data' => $this->present($delivery)], 201);
    }

    public function show(School $school, string $curriculumDelivery): JsonResponse
    {
        $this->authorizeCapability('curriculum.delivery.view', $school);

        $delivery = CurriculumDelivery::query()->findOrFail($curriculumDelivery);

        return response()->json(['data' => $this->present($delivery)]);
    }

    /**
     * Clerical date correction ONLY. `status` is deliberately not an
     * accepted field: state changes go through transition(), which is
     * compare-and-swap guarded.
     */
    public function update(
        Request $request,
        School $school,
        string $curriculumDelivery,
        CurriculumDeliveryService $service,
    ): JsonResponse {
        $this->authorizeCapability('curriculum.delivery.manage', $school);

        $validated = $request->validate([
            'started_on' => ['sometimes', 'date_format:Y-m-d'],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $delivery = $service->correctDates(
            $school,
            $curriculumDelivery,
            $validated['started_on'] ?? null,
            $validated['completed_on'] ?? null,
            $request->user(),
        );

        return response()->json(['data' => $this->present($delivery)]);
    }

    public function transition(
        Request $request,
        School $school,
        string $curriculumDelivery,
        CurriculumDeliveryService $service,
    ): JsonResponse {
        $this->authorizeCapability('curriculum.delivery.manage', $school);

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'new_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $delivery = $service->transition(
            $school,
            $curriculumDelivery,
            $validated['expected_status'],
            $validated['new_status'],
            $validated['completed_on'] ?? null,
            $request->user(),
        );

        return response()->json(['data' => $this->present($delivery)]);
    }

    /**
     * The four structural context columns (`subject_offering_id` aside,
     * which identifies the Offering a client already navigated) are
     * integrity machinery, not client data, so `academic_year_id`,
     * `campus_id` and `grade_level_id` are deliberately not exposed.
     *
     * @return array<string, mixed>
     */
    private function present(CurriculumDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'sectionId' => $delivery->section_id,
            'subjectOfferingId' => $delivery->subject_offering_id,
            'syllabusUnitId' => $delivery->syllabus_unit_id,
            'startedOn' => $delivery->started_on->toDateString(),
            'completedOn' => $delivery->completed_on?->toDateString(),
            'status' => $delivery->status,
        ];
    }
}
