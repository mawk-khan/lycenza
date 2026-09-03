<?php

namespace App\Http\Controllers\App\CurriculumDelivery;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\CurriculumDelivery\Application\Exceptions\CurriculumDeliveryException;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
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
 * Phase 0H.3B -- the session-authenticated administrative Curriculum
 * Delivery surface. One page: resolve an AcademicYear -> a REQUIRED
 * SubjectOffering -> a Section sharing that Offering's context -> the
 * Offering's active SyllabusUnits, each with its delivery state.
 *
 * THE NOT-STARTED PROJECTION lives here. `curriculum_deliveries`
 * deliberately stores only `in_progress` and `completed`; a unit with
 * no row has simply not been started. This controller is the canonical
 * demonstration of the required consumer discipline: it iterates the
 * SYLLABUS UNITS and left-joins whatever delivery exists, so "Not
 * started" is computed, never stored, and no row is ever pre-seeded.
 *
 * The AcademicYear/Offering context reuses the EXACT filter shape
 * App\Http\Controllers\App\SubjectOfferingController::index() and
 * App\Http\Controllers\App\Syllabus\SyllabusUnitController::index()
 * already established -- an optional `academic_year_id` defaulting to
 * the School's active year -- rather than building a new Offering,
 * Section or Unit search API. There is no new lookup infrastructure
 * here.
 *
 * Only REQUIRED Offerings are selectable: an elective has no
 * Section-wide cohort (a Student opts in individually through
 * `student_subject_enrollments`, which carries no `section_id`), so
 * "Section A covered Unit 3" has no meaning for one. The service
 * rejects an elective independently -- this filter is a usability
 * affordance, never the authority.
 *
 * Every write delegates to
 * App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
 * this controller performs no CurriculumDelivery write of its own.
 *
 * Deliberately absent: Student roster, attendance entry, timetable
 * editing, marks/grading, lesson planner, files/resources/attachments,
 * teacher portal, and any Student- or Guardian-facing view.
 */
class CurriculumDeliveryController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('curriculum.delivery.view', $school);

        $validated = $request->validate([
            'academic_year_id' => ['sometimes', 'uuid'],
            'subject_offering_id' => ['sometimes', 'uuid'],
            'section_id' => ['sometimes', 'uuid'],
        ]);

        $academicYearId = $validated['academic_year_id']
            ?? AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->value('id');

        $offerings = SubjectOffering::query()
            ->with(['subject', 'gradeLevel', 'campus'])
            ->when($academicYearId, fn ($q) => $q->where('academic_year_id', $academicYearId))
            ->where('status', 'active')
            // Required only -- see the class docblock.
            ->where('is_required', true)
            ->get();

        $selectedOffering = isset($validated['subject_offering_id'])
            ? $offerings->firstWhere('id', $validated['subject_offering_id'])
            : null;

        $sections = collect();
        $selectedSectionId = null;
        $rows = [];

        if ($selectedOffering !== null) {
            // Sections that share the Offering's exact
            // AcademicYear/Campus/GradeLevel context -- the same triple
            // the composite foreign keys pin structurally, so the page
            // can only offer combinations the database would accept.
            $sections = Section::query()
                ->where('academic_year_id', $selectedOffering->academic_year_id)
                ->where('campus_id', $selectedOffering->campus_id)
                ->where('grade_level_id', $selectedOffering->grade_level_id)
                ->where('status', 'active')
                ->orderByRaw('upper(code)')
                ->get();

            $selectedSectionId = isset($validated['section_id']) && $sections->contains('id', $validated['section_id'])
                ? $validated['section_id']
                : null;

            if ($selectedSectionId !== null) {
                $rows = $this->unitRows($selectedOffering, $selectedSectionId);
            }
        }

        return Inertia::render('App/CurriculumDelivery/Index', [
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
            ])->values()->all(),
            'sections' => $sections->map(fn (Section $s) => [
                'id' => $s->id, 'name' => $s->name, 'code' => $s->code,
            ])->values()->all(),
            'filters' => [
                'academicYearId' => $academicYearId ?? '',
                'subjectOfferingId' => $selectedOffering !== null ? $selectedOffering->id : '',
                'sectionId' => $selectedSectionId ?? '',
            ],
            'rows' => $rows,
            'canManage' => $capabilities->canInSchool($context->actor(), 'curriculum.delivery.manage', $school),
        ]);
    }

    public function store(Request $request, TenantContext $context, CurriculumDeliveryService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('curriculum.delivery.manage', $school);

        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'section_id' => ['required', 'uuid'],
            'syllabus_unit_id' => ['required', 'uuid'],
            'started_on' => ['required', 'date_format:Y-m-d'],
        ]);

        // Domain failures surface as ordinary form errors on this
        // session-authenticated surface, exactly as
        // App\Http\Controllers\App\Attendance\AttendanceController
        // already does -- the /api surface renders the same exceptions
        // with their real HTTP status through bootstrap/app.php's
        // envelope instead.
        try {
            $service->start(
                $school,
                $validated['subject_offering_id'],
                $validated['section_id'],
                $validated['syllabus_unit_id'],
                $validated['started_on'],
                $request->user(),
            );
        } catch (CurriculumDeliveryException $e) {
            throw ValidationException::withMessages(['started_on' => $e->getMessage()]);
        }

        return back();
    }

    public function update(
        Request $request,
        TenantContext $context,
        string $curriculumDelivery,
        CurriculumDeliveryService $service,
    ): RedirectResponse {
        $school = $context->requireSchool();
        $this->authorizeCapability('curriculum.delivery.manage', $school);

        $validated = $request->validate([
            'started_on' => ['sometimes', 'date_format:Y-m-d'],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        try {
            $service->correctDates(
                $school,
                $curriculumDelivery,
                $validated['started_on'] ?? null,
                $validated['completed_on'] ?? null,
                $request->user(),
            );
        } catch (CurriculumDeliveryException $e) {
            throw ValidationException::withMessages(['started_on' => $e->getMessage()]);
        }

        return back();
    }

    public function transition(
        Request $request,
        TenantContext $context,
        string $curriculumDelivery,
        CurriculumDeliveryService $service,
    ): RedirectResponse {
        $school = $context->requireSchool();
        $this->authorizeCapability('curriculum.delivery.manage', $school);

        $validated = $request->validate([
            'expected_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'new_status' => ['required', Rule::in(CurriculumDelivery::STATUSES)],
            'completed_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        try {
            $service->transition(
                $school,
                $curriculumDelivery,
                $validated['expected_status'],
                $validated['new_status'],
                $validated['completed_on'] ?? null,
                $request->user(),
            );
        } catch (CurriculumDeliveryException $e) {
            // Includes the compare-and-swap refusal: a stale tab gets a
            // visible "reload before transitioning" error rather than
            // silently overwriting a colleague's change.
            throw ValidationException::withMessages(['new_status' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * One row per ACTIVE SyllabusUnit of the Offering, in the
     * catalogue's own teaching order, each carrying the delivery state
     * for the chosen Section -- or `not_started` where no row exists.
     *
     * @return list<array<string, mixed>>
     */
    private function unitRows(SubjectOffering $offering, string $sectionId): array
    {
        $units = SyllabusUnit::query()
            ->where('subject_offering_id', $offering->id)
            ->where('status', 'active')
            ->orderBy('sequence')
            ->orderByRaw('upper(code)')
            ->get();

        // Keyed as a plain array so "there is no delivery for this
        // unit" is an honest null rather than a Collection lookup whose
        // absent case has to be asserted about.
        $deliveries = CurriculumDelivery::query()
            ->where('subject_offering_id', $offering->id)
            ->where('section_id', $sectionId)
            ->get()
            ->keyBy('syllabus_unit_id')
            ->all();

        return $units->map(function (SyllabusUnit $unit) use ($deliveries) {
            $delivery = $deliveries[$unit->id] ?? null;

            return [
                'syllabusUnitId' => $unit->id,
                'code' => $unit->code,
                'title' => $unit->title,
                'sequence' => $unit->sequence,
                // 'not_started' is a PRESENTATION value only -- it is
                // never stored, and CurriculumDelivery::STATUSES does
                // not contain it.
                'state' => $delivery === null ? 'not_started' : $delivery->status,
                'deliveryId' => $delivery?->id,
                'startedOn' => $delivery?->started_on->toDateString(),
                'completedOn' => $delivery?->completed_on?->toDateString(),
            ];
        })->values()->all();
    }
}
