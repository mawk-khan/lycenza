<?php

namespace Tests\Feature\Timetable;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * ORG.7D-1 D1-B (ADR 0076 §37) -- the canary of the academic wave:
 * `timetable.schedule.*` are Campus-adopted. A Campus-scoped scheduler sees
 * and changes only its Campuses' classes; another Campus's class, Offering,
 * Section or Room is NOT FOUND; a request filter only narrows; a slot
 * collision never says whose or where; the bell schedule, Data Health and
 * whole-School `can()` are unchanged.
 */
class TimetableCampusScopeTest extends TestCase
{
    use CreatesTimetableFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $year;

    private GradeLevel $grade;

    private TimetablePeriod $p1;

    private TimetablePeriod $p2;

    private Employee $teacherA;

    private Employee $teacherB;

    /** @var array<string, array{offering: SubjectOffering, section: Section, room: Room, entry: TimetableEntry}> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->b = $this->createCampus($this->school, ['name' => 'South Campus']);
        $this->year = $this->createAcademicYear($this->school, ['status' => 'active']);
        $this->grade = $this->createGradeLevel($this->school);
        $this->p1 = $this->createTimetablePeriod($this->school, ['code' => 'P1', 'start_time' => '09:00:00', 'end_time' => '09:45:00', 'status' => 'active']);
        $this->p2 = $this->createTimetablePeriod($this->school, ['code' => 'P2', 'start_time' => '10:00:00', 'end_time' => '10:45:00', 'status' => 'active']);
        $this->teacherA = $this->createEmployee($this->school, ['record_status' => 'active', 'full_name' => 'Asha North']);
        $this->teacherB = $this->createEmployee($this->school, ['record_status' => 'active', 'full_name' => 'Bilal South']);

        foreach (['a' => [$this->a, $this->teacherA], 'b' => [$this->b, $this->teacherB]] as $key => [$campus, $teacher]) {
            $offering = $this->createSubjectOffering($this->year, $campus, $this->grade, $this->createSubject($this->school, ['code' => 'SUB'.strtoupper($key)]), ['is_required' => true, 'status' => 'active']);
            $section = $this->createSection($this->year, $campus, $this->grade, ['status' => 'active', 'code' => 'SEC'.strtoupper($key)]);
            $room = $this->createRoom($campus, ['status' => 'active', 'code' => 'ROOM'.strtoupper($key)]);
            $entry = $this->createTimetableEntry($offering, $section, $teacher, $this->p1, ['day_of_week' => 1, 'status' => 'active', 'room_id' => $room->id]);
            $this->at[$key] = compact('offering', 'section', 'room', 'entry');
        }
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function principal(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'principal', $campusId);
        }

        return $user;
    }

    private function api(User $user): self
    {
        // The Sanctum guard keeps the first authenticated user for the whole
        // test; forget it so every request authenticates its own token.
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/schools/{$this->school->id}/timetable-entries{$path}";
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    /** @return list<string> */
    private function apiEntryIds(User $user, array $query = []): array
    {
        return collect($this->api($user)->getJson($this->url().'?'.http_build_query($query))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    /** @return list<string> */
    private function ids(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->at[$k]['entry']->id)->sort()->values()->all();
    }

    private function payload(string $key, Employee $teacher, TimetablePeriod $period, int $day, ?Section $section = null, ?Room $room = null): array
    {
        return [
            'subject_offering_id' => $this->at[$key]['offering']->id,
            'section_id' => ($section ?? $this->at[$key]['section'])->id,
            'teacher_id' => $teacher->id,
            'room_id' => $room?->id,
            'period_id' => $period->id,
            'day_of_week' => $day,
        ];
    }

    private function entryCount(): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => TimetableEntry::query()->count());
    }

    private function entryStatus(string $key): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => TimetableEntry::query()->findOrFail($this->at[$key]['entry']->id)->status);
    }

    #[Test]
    public function a_whole_school_scheduler_is_unchanged(): void
    {
        $user = $this->principal([null]);

        $this->assertSame($this->ids('a', 'b'), $this->apiEntryIds($user));
        $this->api($user)->getJson($this->url('/search/subject-offerings'))->assertOk()->assertJsonCount(2, 'data');
        $this->api($user)->getJson($this->url('/search/periods'))->assertOk()->assertJsonCount(2, 'data');
        $this->signIn($user);
        $this->get('/app/timetable-schedule')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('entries.data', 2)->has('filterOptions.sections', 2)->has('filterOptions.teachers', 2)->where('canManage', true));
    }

    #[Test]
    public function a_campus_a_scheduler_sees_and_works_only_campus_a(): void
    {
        $user = $this->principal([$this->a->id]);

        // Lists: Campus A only, in SQL, web and API; filter choices too.
        $this->assertSame($this->ids('a'), $this->apiEntryIds($user));
        $this->signIn($user);
        $this->get('/app/timetable-schedule?status=all')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('entries.data', 1)->where('entries.data.0.sectionCode', 'SECA')->where('canManage', true)
            ->where('filterOptions.sections.0.id', $this->at['a']['section']->id)->has('filterOptions.sections', 1)
            ->where('filterOptions.teachers', [['id' => $this->teacherA->id, 'label' => "Asha North ({$this->teacherA->employee_number})"]]));

        // A request filter only narrows: naming Campus B's Section or teacher finds nothing.
        $this->assertSame([], $this->apiEntryIds($user, ['section_id' => $this->at['b']['section']->id]));
        $this->assertSame([], $this->apiEntryIds($user, ['teacher_id' => $this->teacherB->id]));
        $this->get('/app/timetable-schedule?section_id='.$this->at['b']['section']->id)->assertInertia(fn (AssertableInertia $p) => $p->has('entries.data', 0));

        // Direct access: Campus A yes, Campus B NOT FOUND (never 403), web and API, and nothing changes.
        $b = $this->at['b']['entry']->id;
        $this->api($user)->getJson($this->url('/'.$this->at['a']['entry']->id))->assertOk();
        $this->api($user)->getJson($this->url("/{$b}"))->assertNotFound();
        $this->api($user)->patchJson($this->url("/{$b}"), $this->payload('a', $this->teacherA, $this->p2, 3))->assertNotFound();
        $this->api($user)->postJson($this->url("/{$b}/deactivate"))->assertNotFound();
        $this->api($user)->postJson($this->url("/{$b}/activate"))->assertNotFound();
        $this->signIn($user);
        $this->get("/app/timetable-schedule/{$b}/edit")->assertNotFound();
        $this->post("/app/timetable-schedule/{$b}/deactivate")->assertNotFound();
        $this->patch("/app/timetable-schedule/{$b}", ['teacher_id' => $this->teacherA->id, 'period_id' => $this->p2->id, 'day_of_week' => 3])->assertNotFound();
        $this->assertSame('active', $this->entryStatus('b'));

        // Pickers: Campus A Offerings, Sections and Rooms only; teachers and the bell schedule are the declared shared lookups.
        $this->assertSame([$this->at['a']['offering']->id], $this->api($user)->getJson($this->url('/search/subject-offerings'))->assertOk()->json('data.*.id'));
        $this->assertSame([$this->at['a']['section']->id], $this->api($user)->getJson($this->url('/search/sections'))->assertOk()->json('data.*.id'));
        $this->assertSame([$this->at['a']['room']->id], $this->api($user)->getJson($this->url('/search/rooms'))->assertOk()->json('data.*.id'));
        $this->getJson('/app/timetable-schedule/search/rooms?q=ROOM')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ROOMA');
        foreach ($this->getJson('/app/timetable-schedule/search/teachers?q=a')->assertOk()->json('data') as $row) {
            $this->assertSame(['id', 'fullName'], array_keys($row));
        }
        $this->getJson('/app/timetable-schedule/search/schedule-periods?q=P')->assertOk()->assertJsonCount(2, 'data');
        $this->api($user)->getJson($this->url('/search/schedule-periods'))->assertOk()->assertJsonCount(2, 'data');
        // The Period catalogue stays whole-School configuration.
        $this->getJson('/app/timetable-schedule/search/periods?q=P')->assertForbidden();
        $this->api($user)->getJson($this->url('/search/periods'))->assertForbidden();
        $this->get('/app/timetable-periods')->assertForbidden();

        // Create: Campus B's Offering, or Campus B's Room for a Campus A class, is NOT FOUND and nothing is written.
        $before = $this->entryCount();
        $this->api($user)->postJson($this->url(), $this->payload('b', $this->teacherB, $this->p2, 2))->assertNotFound();
        $this->api($user)->postJson($this->url(), $this->payload('a', $this->teacherA, $this->p2, 2, room: $this->at['b']['room']))->assertNotFound();
        $this->post('/app/timetable-schedule', $this->payload('b', $this->teacherB, $this->p2, 2))->assertNotFound();
        $this->assertSame($before, $this->entryCount());

        // Moving a Campus A class onto Campus B's Offering is refused and the class is unchanged.
        $this->api($user)->patchJson($this->url('/'.$this->at['a']['entry']->id), $this->payload('b', $this->teacherA, $this->p2, 3))->assertNotFound();
        $this->assertSame($this->a->id, app(TenantContext::class)->withSchool($this->school, fn () => TimetableEntry::query()->findOrFail($this->at['a']['entry']->id)->campus_id));

        // Allowed operations in Campus A.
        $this->api($user)->postJson($this->url(), $this->payload('a', $this->teacherA, $this->p2, 2, room: $this->at['a']['room']))->assertCreated();
        $this->post('/app/timetable-schedule/'.$this->at['a']['entry']->id.'/deactivate')->assertRedirect();
        $this->assertSame('inactive', $this->entryStatus('a'));
        $this->post('/app/timetable-schedule/'.$this->at['a']['entry']->id.'/activate')->assertRedirect();
        $this->assertSame('active', $this->entryStatus('a'));
    }

    #[Test]
    public function two_campus_grants_give_the_union_and_an_inactive_campus_gives_nothing(): void
    {
        $this->assertSame($this->ids('a', 'b'), $this->apiEntryIds($this->principal([$this->a->id, $this->b->id])));

        $user = $this->principal([$this->a->id]);
        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url())->assertForbidden();
        $this->signIn($user);
        $this->get('/app/timetable-schedule')->assertForbidden();

        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'active'])->save());
        $this->assertSame($this->ids('a'), $this->apiEntryIds($user));
    }

    #[Test]
    public function no_grant_or_a_disabled_module_means_no_authority(): void
    {
        $none = $this->createUser();
        $this->createMembership($none, $this->school);
        $this->api($none)->getJson($this->url())->assertForbidden();

        $user = $this->principal([$this->a->id]);
        SchoolFactory::setOptionalModules($this->school, false, ['timetable']);
        $this->api($user)->getJson($this->url())->assertForbidden();
        $this->api($user)->getJson($this->url('/search/schedule-periods'))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['timetable']);
        $this->assertSame($this->ids('a'), $this->apiEntryIds($user));
    }

    #[Test]
    public function a_campus_scoped_slot_collision_never_says_whose_or_where(): void
    {
        $user = $this->principal([$this->a->id]);
        // A second Campus A Section, free at P1 on Monday; each teacher is busy then (A here, B on South Campus).
        $free = $this->createSection($this->year, $this->a, $this->grade, ['status' => 'active', 'code' => 'SECA2']);

        $responses = [];
        foreach (['here' => $this->teacherA, 'elsewhere' => $this->teacherB] as $key => $teacher) {
            $responses[$key] = $this->api($user)->postJson($this->url(), $this->payload('a', $teacher, $this->p1, 1, $free))
                ->assertStatus(409)->assertJsonPath('error.code', 'TIMETABLE_SLOT_UNAVAILABLE');
        }
        $error = fn (string $key): array => array_diff_key($responses[$key]->json('error'), ['requestId' => true]);
        $this->assertSame($error('here'), $error('elsewhere'));
        $body = (string) $responses['elsewhere']->getContent();
        foreach (['South', $this->b->id, $this->at['b']['entry']->id, 'Bilal', 'teacher already'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $body);
        }

        // Web: the same generic refusal banner.
        $this->signIn($user);
        $this->post('/app/timetable-schedule', $this->payload('a', $this->teacherB, $this->p1, 1, $free))
            ->assertSessionHasErrors(['refusal' => 'This class cannot be scheduled for this day and Period. Choose another teacher, Room, day or Period.']);

        // A whole-School scheduler keeps the specific message; the School-wide uniqueness is unchanged.
        $this->api($this->principal([null]))->postJson($this->url(), $this->payload('a', $this->teacherB, $this->p1, 1, $free))
            ->assertStatus(409)->assertJsonPath('error.code', 'TIMETABLE_TEACHER_ALREADY_SCHEDULED');
    }

    #[Test]
    public function the_service_refuses_an_out_of_scope_campus_itself(): void
    {
        $user = $this->principal([$this->a->id]);
        $service = app(TimetableScheduleService::class);
        $b = $this->at['b'];

        foreach ([
            fn () => $service->deactivate($b['entry'], $user),
            fn () => $service->activate($b['entry'], $user),
            fn () => $service->create($this->school, $b['offering'], $b['section'], $this->teacherB, null, $this->p2, 4, $user),
            fn () => $service->update($this->at['a']['entry'], $b['offering'], $b['section'], $this->teacherA, null, $this->p2, 4, $user),
        ] as $write) {
            try {
                $write();
                $this->fail('An out-of-scope Campus was accepted.');
            } catch (ModelNotFoundException) {
                // NOT FOUND, exactly like another School's record.
            }
        }
        $this->assertSame(['active', 'active'], [$this->entryStatus('a'), $this->entryStatus('b')]);
    }

    #[Test]
    public function an_entry_moved_to_another_campus_after_it_was_read_is_never_written(): void
    {
        // Security review LOW-1: the write re-checks the LOCKED row's Campus.
        $user = $this->principal([$this->a->id]);
        $stale = $this->at['a']['entry'];
        $b = $this->at['b'];
        $whole = $this->principal([null]);
        $service = app(TimetableScheduleService::class);
        $free = $this->createSection($this->year, $this->b, $this->grade, ['status' => 'active', 'code' => 'SECB2']);
        $service->update(app(TenantContext::class)->withSchool($this->school, fn () => TimetableEntry::query()->findOrFail($stale->id)), $b['offering'], $free, $this->teacherA, null, $this->p2, 3, $whole);

        try {
            $service->deactivate($stale, $user);
            $this->fail('A stale Campus A copy of a Campus B entry was written.');
        } catch (ModelNotFoundException) {
        }
        $this->assertSame('active', $this->entryStatus('a'));
    }

    #[Test]
    public function the_campus_grant_never_satisfies_whole_school_checks_and_navigation_follows(): void
    {
        $user = $this->principal([$this->a->id]);

        $this->assertFalse(Gate::forUser($user)->allows('capability', ['timetable.schedule.view', $this->school]));
        $this->assertTrue(Gate::forUser($user)->allows('campus-capability', ['timetable.schedule.view', $this->school]));

        $keys = collect(app(NavigationBuilder::class)->visibleEntries($user, $this->school, false))->pluck('key')->all();
        // The principal template's adopted areas appear (ORG.7D-1); whole-School ones do not.
        foreach (['timetable-schedule', 'attendance', 'curriculum-delivery', 'learning-content', 'assignments'] as $shown) {
            $this->assertContains($shown, $keys, "{$shown} is Campus-adopted and held in Campus A");
        }
        foreach (['timetable-periods', 'data-health', 'examinations', 'syllabus', 'teaching-assignments'] as $hidden) {
            $this->assertNotContains($hidden, $keys, "{$hidden} must not appear from a Campus grant alone");
        }

        // Data Health is a whole-School report: refused, not merely hidden.
        $this->signIn($user);
        $this->get('/app/data-health')->assertForbidden();
        $this->get('/app/timetable-schedule')->assertOk();
    }

    #[Test]
    public function the_campus_cost_is_constant_per_request_never_per_row(): void
    {
        $count = function (User $user): int {
            $this->signIn($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/app/timetable-schedule')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $whole = $count($this->principal([null]));
        $campus = $count($this->principal([$this->a->id]));

        // Five more Campus A classes: the Campus-scoped page costs exactly the same.
        foreach ([2, 3, 4, 5, 6] as $day) {
            $this->createTimetableEntry($this->at['a']['offering'], $this->at['a']['section'], $this->teacherA, $this->p1, ['day_of_week' => $day, 'status' => 'active']);
        }
        $this->assertSame($campus, $count($this->principal([$this->a->id])), 'a Campus-scoped list must never cost a query per row');

        // The constant part: one Campus-status read (three statements with the
        // RLS context) per scope resolution -- entry, canManage, navigation --
        // re-read on every call by design (ADR 0076 §34.3).
        $this->assertLessThanOrEqual($whole + 12, $campus, "whole-School {$whole} vs Campus {$campus} queries");
        if (getenv('REPORT_QUERY_COUNTS')) {
            fwrite(STDERR, "\ntimetable list queries: whole-School {$whole}, Campus {$campus}\n");
        }
    }
}
