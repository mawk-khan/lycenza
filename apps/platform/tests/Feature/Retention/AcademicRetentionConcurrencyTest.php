<?php

namespace Tests\Feature\Retention;

use App\Domain\Attendance\Application\Retention\AttendanceSessionRetentionService;
use App\Domain\Timetable\Application\Retention\TimetableEntryRetentionService;
use App\Models\School;
use App\Support\Retention\LmsResourceRetention;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CommitsRetentionFixtures;
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
    use CommitsRetentionFixtures, CreatesAttendanceFixtures, ForcesConcurrentOverlap;

    protected function tearDown(): void
    {
        // Committed fixtures (Schools, Users, holds) go in CommitsRetentionFixtures' hermetic cleanup.
        DB::purge('pgsql_race');

        parent::tearDown();
    }

    private function oldYear(): array
    {
        $school = $this->createSchool();
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
    public function a_document_committed_first_keeps_its_resource_as_recorded_within_the_period(): void
    {
        $w = $this->oldYear();
        $id = $this->learningContent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('attach-document', $w['school']->id, $id),
            $this->script('lms-prune', $w['school']->id, '2019-01-01'),
        );

        // E21-RH.7: the Document was recorded by the database just now, so the unit keeps the resource with it
        // (never an error): it goes only once that recording is past the period too. Before RH.7 a late
        // Document left with its resource; a document attached minutes ago is not years-old evidence.
        $this->assertSame('attached', $holder);
        $this->assertSame('deleted:0 errors:0', $contender);
        $this->assertSame(1, $this->admin('learning_content', $id));
        $this->assertSame(1, DB::connection('pgsql_admin')->table('documents')->where('learning_content_id', $id)->count());
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

        $this->assertSame('deleted:1 errors:0', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, DB::connection('pgsql_admin')->table('documents')->where('learning_content_id', $id)->count());
    }

    #[Test]
    public function two_workers_purging_the_same_resource_serialize_and_the_second_keeps_quietly(): void
    {
        // E21-RH.5: the second unit waits on the resource lock the first's function holds, then finds it gone:
        // nothing deleted twice, not counted as an error.
        $w = $this->oldYear();
        $id = $this->learningContent($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('lms-prune', $w['school']->id, '2019-01-01'),
            $this->script('lms-prune', $w['school']->id, '2019-01-01'),
        );

        $this->assertSame('deleted:1 errors:0', $holder);
        $this->assertSame('deleted:0 errors:0', $contender);
        $this->assertSame(0, $this->admin('learning_content', $id));
    }

    #[Test]
    public function a_hold_placed_concurrently_makes_the_waiting_lms_unit_keep_the_resource_and_its_documents(): void
    {
        $w = $this->oldYear();
        $id = $this->learningContent($w);
        $this->document($w, $id);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../Support/hrx-retention-op.php', 'place-school', $w['school']->id],
            $this->script('lms-prune', $w['school']->id, '2019-01-01'),
        );

        $this->assertSame('placed:created', $holder);
        $this->assertSame('deleted:0 errors:0', $contender);
        $this->assertSame(1, $this->admin('learning_content', $id));
        $this->assertSame(1, DB::connection('pgsql_admin')->table('documents')->where('learning_content_id', $id)->count(), 'no half-purged unit');
    }

    #[Test]
    public function an_lms_unit_past_the_hold_check_finishes_before_the_placement_lands(): void
    {
        $w = $this->oldYear();
        $id = $this->learningContent($w);
        $this->document($w, $id);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('lms-prune', $w['school']->id, '2019-01-01'),
            ['php', __DIR__.'/../../Support/hrx-retention-op.php', 'place-school', $w['school']->id],
        );

        $this->assertSame('deleted:1 errors:0', $holder);
        $this->assertSame('placed:created', $contender, 'the placement waited for the in-flight unit');
        $this->assertSame(0, $this->admin('learning_content', $id));
        $this->assertSame(0, DB::connection('pgsql_admin')->table('documents')->where('learning_content_id', $id)->count());
    }

    #[Test]
    public function another_schools_unit_runs_while_one_school_unit_is_in_flight_and_neither_sees_the_other(): void
    {
        $a = $this->oldYear();
        $b = $this->oldYear();
        $idA = $this->learningContent($a);
        $idB = $this->learningContent($b);
        $this->backdateEndRecording(); // E21-RH.7: years-old fixtures were recorded years ago too

        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $holder = new Process($this->script('lms-prune', $a['school']->id, '2019-01-01'), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        try {
            $holder->start();
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'the holder never acted: '.$holder->getOutput().$holder->getErrorOutput());
                usleep(2_000);
            }

            // School A's unit is deleted but uncommitted; School B's unit runs to completion meanwhile.
            $r = app(LmsResourceRetention::class)->prune('learning_content', $b['school'], '2019-01-01', 100, false, false);
            $this->assertSame([1, 0], [$r['deleted'], $r['errors']], 'School B never waits on School A');
            $this->assertSame(1, $this->admin('learning_content', $idA), 'A is uncommitted: still visible');
            $this->assertSame(0, $this->admin('learning_content', $idB));
        } finally {
            touch($dir.'/release');
            $holder->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        $this->assertSame('deleted:1 errors:0', trim($holder->getOutput()));
        $this->assertSame(0, $this->admin('learning_content', $idA));
    }

    private function document(array $w, string $id): void
    {
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('documents')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'learning_content_id' => $id, 'classification_tier' => 'internal',
            'storage_disk' => 'local', 'storage_path' => "schools/{$w['school']->id}/documents/learning_content/{$id}/race.pdf",
            'original_filename' => 'race.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
