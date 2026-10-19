<?php

namespace App\Domain\Timetable\Http\Controllers;

use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\TimetableScheduleAccess;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
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
 *
 * ORG.7D-1 (ADR 0076 §37): `timetable.schedule.*` are Campus-adopted. Every
 * action resolves the actor's CampusScope and reads or targets entries,
 * Offerings, Sections and Rooms only through TimetableScheduleAccess
 * (out of scope = 404). The teacher and bell-schedule lookups are the
 * declared School-wide shared lookups (CampusScopeCatalogue::DOMAINS).
 * `search/periods` stays on whole-School `timetable.periods.view`.
 */
class TimetableEntryController extends Controller
{
    use AuthorizesCampusScope;
    use AuthorizesCapability;

    public function index(Request $request, School $school, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.view', $school);

        $validated = $request->validate([
            'section_id' => ['sometimes', 'uuid'],
            'teacher_id' => ['sometimes', 'uuid'],
            'day_of_week' => ['sometimes', 'integer', 'between:1,7'],
        ]);

        $query = $access->entries($scope)
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

    public function store(Request $request, School $school, TimetableScheduleService $service, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.manage', $school);

        [$offering, $section, $teacher, $room, $period, $dayOfWeek] = $this->resolveEntryInput($request, $access, $scope);

        $entry = $service->create($school, $offering, $section, $teacher, $room, $period, $dayOfWeek, $request->user());
        $entry->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)], 201);
    }

    public function show(School $school, string $timetableEntry, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.view', $school);

        $entry = $access->entry($scope, $timetableEntry)->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)]);
    }

    public function update(Request $request, School $school, string $timetableEntry, TimetableScheduleService $service, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.manage', $school);

        $entry = $access->entry($scope, $timetableEntry);
        [$offering, $section, $teacher, $room, $period, $dayOfWeek] = $this->resolveEntryInput($request, $access, $scope);

        $entry = $service->update($entry, $offering, $section, $teacher, $room, $period, $dayOfWeek, $request->user());
        $entry->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)]);
    }

    public function activate(Request $request, School $school, string $timetableEntry, TimetableScheduleService $service, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.manage', $school);

        $entry = $access->entry($scope, $timetableEntry);
        $entry = $service->activate($entry, $request->user());
        $entry->load(['subjectOffering.subject', 'section', 'teacher', 'room', 'period']);

        return response()->json(['data' => $this->present($entry)]);
    }

    public function deactivate(Request $request, School $school, string $timetableEntry, TimetableScheduleService $service, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.manage', $school);

        $entry = $access->entry($scope, $timetableEntry);
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

    public function searchSubjectOfferings(Request $request, School $school, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);

        $query = $access->offerings($scope)
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

    public function searchSections(Request $request, School $school, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);

        $query = $access->sections($scope)->where('status', 'active')->orderBy('code');

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
     * docblock). ORG.7D-1: a declared School-wide shared lookup (active
     * Employees, id and name only), so it answers a Campus-scoped scheduler.
     */
    public function searchTeachers(Request $request, School $school): JsonResponse
    {
        $this->authorizeCampusScope('timetable.schedule.manage', $school);

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

    public function searchRooms(Request $request, School $school, TimetableScheduleAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('timetable.schedule.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);

        $query = $access->rooms($scope)->where('status', 'active')->orderBy('code');

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

        return $this->periodLookup($request);
    }

    /**
     * ORG.7D-1 (ADR 0076 §37): the same bell-schedule lookup for the
     * scheduling form, under the Campus-adopted `timetable.schedule.manage`
     * -- a declared School-wide shared lookup (CampusScopeCatalogue::DOMAINS
     * timetable), so a Campus-scoped scheduler can pick a Period. The Period
     * catalogue stays whole-School configuration.
     */
    public function searchSchedulePeriods(Request $request, School $school): JsonResponse
    {
        $this->authorizeCampusScope('timetable.schedule.manage', $school);

        return $this->periodLookup($request);
    }

    /** Active Periods of the School-wide bell schedule: code, name and times only. */
    private function periodLookup(Request $request): JsonResponse
    {
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
     * ORG.7D-1: the Offering, Section and Room are resolved within the
     * actor's CampusScope (out of scope = 404); the teacher and Period are
     * School-wide.
     *
     * @return array{0: SubjectOffering, 1: Section, 2: Employee, 3: ?Room, 4: TimetablePeriod, 5: int}
     */
    private function resolveEntryInput(Request $request, TimetableScheduleAccess $access, CampusScope $scope): array
    {
        $validated = $request->validate([
            'subject_offering_id' => ['required', 'uuid'],
            'section_id' => ['required', 'uuid'],
            'teacher_id' => ['required', 'uuid'],
            'room_id' => ['sometimes', 'nullable', 'uuid'],
            'period_id' => ['required', 'uuid'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
        ]);

        $offering = $access->offering($scope, $validated['subject_offering_id']);
        $section = $access->section($scope, $validated['section_id']);
        $teacher = Employee::query()->findOrFail($validated['teacher_id']);
        $room = isset($validated['room_id']) ? $access->room($scope, $validated['room_id']) : null;
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
