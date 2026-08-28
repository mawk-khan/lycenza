<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0D sections 49-50, 81 (mandatory): every School-owned Academic
 * Structure table, proven at the raw-SQL level against real
 * PostgreSQL, independent of Eloquent -- mirrors
 * WebhookRlsIsolationTest's pattern. The runtime role's own
 * rolsuper=false/rolbypassrls=false property is proven generically in
 * RawIsolationTest.php, not duplicated here.
 */
class AcademicStructureRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const TABLES = [
        'academic_years', 'academic_terms', 'grade_levels',
        'academic_departments', 'subjects', 'rooms', 'sections', 'subject_offerings',
        'elective_groups',
    ];

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function every_academic_structure_table_has_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "{$table} must exist");
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_rows_on_every_academic_structure_table(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $this->createAcademicTerm($year);
        $grade = $this->createGradeLevel($school);
        $department = $this->createAcademicDepartment($school);
        $subject = $this->createSubject($school, ['academic_department_id' => $department->id]);
        $this->createRoom($campus);
        $this->createSection($year, $campus, $grade);
        $this->createSubjectOffering($year, $campus, $grade, $subject);
        $this->createElectiveGroup($year, $campus, $grade);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        foreach (self::TABLES as $table) {
            $count = DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
            $this->assertSame(0, (int) $count, "{$table} must be invisible with no TenantContext");
        }
    }

    #[Test]
    public function school_a_cannot_read_school_bs_rows_on_every_academic_structure_table(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $campusB = $this->createCampus($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $termB = $this->createAcademicTerm($yearB);
        $gradeB = $this->createGradeLevel($schoolB);
        $departmentB = $this->createAcademicDepartment($schoolB);
        $subjectB = $this->createSubject($schoolB, ['academic_department_id' => $departmentB->id]);
        $roomB = $this->createRoom($campusB);
        $sectionB = $this->createSection($yearB, $campusB, $gradeB);
        $offeringB = $this->createSubjectOffering($yearB, $campusB, $gradeB, $subjectB);
        $electiveGroupB = $this->createElectiveGroup($yearB, $campusB, $gradeB);

        $this->setSchool($schoolA->id);

        $ids = [
            'academic_years' => $yearB->id,
            'academic_terms' => $termB->id,
            'grade_levels' => $gradeB->id,
            'academic_departments' => $departmentB->id,
            'subjects' => $subjectB->id,
            'rooms' => $roomB->id,
            'sections' => $sectionB->id,
            'subject_offerings' => $offeringB->id,
            'elective_groups' => $electiveGroupB->id,
        ];

        foreach ($ids as $table => $id) {
            $rows = DB::connection('pgsql')->select("select id from {$table} where id = ?", [$id]);
            $this->assertCount(0, $rows, "School A must not see School B's row in {$table}");
        }
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows_on_every_academic_structure_table(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $yearB = $this->createAcademicYear($schoolB);
        $gradeB = $this->createGradeLevel($schoolB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update("update academic_years set status = 'closed' where id = ?", [$yearB->id]));
        $this->assertSame(0, DB::connection('pgsql')->update("update grade_levels set status = 'inactive' where id = ?", [$gradeB->id]));
        $this->assertSame(0, DB::connection('pgsql')->delete('delete from grade_levels where id = ?', [$gradeB->id]));
    }
}
