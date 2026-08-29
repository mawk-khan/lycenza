<?php

namespace App\Http\Controllers\App\Timetable;

use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\Exceptions\TimetableException;
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
use Illuminate\Validation\ValidationException;
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

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.view', $school);

        $validated = $request->validate([
            'section_id' => ['sometimes', 'uuid'],
            'teacher_id' => ['sometimes', 'uuid'],
        ]);

        $query = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'section', 'teacher', 'room', 'period'])
            ->where('status', 'active')
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
            ],
            'canManage' => $capabilities->canInSchool($context->actor(), 'timetable.schedule.manage', $school),
        ]);
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

        try {
            $service->create($school, $offering, $section, $teacher, $room, $period, $dayOfWeek, $context->actor());
        } catch (TimetableException $e) {
            // Reuses the form's own `period_id` field to carry the
            // message -- every conflict/eligibility exception this
            // service can throw (double-booking, an inactive parent,
            // ...) is fundamentally about whether THIS Period/day
            // combination is schedulable, mirroring
            // CanteenOrderController's identical "reuse an existing
            // form field, don't invent a synthetic error key" choice
            // for its own `lines` field.
            throw ValidationException::withMessages(['period_id' => [$e->getMessage()]]);
        }

        return redirect('/app/timetable-schedule')->with('flash', 'Class scheduled.');
    }

    public function activate(TenantContext $context, TimetableScheduleService $service, string $timetableEntry): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);

        try {
            $service->activate($entry, $context->actor());
        } catch (TimetableException $e) {
            return redirect('/app/timetable-schedule')->withErrors(['schedule' => $e->getMessage()]);
        }

        return redirect('/app/timetable-schedule')->with('flash', 'Class activated.');
    }

    public function deactivate(TenantContext $context, TimetableScheduleService $service, string $timetableEntry): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);
        $service->deactivate($entry, $context->actor());

        return redirect('/app/timetable-schedule')->with('flash', 'Class deactivated.');
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
        $query = Section::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $sections = $query->limit(20)->get();

        return response()->json(['data' => $sections->map(fn (Section $s) => [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
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
