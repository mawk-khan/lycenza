<?php

namespace App\Http\Controllers\App\Timetable;

use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0H -- session-authenticated Inertia pages for the Timetable
 * schedule (Entry) grid: list/filter, placement, activation/
 * deactivation. Mirrors
 * App\Http\Controllers\App\Canteen\CanteenOrderController's shape,
 * including hosting this page's own narrow, read-only helper/search
 * endpoints directly here (the same "carry forward the Canteen
 * capability-boundary lesson" precedent -- see this controller's own
 * helper methods below).
 *
 * Data-minimization discipline (Sensitive-tier
 * docs/security/DATA-CLASSIFICATION.md): every presenter below that
 * touches an Employee (teacher) projects ONLY `id`/`fullName` -- never
 * `work_email`/`work_phone`/any other HR field. There is no Student
 * roster anywhere in this data model.
 */
class TimetableEntryController extends Controller
{
    use AuthorizesCapability;

    /** LC-2: the list's status filter. */
    private const STATUS_FILTERS = ['active', 'inactive', 'all'];

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.view', $school);

        $validated = $request->validate([
            'section_id' => ['sometimes', 'uuid'],
            'teacher_id' => ['sometimes', 'uuid'],
            'academic_year_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(self::STATUS_FILTERS)],
        ]);

        // LC-2: one academic year at a time (this year's and next year's
        // timetables live side by side), defaulting to the active year; and
        // inactive classes stay findable so they can be reactivated.
        $years = AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'school_id', 'name', 'status']);
        $defaultYear = $years->firstWhere('status', 'active') ?? $years->first();
        $yearId = $validated['academic_year_id'] ?? $defaultYear?->id;
        $status = $validated['status'] ?? 'active';

        $query = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'section.gradeLevel', 'teacher', 'room', 'period'])
            ->forAcademicYear($yearId)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('day_of_week')
            ->orderBy('period_id');

        if (isset($validated['section_id'])) {
            $query->where('section_id', $validated['section_id']);
        }
        if (isset($validated['teacher_id'])) {
            $query->where('teacher_id', $validated['teacher_id']);
        }

        $paginator = $query->paginate(50)->withQueryString();

        return Inertia::render('App/Timetable/Schedule/Index', [
            'entries' => $paginator->through(fn (TimetableEntry $e) => $this->present($e)),
            'filters' => [
                'sectionId' => $validated['section_id'] ?? '',
                'teacherId' => $validated['teacher_id'] ?? '',
                'academicYearId' => $yearId ?? '',
                'status' => $status,
            ],
            'filterOptions' => $this->filterOptions($school->id, $validated['section_id'] ?? null, $yearId, $status) + [
                'academicYears' => $years->map(fn (AcademicYear $y) => ['id' => $y->id, 'label' => "{$y->name} ({$y->status})"])->values()->all(),
            ],
            'canManage' => $capabilities->canInSchool($context->actor(), 'timetable.schedule.manage', $school),
        ]);
    }

    /**
     * X-04 (OD-V11-7, ADR 0075 section 12): the filter choices, so nobody has
     * to type an internal id. Only the Sections and teachers that appear on
     * this School's ACTIVE timetable classes -- what the list itself can
     * show, never every Section or the HR directory. LC-2: within the chosen
     * academic year, and following the status filter (inactive / all offer
     * the Sections and teachers of the classes that filter shows). Teachers narrow to the
     * chosen Section. The request contract (UUID section_id / teacher_id,
     * unknown or foreign ids match nothing) is unchanged.
     *
     * @return array{sections: list<array{id: string, label: string}>, teachers: list<array{id: string, label: string}>}
     */
    private function filterOptions(string $schoolId, ?string $sectionId, ?string $yearId, string $status): array
    {
        $scheduled = fn () => TimetableEntry::query()->where('school_id', $schoolId)->forAcademicYear($yearId)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status));

        return [
            'sections' => Section::query()->where('school_id', $schoolId)
                ->whereIn('id', $scheduled()->select('section_id'))
                ->with(['academicYear', 'gradeLevel'])->orderByRaw('upper(code)')->get()
                // Section codes are unique only within a grade, so the label names
                // the grade too ("Grade 6 · A (A) · 2026-27").
                ->map(fn (Section $s) => ['id' => $s->id, 'label' => implode(' · ', array_filter([
                    $s->gradeLevel?->name, "{$s->name} ({$s->code})", $s->academicYear?->name,
                ]))])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()->all(),
            'teachers' => Employee::query()->where('school_id', $schoolId)
                ->whereIn('id', $scheduled()->when($sectionId !== null, fn ($q) => $q->where('section_id', $sectionId))->select('teacher_id'))
                ->orderBy('full_name')->get(['id', 'school_id', 'full_name', 'employee_number'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->employee_number})"])
                ->values()->all(),
        ];
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        return Inertia::render('App/Timetable/Schedule/Create');
    }

    public function store(Request $request, TenantContext $context, TimetableScheduleService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        [$offering, $section, $teacher, $room, $period, $dayOfWeek] = $this->resolveEntryInput($request);

        // LC-2: a clash or eligibility refusal reaches the page through the
        // LC-1 renderer (the layout's refusal banner). It used to be carried
        // on `period_id`, which the form does not show once a Period is chosen.
        $service->create($school, $offering, $section, $teacher, $room, $period, $dayOfWeek, $context->actor());

        return redirect('/app/timetable-schedule')->with('flash', 'Class scheduled.');
    }

    /**
     * LC-2: re-runs the full create-time validation (TimetableScheduleService
     * ::activate) -- a class that now collides with another one in its year
     * stays inactive and the page says why (WebDomainRefusalResponder).
     * Stays on the list the operator was looking at.
     */
    public function activate(TenantContext $context, TimetableScheduleService $service, string $timetableEntry): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);
        $service->activate($entry, $context->actor());

        return back()->with('flash', 'Class activated.');
    }

    public function deactivate(TenantContext $context, TimetableScheduleService $service, string $timetableEntry): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);
        $service->deactivate($entry, $context->actor());

        return back()->with('flash', 'Class deactivated.');
    }

    /**
     * LC-2: correct a scheduled class in the browser -- its teacher, room,
     * period and day. The Subject Offering and Section are what the class
     * IS; a different one is a different class (schedule it, deactivate
     * this one). Same service and validation as creating one, scoped to the
     * class's own academic year; a collision is refused and nothing changes.
     */
    public function edit(TenantContext $context, string $timetableEntry): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'subjectOffering.academicYear', 'section.gradeLevel', 'teacher', 'room', 'period'])
            ->findOrFail($timetableEntry);

        return Inertia::render('App/Timetable/Schedule/Edit', [
            'entry' => $this->present($entry) + [
                'academicYearName' => $entry->subjectOffering?->academicYear?->name,
                'roomId' => $entry->room_id,
                'periodId' => $entry->period_id,
            ],
        ]);
    }

    public function update(Request $request, TenantContext $context, TimetableScheduleService $service, string $timetableEntry): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);
        $validated = $request->validate([
            'teacher_id' => ['required', 'uuid'],
            'room_id' => ['sometimes', 'nullable', 'uuid'],
            'period_id' => ['required', 'uuid'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
        ]);

        $service->update(
            $entry,
            $entry->subjectOffering()->firstOrFail(),
            $entry->section()->firstOrFail(),
            Employee::query()->findOrFail($validated['teacher_id']),
            isset($validated['room_id']) ? Room::query()->findOrFail($validated['room_id']) : null,
            TimetablePeriod::query()->findOrFail($validated['period_id']),
            (int) $validated['day_of_week'],
            $context->actor(),
        );

        return redirect('/app/timetable-schedule?'.http_build_query(['academic_year_id' => $entry->academic_year_id, 'status' => 'all']))
            ->with('flash', 'Class updated.');
    }

    // --- Timetable-owned helper/search endpoints ---------------------------
    //
    // Deliberately narrow, read-only, and gated by Timetable's OWN
    // capabilities -- NEVER Academic Structure's `academics.*` or HR's
    // employee-management capabilities (the Canteen capability-boundary
    // lesson, carried forward explicitly). Every helper authorizes
    // BEFORE running its query. Used only by Schedule/Create.vue's
    // pickers.

    public function searchSubjectOfferings(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);

        $query = SubjectOffering::query()
            ->with(['subject', 'gradeLevel', 'campus', 'academicYear'])
            ->where('status', 'active')
            ->where('is_required', true);

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->whereHas('subject', fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $offerings = $query->limit(20)->get();

        return response()->json(['data' => $offerings->map(fn (SubjectOffering $o) => [
            'id' => $o->id,
            'subjectCode' => $o->subject->code,
            'subjectName' => $o->subject->name,
            'gradeLevelName' => $o->gradeLevel->name,
            'campusName' => $o->campus->name,
            'academicYearName' => $o->academicYear->name,
        ])->all()]);
    }

    public function searchSections(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        $query = Section::query()->where('status', 'active')->with(['gradeLevel', 'academicYear'])->orderBy('code');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term)
                ->orWhereHas('gradeLevel', fn ($g) => $g->where('name', 'ilike', $term)));
        }

        $sections = $query->limit(20)->get()->sortBy(fn (Section $s) => SectionLabel::sortKey($s))->values();

        // UAT-C4 (UAT-ATT-08): Section names repeat across Grades and years, so
        // each result names both ("Grade 6 · A · 2026-27").
        return response()->json(['data' => $sections->map(fn (Section $s) => [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'label' => implode(' · ', array_filter([SectionLabel::of($s), $s->academicYear?->name])),
        ])->all()]);
    }

    /**
     * Teacher lookup -- projects ONLY `id`/`fullName` (this class's own
     * docblock).
     */
    public function searchTeachers(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        $query = Employee::query()->where('record_status', 'active')->orderBy('full_name');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->where('full_name', 'ilike', $term);
        }

        $teachers = $query->limit(20)->get();

        return response()->json(['data' => $teachers->map(fn (Employee $e) => [
            'id' => $e->id,
            'fullName' => $e->full_name,
        ])->all()]);
    }

    public function searchRooms(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        $query = Room::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $rooms = $query->limit(20)->get();

        return response()->json(['data' => $rooms->map(fn (Room $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->name,
        ])->all()]);
    }

    /**
     * Period lookup for this same Create form -- gated by
     * `timetable.periods.view` (Timetable's own Period-directory
     * capability), matching
     * App\Domain\Timetable\Http\Controllers\TimetableEntryController::searchPeriods()'s
     * identical choice at the API layer.
     */
    public function searchPeriods(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.periods.view', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        $query = TimetablePeriod::query()->where('status', 'active')->orderBy('sort_order')->orderBy('code');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $periods = $query->limit(20)->get();

        return response()->json(['data' => $periods->map(fn (TimetablePeriod $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'startTime' => $p->start_time,
            'endTime' => $p->end_time,
        ])->all()]);
    }

    /**
     * @return array{0: SubjectOffering, 1: Section, 2: Employee, 3: ?Room, 4: TimetablePeriod, 5: int}
     */
    private function resolveEntryInput(Request $request): array
    {
        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'section_id' => ['required', 'uuid'],
            'teacher_id' => ['required', 'uuid'],
            'room_id' => ['sometimes', 'nullable', 'uuid'],
            'period_id' => ['required', 'uuid'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
        ]);

        $offering = SubjectOffering::query()->findOrFail($validated['subject_offering_id']);
        $section = Section::query()->findOrFail($validated['section_id']);
        $teacher = Employee::query()->findOrFail($validated['teacher_id']);
        $room = isset($validated['room_id']) ? Room::query()->findOrFail($validated['room_id']) : null;
        $period = TimetablePeriod::query()->findOrFail($validated['period_id']);

        return [$offering, $section, $teacher, $room, $period, (int) $validated['day_of_week']];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TimetableEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'status' => $entry->status,
            'dayOfWeek' => $entry->day_of_week,
            'subjectCode' => $entry->subjectOffering?->subject?->code,
            'subjectName' => $entry->subjectOffering?->subject?->name,
            'sectionCode' => $entry->section?->code,
            'sectionName' => $entry->section?->name,
            'classLabel' => SectionLabel::of($entry->section),
            'teacherId' => $entry->teacher_id,
            'teacherName' => $entry->teacher?->full_name,
            'roomCode' => $entry->room?->code,
            'roomName' => $entry->room?->name,
            'periodCode' => $entry->period?->code,
            'periodName' => $entry->period?->name,
            'periodStartTime' => $entry->period?->start_time,
            'periodEndTime' => $entry->period?->end_time,
        ];
    }
}
