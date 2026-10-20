<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * ORG.7D-0 R1/R2 (ADR 0076 §36; CLAUDE.md rules 18, 28, 70): Campus
 * relational integrity proven through raw PostgreSQL on the RUNTIME role --
 * no service in the way.
 *
 * - R1: a Timetable entry's Room is on the entry's Campus
 *   (`timetable_entries_room_campus_fk`).
 * - R2: an enrollment's School, Year, Campus and Grade are its Section's
 *   (`student_enrollments_section_context_fk`).
 *
 * The constraints prove consistency only: `students` has no Campus, and no
 * column says which enrollment is current.
 */
class CampusRelationalIntegrityTest extends TestCase
{
    use CreatesTimetableFixtures;

    private function setSchool(School $school): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);
    }

    /** Runs one raw statement on the runtime role in a savepoint; returns the database error, or null when accepted. */
    private function attempt(string $sql, array $bindings = []): ?string
    {
        try {
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->statement($sql, $bindings));
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return null;
    }

    #[Test]
    public function the_constraints_are_installed_as_designed(): void
    {
        $definition = fn (string $name) => DB::selectOne('select pg_get_constraintdef(oid) as d from pg_constraint where conname = ?', [$name])?->d;

        $this->assertSame('UNIQUE (id, school_id, campus_id)', $definition('rooms_campus_context_unique'));
        $this->assertSame('FOREIGN KEY (room_id, school_id, campus_id) REFERENCES rooms(id, school_id, campus_id) ON DELETE RESTRICT', $definition('timetable_entries_room_campus_fk'));
        $this->assertSame('FOREIGN KEY (section_id, school_id, academic_year_id, campus_id, grade_level_id) REFERENCES sections(id, school_id, academic_year_id, campus_id, grade_level_id) ON DELETE RESTRICT', $definition('student_enrollments_section_context_fk'));
        // The School-only keys they strengthen are kept.
        $this->assertNotNull($definition('timetable_entries_room_fk'));
        $this->assertNotNull($definition('student_enrollments_section_id_school_id_foreign'));
        // Student identity stays School-wide.
        $this->assertNull(DB::selectOne("select 1 as x from information_schema.columns where table_name = 'students' and column_name like '%campus%'"));
    }

    #[Test]
    public function a_timetable_entry_cannot_reference_a_room_of_another_campus(): void
    {
        $school = $this->createSchool();
        $a = $this->createCampus($school);
        $b = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $a, $grade, $this->createSubject($school), ['is_required' => true]);
        $section = $this->createSection($year, $a, $grade);
        $roomA = $this->createRoom($a);
        $roomB = $this->createRoom($b);
        $roomOtherSchool = $this->createRoom($this->createCampus($this->createSchool()));
        $entry = $this->createTimetableEntry($offering, $section, $this->createEmployee($school), $this->createTimetablePeriod($school), ['room_id' => $roomA->id, 'day_of_week' => 1]);
        $this->setSchool($school);

        $copy = fn (string $roomId, int $day) => $this->attempt(
            'insert into timetable_entries (id, school_id, academic_year_id, campus_id, grade_level_id, subject_offering_id, section_id, teacher_id, room_id, period_id, day_of_week, status, created_at, updated_at)
             select ?, school_id, academic_year_id, campus_id, grade_level_id, subject_offering_id, section_id, teacher_id, ?, period_id, ?, status, now(), now() from timetable_entries where id = ?',
            [(string) Str::uuid7(), $roomId, $day, $entry->id],
        );

        $this->assertNull($copy($roomA->id, 2), 'a same-Campus Room is accepted');
        $this->assertStringContainsString('timetable_entries_room_campus_fk', (string) $copy($roomB->id, 3));
        $this->assertStringContainsString('timetable_entries_room', (string) $copy($roomOtherSchool->id, 4), 'another School\'s Room stays refused');
        $this->assertStringContainsString('timetable_entries_room_campus_fk', (string) $this->attempt('update timetable_entries set room_id = ? where id = ?', [$roomB->id, $entry->id]));
        $this->assertNull($this->attempt('update timetable_entries set room_id = null where id = ?', [$entry->id]), 'a Room-less entry stays valid');

        // A referenced Room cannot be moved to another Campus underneath its entries.
        $this->assertNull($this->attempt('update timetable_entries set room_id = ? where id = ?', [$roomA->id, $entry->id]));
        $this->assertStringContainsString('timetable_entries_room_campus_fk', (string) $this->attempt('update rooms set campus_id = ? where id = ?', [$b->id, $roomA->id]));
    }

    #[Test]
    public function an_enrollment_cannot_disagree_with_its_section(): void
    {
        $school = $this->createSchool();
        $a = $this->createCampus($school);
        $b = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $a, $grade, ['code' => 'A']);
        $sectionB = $this->createSection($year, $b, $grade, ['code' => 'B']);
        $enrollment = $this->createStudentEnrollment($this->createStudent($school), $sectionA);
        $students = [$this->createStudent($school)->id, $this->createStudent($school)->id];
        $this->setSchool($school);

        $insert = function (string $sectionId, string $campusId) use (&$students, $school, $year, $grade): ?string {
            return $this->attempt(
                "insert into student_enrollments (id, school_id, student_id, academic_year_id, campus_id, grade_level_id, section_id, roll_number, status, starts_on, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, ?, ?, 'active', current_date, now(), now())",
                [(string) Str::uuid7(), $school->id, array_pop($students), $year->id, $campusId, $grade->id, $sectionId, (string) random_int(1000, 99999)],
            );
        };

        $this->assertNull($insert($sectionB->id, $b->id), 'an enrollment matching its Section is accepted');
        $this->assertStringContainsString('student_enrollments_section_context_fk', (string) $insert($sectionB->id, $a->id));
        // ORG.7F-3 (§47): placement context is frozen after INSERT, so a raw
        // edit is refused by the freeze trigger before the composite key.
        $this->assertStringContainsString('student_enrollments_freeze_placement', (string) $this->attempt('update student_enrollments set campus_id = ? where id = ?', [$b->id, $enrollment->id]), 'a raw Campus edit is refused');
        $this->assertStringContainsString('student_enrollments_freeze_placement', (string) $this->attempt('update student_enrollments set section_id = ? where id = ?', [$sectionB->id, $enrollment->id]), 'a raw Section edit to another Campus is refused');
        $this->assertStringContainsString('student_enrollments_section_context_fk', (string) $this->attempt('update sections set campus_id = ? where id = ?', [$b->id, $sectionA->id]), 'a Section cannot move Campus under its enrollments');

        $this->assertSame($a->id, DB::connection('pgsql')->table('student_enrollments')->where('id', $enrollment->id)->value('campus_id'), 'nothing was rewritten');
    }

    #[Test]
    public function rls_stays_forced_and_the_runtime_role_gains_nothing(): void
    {
        foreach (['timetable_entries', 'rooms', 'student_enrollments', 'sections'] as $table) {
            $this->assertTrue((bool) DB::selectOne('select relforcerowsecurity as f from pg_class where relname = ?', [$table])->f, "{$table} keeps FORCE ROW LEVEL SECURITY");
        }
        $role = DB::selectOne("select rolsuper, rolbypassrls from pg_roles where rolname = 'school_os_app'");
        $this->assertFalse((bool) $role->rolsuper);
        $this->assertFalse((bool) $role->rolbypassrls);

        // Another School's rows stay invisible on the runtime role.
        $other = $this->createSchool();
        $year = $this->createAcademicYear($other);
        $campus = $this->createCampus($other);
        $this->createStudentEnrollment($this->createStudent($other), $this->createSection($year, $campus, $this->createGradeLevel($other)));
        $this->setSchool($this->createSchool());
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from student_enrollments where school_id = ?', [$other->id])->c);
    }
}
