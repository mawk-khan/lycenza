<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;

/**
 * Phase 0H (Timetable foundation), mandatory per root CLAUDE.md rule
 * 28. Also proves: every composite-FK cross-School rejection
 * (SubjectOffering, Section, teacher/Employee, Room, Period), the
 * structural AcademicYear/Campus/GradeLevel context-mismatch rejection
 * between a SubjectOffering and a Section (CLAUDE.md rule 70 -- the
 * actual proof that the two-composite-FK design works, not just an
 * application-level check), and historical-integrity deletion
 * protection for every referenced parent. Mirrors
 * InventoryLocationsRlsIsolationTest.php's exact pattern.
 */
class TimetableEntriesRlsIsolationTest extends TestCase
{
    use CreatesTimetableFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(School $school): array
    {
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active']);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $room = $this->createRoom($campus, ['status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);

        return compact('campus', 'year', 'grade', 'subject', 'offering', 'section', 'teacher', 'room', 'period');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entryRow(School $school, array $ctx, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'academic_year_id' => $ctx['offering']->academic_year_id,
            'campus_id' => $ctx['offering']->campus_id,
            'grade_level_id' => $ctx['offering']->grade_level_id,
            'subject_offering_id' => $ctx['offering']->id,
            'section_id' => $ctx['section']->id,
            'teacher_id' => $ctx['teacher']->id,
            'room_id' => $ctx['room']->id,
            'period_id' => $ctx['period']->id,
            'day_of_week' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['timetable_entries', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);
        $this->setSchool($school->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($this->entryRow($school, $ctx));

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from timetable_entries')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_entry(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $ctxB = $this->buildContext($schoolB);
        $this->setSchool($schoolB->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($row = $this->entryRow($schoolB, $ctxB));

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from timetable_entries where id = ?', [$row['id']]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_entry(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $ctxB = $this->buildContext($schoolB);
        $this->setSchool($schoolB->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($row = $this->entryRow($schoolB, $ctxB));

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update timetable_entries set status = ? where id = ?',
            ['inactive', $row['id']],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function an_entry_cannot_reference_a_subject_offering_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $ctxA = $this->buildContext($schoolA);
        $ctxB = $this->buildContext($schoolB);

        $this->setSchool($schoolA->id);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('timetable_entries')->insert($this->entryRow($schoolA, $ctxA, [
                'subject_offering_id' => $ctxB['offering']->id,
            ]));
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_subject_offering_fk must reject a cross-School SubjectOffering reference.');
    }

    #[Test]
    public function an_entry_cannot_reference_a_section_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $ctxA = $this->buildContext($schoolA);
        $ctxB = $this->buildContext($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('timetable_entries')->insert($this->entryRow($schoolA, $ctxA, [
                'section_id' => $ctxB['section']->id,
            ]));
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_section_fk must reject a cross-School Section reference.');
    }

    #[Test]
    public function an_entry_cannot_reference_a_teacher_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $ctxA = $this->buildContext($schoolA);
        $ctxB = $this->buildContext($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('timetable_entries')->insert($this->entryRow($schoolA, $ctxA, [
                'teacher_id' => $ctxB['teacher']->id,
            ]));
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_teacher_fk must reject a cross-School Employee reference.');
    }

    #[Test]
    public function an_entry_cannot_reference_a_room_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $ctxA = $this->buildContext($schoolA);
        $ctxB = $this->buildContext($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('timetable_entries')->insert($this->entryRow($schoolA, $ctxA, [
                'room_id' => $ctxB['room']->id,
            ]));
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_room_fk must reject a cross-School Room reference.');
    }

    #[Test]
    public function an_entry_cannot_reference_a_period_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $ctxA = $this->buildContext($schoolA);
        $ctxB = $this->buildContext($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('timetable_entries')->insert($this->entryRow($schoolA, $ctxA, [
                'period_id' => $ctxB['period']->id,
            ]));
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_period_fk must reject a cross-School Period reference.');
    }

    #[Test]
    public function an_entry_with_a_null_room_is_accepted(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);

        $this->setSchool($school->id);

        $inserted = DB::connection('pgsql')->table('timetable_entries')->insert($this->entryRow($school, $ctx, ['room_id' => null]));
        $this->assertTrue($inserted);
    }

    /**
     * The structural proof: a Section that does NOT actually belong to
     * the AcademicYear/Campus/GradeLevel context the entry declares
     * (matching its SubjectOffering) is rejected by the database at
     * INSERT time -- not merely by application-level validation
     * (CLAUDE.md rule 70).
     */
    #[Test]
    public function an_entry_is_rejected_when_the_section_context_does_not_match_the_subject_offerings_context(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);
        $otherGrade = $this->createGradeLevel($school, ['code' => 'OTHER-GRADE']);
        $mismatchedSection = $this->createSection($ctx['year'], $ctx['campus'], $otherGrade, ['status' => 'active', 'code' => 'MISMATCH']);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('timetable_entries')->insert($this->entryRow($school, $ctx, [
                // grade_level_id here matches the SubjectOffering (ctx['offering']),
                // but $mismatchedSection actually belongs to $otherGrade --
                // no (section_id, school_id, academic_year_id, campus_id,
                // grade_level_id) row exists for this exact combination.
                'section_id' => $mismatchedSection->id,
            ]));
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_section_fk must reject a Section whose real context does not match the declared context.');
    }

    #[Test]
    public function a_referenced_subject_offering_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);
        $this->setSchool($school->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($this->entryRow($school, $ctx));

        $rejected = false;
        try {
            DB::connection('pgsql')->table('subject_offerings')->where('id', $ctx['offering']->id)->delete();
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_subject_offering_fk must RESTRICT deletion of a referenced SubjectOffering.');
    }

    #[Test]
    public function a_referenced_section_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);
        $this->setSchool($school->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($this->entryRow($school, $ctx));

        $rejected = false;
        try {
            DB::connection('pgsql')->table('sections')->where('id', $ctx['section']->id)->delete();
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_section_fk must RESTRICT deletion of a referenced Section.');
    }

    #[Test]
    public function a_referenced_teacher_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);
        $this->setSchool($school->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($this->entryRow($school, $ctx));

        $rejected = false;
        try {
            DB::connection('pgsql')->table('employees')->where('id', $ctx['teacher']->id)->delete();
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_teacher_fk must RESTRICT deletion of a referenced Employee.');
    }

    #[Test]
    public function a_referenced_room_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);
        $this->setSchool($school->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($this->entryRow($school, $ctx));

        $rejected = false;
        try {
            DB::connection('pgsql')->table('rooms')->where('id', $ctx['room']->id)->delete();
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_room_fk must RESTRICT deletion of a referenced Room.');
    }

    #[Test]
    public function a_referenced_period_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $ctx = $this->buildContext($school);
        $this->setSchool($school->id);
        DB::connection('pgsql')->table('timetable_entries')->insert($this->entryRow($school, $ctx));

        $rejected = false;
        try {
            DB::connection('pgsql')->table('timetable_periods')->where('id', $ctx['period']->id)->delete();
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'timetable_entries_period_fk must RESTRICT deletion of a referenced Period.');
    }
}
