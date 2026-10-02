<?php

namespace Tests\Feature\Retention;

use App\Domain\Attendance\Application\Retention\AttendanceSessionRetentionService;
use App\Domain\Timetable\Application\Retention\TimetableEntryRetentionService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * E21.3D (E21.2G A1): the year-bound expiries racing the writes that could
 * matter, in real separate transactions (COMMITTED fixtures).
 * - Empty register header vs a late attendance record, and a timetable
 *   entry vs a late register header: the batch expiry locks `FOR UPDATE
 *   SKIP LOCKED`, so a row whose child insert holds it is SKIPPED (never
 *   waited on, never deleted under it); an expiry committed first makes the
 *   late child fail on its foreign key. Proven with a second connection
 *   holding the child insert open.
 * - LMS resource vs a new Document (two OS processes): the unit locks the
 *   resource FOR UPDATE; a Document insert takes FOR KEY SHARE. A Document
 *   committed first is seen and removed with the resource (it inherits it);
 *   an expiry committed first makes the late Document fail.
 * A new audience can never be added to an existing resource (audience
 * insert guard: only in the resource's creating transaction), and a
 * curriculum delivery has no child, so no other race exists.
 */
class AcademicRetentionConcurrencyTest extends TestCase
{
    use CreatesAttendanceFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        DB::purge('pgsql_race');
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function oldYear(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'closed', 'starts_on' => '2010-04-01', 'ends_on' => '2011-03-31', 'code' => 'AY10']);
        $grade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'A']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $entry = $this->createTimetableEntry($offering, $section, $teacher, $period, ['day_of_week' => 1]);

        return compact('school', 'campus', 'year', 'grade', 'offering', 'section', 'teacher', 'period', 'entry');
    }

    private function header(array $w): array
    {
        return [
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'timetable_entry_id' => $w['entry']->id, 'attendance_date' => '2010-06-01',
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'section_id' => $w['section']->id,
            'subject_offering_id' => $w['offering']->id, 'teacher_id' => $w['teacher']->id, 'period_id' => $w['period']->id, 'period_start_time' => '09:00:00',
            'period_end_time' => '10:00:00', 'submitted_by_user_id' => $this->createUser()->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** A second, independent runtime connection, in the School's context, with an open transaction. */
    private function race(School $school): Connection
    {
        Config::set('database.connections.pgsql_race', config('database.connections.pgsql'));
        $race = DB::connection('pgsql_race');
        $race->beginTransaction();
        $race->select("select set_config('app.current_school_id', ?, true)", [$school->id]);

        return $race;
    }

    private function admin(string $table, string $id): int
    {
        return DB::connection('pgsql_admin')->table($table)->where('id', $id)->count();
    }

    #[Test]
    public function a_late_attendance_record_keeps_its_header_and_an_expired_header_refuses_it(): void
    {
        $w = $this->oldYear();
        $header = $this->header($w);
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('attendance_sessions')->insert($header));
        $enrollment = $this->createStudentEnrollment($this->createStudent($w['school']), $w['section'], ['status' => 'active', 'starts_on' => '2010-04-01', 'ends_on' => null]);
        $record = fn () => [
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'attendance_session_id' => $header['id'], 'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'section_id' => $w['section']->id,
            'status' => 'present', 'created_at' => now(), 'updated_at' => now(),
        ];
        $sessions = app(AttendanceSessionRetentionService::class);

        // The child insert is in flight: the header is skipped, never deleted under it.
        $race = $this->race($w['school']);
        $race->table('attendance_records')->insert($record());
        $this->assertSame(0, $sessions->prune($w['school'], '2020-01-01', 100, false, false)['deleted']);
        $race->commit();
        $this->assertSame(1, $this->admin('attendance_sessions', $header['id']));
        $this->assertSame(1, $sessions->prune($w['school'], '2020-01-01', 100, true, false)['dependency_blocked'], 'committed: the header now has a record');

        // Expiry committed first: a late record fails on its foreign key.
        DB::connection('pgsql_admin')->table('attendance_records')->where('attendance_session_id', $header['id'])->delete();
        $this->assertSame(1, $sessions->prune($w['school'], '2020-01-01', 100, false, false)['deleted']);
        $this->expectException(QueryException::class);
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('attendance_records')->insert($record()));
    }

    #[Test]
    public function a_late_register_header_keeps_its_timetable_entry(): void
    {
        $w = $this->oldYear();
        $timetable = app(TimetableEntryRetentionService::class);

        $race = $this->race($w['school']);
        $race->table('attendance_sessions')->insert($this->header($w));
        $this->assertSame(0, $timetable->prune($w['school'], '2020-01-01', 100, false, false)['deleted']);
        $race->commit();

        $this->assertSame(1, $this->admin('timetable_entries', $w['entry']->id));
        $this->assertSame(1, $timetable->prune($w['school'], '2020-01-01', 100, true, false)['dependency_blocked']);
    }

    private function learningContent(array $w): string
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('learning_content')->insert([
            'id' => $id, 'school_id' => $w['school']->id, 'subject_offering_id' => $w['offering']->id, 'title' => 'Material', 'sequence' => 1,
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/academic-retention-op.php', ...$args];
    }

    #[Test]
    public function a_document_committed_first_is_removed_with_its_resource(): void
    {
        $w = $this->oldYear();
        $id = $this->learningContent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('attach-document', $w['school']->id, $id),
            $this->script('lms-prune', $w['school']->id, '2019-01-01'),
        );

        $this->assertSame('attached', $holder);
        $this->assertSame('deleted:1', $contender);
        $this->assertSame(0, $this->admin('learning_content', $id));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('documents')->where('learning_content_id', $id)->count());
    }

    #[Test]
    public function an_expiry_committed_first_refuses_the_late_document(): void
    {
        $w = $this->oldYear();
        $id = $this->learningContent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('lms-prune', $w['school']->id, '2019-01-01'),
            $this->script('attach-document', $w['school']->id, $id),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, DB::connection('pgsql_admin')->table('documents')->where('learning_content_id', $id)->count());
    }
}
