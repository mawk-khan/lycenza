<?php

namespace App\Domain\Timetable\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0H -- the Timetable schedule (Entry) administrative API.
 * Mirrors App\Domain\Canteen\Http\Controllers\CanteenOrderController's
 * shape exactly, including hosting this page's own narrow, read-only
 * helper/search endpoints directly on the controller that needs them
 * (the SAME "carry forward the Canteen capability-boundary lesson"
 * precedent CanteenOrderController::searchStudents()/searchItems()
 * established: never reuse another module's capability just because
 * that module already exposes a similar-shaped lookup).
 *
 * Data-minimization discipline (Sensitive-tier
 * docs/security/DATA-CLASSIFICATION.md): every presenter below that
 * touches an Employee (teacher) projects ONLY `id`/`fullName` -- never
 * `work_email`/`work_phone`/any other HR field. There is no Student
 * roster anywhere in this data model, so that minimization concern
 * does not apply here.
 */
class TimetableEntryController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('timetable.schedule.view', $school);

        $validated = $request->validate([
            'section_id' => ['sometimes', 'uuid'],
            'teacher_id' => ['sometimes', 'uuid'],
            'day_of_week' => ['sometimes', 'integer', 'between:1,7'],
        ]);

        $query = TimetableEntry::query()
            ->with(['subjectOffering.subject', 'section', 'teacher', 'room', 'period'])
            ->orderBy('day_of_week')
            ->orderBy('period_id');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }
        if (isset($validated['section_id'])) {
            $query->where('section_id', $validated['section_id']);
        }
        if (isset($validated['teacher_id'])) {
            $query->where('teacher_id', $validated['teacher_id']);
        }
        if (isset($validated['day_of_week'])) {
            $query->where('day_of_week', $validated['day_of_week']);
        }

        $paginator = $query->paginate(50)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (TimetableEntry $e) => $this->present($e))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, TimetableScheduleService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.schedule.manage', $school);

        [$offering, $section, $teacher, $room, $period, $dayOfWeek] = $this->resolveEntryInput($request, $school);

        $entry = $service->create($school, $offering, $section, $teacher, $room, $period, $dayOfWeek, $request->user());
        $entry->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)], 201);
    }

    public function show(School $school, string $timetableEntry): JsonResponse
    {
        $this->authorizeCapability('timetable.schedule.view', $school);

        $entry = TimetableEntry::query()->with(['subjectOffering.subject', 'section', 'teacher', 'room', 'period'])->findOrFail($timetableEntry);

        return response()->json(['data' => $this->present($entry)]);
    }

    public function update(Request $request, School $school, string $timetableEntry, TimetableScheduleService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);
        [$offering, $section, $teacher, $room, $period, $dayOfWeek] = $this->resolveEntryInput($request, $school);

        $entry = $service->update($entry, $offering, $section, $teacher, $room, $period, $dayOfWeek, $request->user());
        $entry->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)]);
    }

    public function activate(Request $request, School $school, string $timetableEntry, TimetableScheduleService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);
        $entry = $service->activate($entry, $request->user());
        $entry->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)]);
    }

    public function deactivate(Request $request, School $school, string $timetableEntry, TimetableScheduleService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.schedule.manage', $school);

        $entry = TimetableEntry::query()->findOrFail($timetableEntry);
        $entry = $service->deactivate($entry, $request->user());
        $entry->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)]);
    }

    // --- Timetable-owned helper/search endpoints ---------------------------
    //
    // Deliberately narrow and read-only, gated by Timetable's OWN
    // capabilities -- NEVER Academic Structure's `academics.*` or HR's
    // employee-management capabilities, carrying forward the Canteen
    // capability-boundary lesson (CanteenOutletController::
    // searchInventoryLocations()'s docblock) explicitly. Every helper
    // authorizes BEFORE running its query.

    public function searchSubjectOfferings(Request $request, School $school): JsonResponse
    {
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

    public function searchSections(Request $request, School $school): JsonResponse
    {
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
     * Teacher lookup -- projects ONLY `id`/`fullName`, never
     * `work_email`/`work_phone`/any other HR field (this class's own
     * docblock).
     */
    public function searchTeachers(Request $request, School $school): JsonResponse
    {
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

    public function searchRooms(Request $request, School $school): JsonResponse
    {
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
     * Period lookup for the Schedule form's Period picker -- gated by
     * `timetable.periods.view` (Timetable's own Period-directory
     * capability, not `timetable.schedule.*`) per this checkpoint's
     * brief, since a TimetablePeriod is itself owned by that other
     * capability pair.
     */
    public function searchPeriods(Request $request, School $school): JsonResponse
    {
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
    private function resolveEntryInput(Request $request, School $school): array
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
            'subjectOfferingId' => $entry->subject_offering_id,
            'subjectCode' => $entry->subjectOffering?->subject?->code,
            'subjectName' => $entry->subjectOffering?->subject?->name,
            'sectionId' => $entry->section_id,
            'sectionCode' => $entry->section?->code,
            'sectionName' => $entry->section?->name,
            // Teacher: id + display name ONLY -- never work_email/work_phone
            // (this class's own docblock, Sensitive-tier data minimization).
            'teacherId' => $entry->teacher_id,
            'teacherName' => $entry->teacher?->full_name,
            'roomId' => $entry->room_id,
            'roomCode' => $entry->room?->code,
            'roomName' => $entry->room?->name,
            'periodId' => $entry->period_id,
            'periodCode' => $entry->period?->code,
            'periodName' => $entry->period?->name,
            'periodStartTime' => $entry->period?->start_time,
            'periodEndTime' => $entry->period?->end_time,
        ];
    }
}
