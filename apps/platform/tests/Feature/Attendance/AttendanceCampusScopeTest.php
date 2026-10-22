<?php

namespace Tests\Feature\Attendance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Attendance\Infrastructure\AttendanceRecord;
use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Models\Campus;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Testing\LocalCatalogueFixtures;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * ORG.7D-1 D1-C (ADR 0076 §37): administrative Attendance (`attendance.view`
 * / `.manage`) is Campus-adopted. A Campus-scoped administrator reads,
 * submits and corrects only registers of its Campuses -- the Campus a
 * register was WRITTEN on, never a current one; another Campus's register,
 * record or class is NOT FOUND; the roster stays the Section's own; teacher
 * Attendance (`attendance.teacher`) is not adopted and stays
 * ownership-scoped.
 */
class AttendanceCampusScopeTest extends TestCase
{
    use CreatesAttendanceFixtures;

    private const NEXT_MONDAY = '2026-09-07';

    private School $school;

    private Campus $a;

    private Campus $b;

    private AcademicYear $year;

    private TimetablePeriod $period;

    /** @var array<string, array{campus: Campus, offering: SubjectOffering, section: Section, teacher: Employee, entry: TimetableEntry, enrollment: StudentEnrollment, session: AttendanceSession, record: AttendanceRecord}> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->b = $this->createCampus($this->school, ['name' => 'South Campus']);
        $this->year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $grade = $this->createGradeLevel($this->school);
        $this->period = $this->createTimetablePeriod($this->school, ['start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $admin = $this->fullAttendanceActor($this->school);

        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $offering = $this->createSubjectOffering($this->year, $campus, $grade, $this->createSubject($this->school), ['is_required' => true, 'status' => 'active']);
            $section = $this->createSection($this->year, $campus, $grade, ['status' => 'active', 'code' => 'S'.strtoupper($key)]);
            $teacher = $this->createEmployee($this->school, ['record_status' => 'active']);
            $entry = $this->createTimetableEntry($offering, $section, $teacher, $this->period, ['day_of_week' => 1, 'status' => 'active']);
            $enrollment = $this->enrollStudent($section, '1', '2026-06-01');
            $session = $this->inSchool($this->school, fn () => app(AttendanceSubmissionService::class)->guarded(fn () => DB::transaction(fn () => app(AttendanceSubmissionService::class)
                ->submit($this->school, $entry->id, self::MONDAY, $this->registerPayload([$enrollment->id => 'absent']), $admin))));
            $record = $this->inSchool($this->school, fn () => AttendanceRecord::query()->where('attendance_session_id', $session->id)->firstOrFail());
            $this->at[$key] = compact('campus', 'offering', 'section', 'teacher', 'entry', 'enrollment', 'session', 'record');
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
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path): string
    {
        return "/api/v1/schools/{$this->school->id}{$path}";
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    /** @return list<string> */
    private function apiSessionIds(User $user): array
    {
        return collect($this->api($user)->getJson($this->url('/attendance-sessions'))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    /** @return list<string> */
    private function sessions(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->at[$k]['session']->id)->sort()->values()->all();
    }

    private function recordStatus(string $key): string
    {
        return $this->inSchool($this->school, fn () => AttendanceRecord::query()->findOrFail($this->at[$key]['record']->id)->status);
    }

    private function sessionCount(): int
    {
        return $this->inSchool($this->school, fn () => AttendanceSession::query()->count());
    }

    #[Test]
    public function a_whole_school_administrator_is_unchanged(): void
    {
        $user = $this->principal([null]);

        $this->assertSame($this->sessions('a', 'b'), $this->apiSessionIds($user));
        $this->api($user)->getJson($this->url('/attendance-sessions/scheduled-classes?attendance_date='.self::NEXT_MONDAY))->assertOk()->assertJsonCount(2, 'data');
        $this->signIn($user);
        $this->get('/app/attendance')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('sessions.data', 2)->where('canManage', true));
        $this->get('/app/attendance/exceptions?date='.self::MONDAY)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('records.data', 2)->has('sectionOptions', 2));
    }

    #[Test]
    public function a_campus_a_administrator_reads_submits_and_corrects_only_campus_a(): void
    {
        $user = $this->principal([$this->a->id]);
        $b = $this->at['b'];

        // Reads: Campus A registers only, web and API, lists and the exceptions view.
        $this->assertSame($this->sessions('a'), $this->apiSessionIds($user));
        $this->api($user)->getJson($this->url("/attendance-sessions/{$b['session']->id}"))->assertNotFound();
        $this->signIn($user);
        $this->get('/app/attendance')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('sessions.data', 1)->where('canManage', true));
        $this->get("/app/attendance/{$b['session']->id}")->assertNotFound();
        $this->get('/app/attendance/'.$this->at['a']['session']->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canManage', true));
        $this->get('/app/attendance/exceptions?date='.self::MONDAY)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('records.data', 1)->where('records.data.0.id', $this->at['a']['record']->id)
            ->where('sectionOptions', fn ($options) => collect($options)->pluck('id')->all() === [$this->at['a']['section']->id]));
        // A request Section filter only narrows.
        $this->get('/app/attendance/exceptions?date='.self::MONDAY.'&section_id='.$b['section']->id)->assertInertia(fn (AssertableInertia $p) => $p->has('records.data', 0));

        // Class selection and roster: Campus A classes only; Campus B's class is NOT FOUND.
        $scheduled = $this->api($user)->getJson($this->url('/attendance-sessions/scheduled-classes?attendance_date='.self::NEXT_MONDAY))->assertOk()->json('data.*.timetableEntryId');
        $this->assertSame([$this->at['a']['entry']->id], $scheduled);
        $this->api($user)->getJson($this->url("/attendance-sessions/roster-preview?timetable_entry_id={$b['entry']->id}&attendance_date=".self::NEXT_MONDAY))->assertNotFound();
        $this->signIn($user);
        $this->get('/app/attendance/take?attendance_date='.self::NEXT_MONDAY.'&timetable_entry_id='.$b['entry']->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('classes', 1)->where('classes.0.timetableEntryId', $this->at['a']['entry']->id)->where('roster', []));
        $this->getJson("/app/attendance/roster?timetable_entry_id={$b['entry']->id}&attendance_date=".self::NEXT_MONDAY)->assertNotFound();

        // Writes: Campus B's register and record are NOT FOUND and nothing changes.
        $before = $this->sessionCount();
        $this->api($user)->withHeader('Idempotency-Key', 'campus-b-register')->postJson($this->url('/attendance-sessions'), [
            'timetable_entry_id' => $b['entry']->id, 'attendance_date' => self::NEXT_MONDAY,
            'records' => $this->registerPayload([$b['enrollment']->id => 'present']),
        ])->assertNotFound();
        $this->api($user)->postJson($this->url("/attendance-records/{$b['record']->id}/correct"), ['expected_status' => 'absent', 'new_status' => 'present', 'reason_code' => 'entered_in_error'])->assertNotFound();
        $this->signIn($user);
        $this->post("/app/attendance/records/{$b['record']->id}/correct", ['expected_status' => 'absent', 'new_status' => 'present', 'reason_code' => 'entered_in_error'])->assertNotFound();
        $this->post('/app/attendance', ['timetable_entry_id' => $b['entry']->id, 'attendance_date' => self::NEXT_MONDAY, 'records' => $this->registerPayload([$b['enrollment']->id => 'present'])])->assertNotFound();
        $this->assertSame([$before, 'absent'], [$this->sessionCount(), $this->recordStatus('b')]);

        // Allowed operations in Campus A.
        $this->api($user)->withHeader('Idempotency-Key', 'campus-a-register')->postJson($this->url('/attendance-sessions'), [
            'timetable_entry_id' => $this->at['a']['entry']->id, 'attendance_date' => self::NEXT_MONDAY,
            'records' => $this->registerPayload([$this->at['a']['enrollment']->id => 'present']),
        ])->assertCreated();
        $this->api($user)->postJson($this->url('/attendance-records/'.$this->at['a']['record']->id.'/correct'), ['expected_status' => 'absent', 'new_status' => 'late', 'reason_code' => 'entered_in_error'])->assertOk();
        $this->assertSame('late', $this->recordStatus('a'));
    }

    #[Test]
    public function history_keeps_the_campus_it_was_written_on(): void
    {
        // A whole-School scheduler later moves Campus A's Monday class onto Campus B's Offering and Section
        // (retiring Campus B's own Monday class first, so the Section's slot is free).
        $b = $this->at['b'];
        $scheduler = $this->principal([null]);
        $service = app(TimetableScheduleService::class);
        $service->deactivate($b['entry'], $scheduler);
        $service->update($this->at['a']['entry'], $b['offering'], $b['section'], $this->at['a']['teacher'], null, $this->period, 1, $scheduler);

        // The class itself is now South Campus's: its earlier register still counts as taken there.
        $south0 = $this->principal([$this->b->id]);
        $taken = collect($this->api($south0)->getJson($this->url('/attendance-sessions/scheduled-classes?attendance_date='.self::MONDAY))->assertOk()->json('data'))->keyBy('timetableEntryId');
        $this->assertTrue($taken[$this->at['a']['entry']->id]['alreadySubmitted'] ?? false, 'a moved class keeps its earlier register as taken');

        // The register written on Campus A stays Campus A's: its administrator keeps it, Campus B's never gains it.
        $north = $this->principal([$this->a->id]);
        $south = $this->principal([$this->b->id]);
        $this->assertSame($this->sessions('a'), $this->apiSessionIds($north));
        $this->assertSame($this->sessions('b'), $this->apiSessionIds($south));
        $this->api($south)->postJson($this->url('/attendance-records/'.$this->at['a']['record']->id.'/correct'), ['expected_status' => 'absent', 'new_status' => 'present', 'reason_code' => 'entered_in_error'])->assertNotFound();
        $this->api($north)->postJson($this->url('/attendance-records/'.$this->at['a']['record']->id.'/correct'), ['expected_status' => 'absent', 'new_status' => 'present', 'reason_code' => 'entered_in_error'])->assertOk();
    }

    #[Test]
    public function an_idempotent_replay_is_re_authorized_against_the_current_campus_scope(): void
    {
        // Security review MEDIUM-2 (rule 32): the `,campus` floor only says "held somewhere".
        $user = $this->principal([$this->a->id, $this->b->id]);
        $payload = ['timetable_entry_id' => $this->at['a']['entry']->id, 'attendance_date' => self::NEXT_MONDAY,
            'records' => $this->registerPayload([$this->at['a']['enrollment']->id => 'present'])];
        $submit = fn () => $this->api($user)->withHeader('Idempotency-Key', 'campus-replay-0001')->postJson($this->url('/attendance-sessions'), $payload);

        $submit()->assertCreated();
        $submit()->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        // Campus A becomes inactive: the actor still holds the key on Campus B, but the
        // replay is a key conflict -- the stored Campus A register is never served.
        $this->inSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $refused = $submit()->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertArrayNotHasKey('data', (array) $refused->json());
        $this->assertStringNotContainsString($this->at['a']['entry']->id, (string) $refused->getContent());

        // Authority restored: the replay is served again.
        $this->inSchool($this->school, fn () => $this->a->forceFill(['status' => 'active'])->save());
        $submit()->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    }

    #[Test]
    public function an_inactive_campus_or_a_disabled_module_gives_no_authority(): void
    {
        $user = $this->principal([$this->a->id]);
        $this->assertSame($this->sessions('a', 'b'), $this->apiSessionIds($this->principal([$this->a->id, $this->b->id])));

        SchoolFactory::setOptionalModules($this->school, false, ['attendance']);
        $this->api($user)->getJson($this->url('/attendance-sessions'))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['attendance']);
        $this->assertSame($this->sessions('a'), $this->apiSessionIds($user));

        $this->inSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url('/attendance-sessions'))->assertForbidden();
        $this->signIn($user);
        $this->get('/app/attendance')->assertForbidden();
    }

    #[Test]
    public function teacher_attendance_is_not_campus_adopted_and_ownership_stays_mandatory(): void
    {
        // The teacher template is not Campus-scopable: it carries no adopted key.
        $teacher = Role::query()->where('key', 'teacher')->where('scope', 'school')->firstOrFail();
        $this->assertFalse(StaffRoleCatalog::campusScopable($teacher));

        // Even a Campus grant carrying attendance.teacher confers nothing: no Campus authority, no My Attendance.
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['attendance.teacher', 'attendance.view']), $this->a->id);
        $this->assertTrue(app(CampusScopeResolver::class)->campusScope($user, 'attendance.teacher', $this->school)->isNone());
        $this->signIn($user);
        $this->get('/app/my-attendance')->assertForbidden();
        // Its adopted attendance.view does work in Campus A -- but only the administrative register list.
        $this->get('/app/attendance')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('sessions.data', 1)->where('canManage', false));
    }
}
