<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7A (mandatory): `enrollment_rollover_plans`/`_mappings`/
 * `_items` proven at the raw-SQL level against real PostgreSQL, under
 * the unprivileged `school_os_app` runtime role, independent of
 * Eloquent -- mirrors StudentEnrollmentIntegrityTest's pattern exactly.
 * Covers RLS isolation for all three tables and the composite-FK
 * cross-School/cross-Student integrity guarantees that make an
 * inconsistent rollover plan/item structurally impossible, not just
 * application-validated.
 */
class EnrollmentRolloverIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @return array{school: School, sourceYear: AcademicYear, targetYear: AcademicYear, section: Section, student: Student, enrollment: StudentEnrollment}
     */
    private function buildFullContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($sourceYear, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $enrollment = $this->createStudentEnrollment($student, $section);

        return compact('school', 'sourceYear', 'targetYear', 'section', 'student', 'enrollment');
    }

    private function insertPlan(
        string $schoolId,
        string $sourceYearId,
        string $targetYearId,
        ?string $connection = 'pgsql',
    ): string {
        $id = (string) Str::orderedUuid();
        DB::connection($connection)->insert(
            'insert into enrollment_rollover_plans '.
            '(id, school_id, source_academic_year_id, target_academic_year_id, status, configuration_version, created_at, updated_at) '.
            "values (?, ?, ?, ?, 'draft', 1, now(), now())",
            [$id, $schoolId, $sourceYearId, $targetYearId],
        );

        return $id;
    }

    private function insertItem(
        string $schoolId,
        string $planId,
        string $studentId,
        string $sourceEnrollmentId,
        ?string $connection = 'pgsql',
    ): string {
        $id = (string) Str::orderedUuid();
        DB::connection($connection)->insert(
            'insert into enrollment_rollover_items '.
            '(id, school_id, plan_id, student_id, source_enrollment_id, decision, created_at, updated_at) '.
            "values (?, ?, ?, ?, 'undecided', now(), now())",
            [$id, $schoolId, $planId, $studentId, $sourceEnrollmentId],
        );

        return $id;
    }

    // ==================================================================
    // RLS
    // ==================================================================

    #[Test]
    public function all_three_tables_have_rls_enabled_and_forced(): void
    {
        foreach (['enrollment_rollover_plans', 'enrollment_rollover_mappings', 'enrollment_rollover_items'] as $table) {
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
    public function no_school_context_sees_zero_rollover_plan_rows(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildFullContext();
        $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from enrollment_rollover_plans')->c;
        $this->assertSame(0, (int) $count, 'enrollment_rollover_plans must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_rollover_plan_row(): void
    {
        $schoolA = $this->createSchool();
        ['sourceYear' => $sourceYearB, 'targetYear' => $targetYearB] = $this->buildFullContext();
        $planB = $this->createEnrollmentRolloverPlan($sourceYearB, $targetYearB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from enrollment_rollover_plans where id = ?', [$planB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's rollover plan row");
    }

    #[Test]
    public function cross_school_writes_to_rollover_plans_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        ['sourceYear' => $sourceYearB, 'targetYear' => $targetYearB] = $this->buildFullContext();
        $planB = $this->createEnrollmentRolloverPlan($sourceYearB, $targetYearB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update enrollment_rollover_plans set status = 'cancelled' where id = ?",
            [$planB->id],
        ));
        $this->assertSame(0, DB::connection('pgsql')->delete(
            'delete from enrollment_rollover_plans where id = ?',
            [$planB->id],
        ));
    }

    #[Test]
    public function school_a_cannot_create_a_rollover_plan_assigned_to_school_b(): void
    {
        $schoolA = $this->createSchool();
        ['school' => $schoolB, 'sourceYear' => $sourceYearB, 'targetYear' => $targetYearB] = $this->buildFullContext();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT) so the
        // expected RLS WITH CHECK failure rolls back cleanly instead of
        // leaving the `pgsql` connection aborted for tearDown() --
        // StudentEnrollmentIntegrityTest's identical pattern.
        DB::connection('pgsql')->transaction(function () use ($schoolB, $sourceYearB, $targetYearB): void {
            $this->insertPlan($schoolB->id, $sourceYearB->id, $targetYearB->id);
        });
    }

    // ==================================================================
    // Cross-School composite-FK integrity: plan
    // ==================================================================

    #[Test]
    public function a_plan_cannot_reference_another_schools_source_academic_year(): void
    {
        $schoolA = $this->createSchool();
        $targetYearA = $this->createAcademicYear($schoolA);
        ['sourceYear' => $sourceYearB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        // Via pgsql_admin (bypasses RLS's own WITH CHECK, isolating that
        // the failure is purely the composite FK).
        $this->insertPlan($schoolA->id, $sourceYearB->id, $targetYearA->id, 'pgsql_admin');
    }

    #[Test]
    public function a_plan_cannot_reference_another_schools_target_academic_year(): void
    {
        $schoolA = $this->createSchool();
        $sourceYearA = $this->createAcademicYear($schoolA);
        ['targetYear' => $targetYearB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        $this->insertPlan($schoolA->id, $sourceYearA->id, $targetYearB->id, 'pgsql_admin');
    }

    #[Test]
    public function the_database_rejects_a_plan_whose_source_and_target_year_are_identical(): void
    {
        $schoolA = $this->createSchool();
        $yearA = $this->createAcademicYear($schoolA);

        $this->expectException(QueryException::class);

        $this->insertPlan($schoolA->id, $yearA->id, $yearA->id, 'pgsql_admin');
    }

    // ==================================================================
    // Cross-School / cross-Student composite-FK integrity: item
    // ==================================================================

    #[Test]
    public function an_item_cannot_reference_another_schools_student(): void
    {
        $schoolA = $this->createSchool();
        $sourceYearA = $this->createAcademicYear($schoolA, ['code' => 'SRC']);
        $targetYearA = $this->createAcademicYear($schoolA, ['code' => 'TGT']);
        $planA = $this->createEnrollmentRolloverPlan($sourceYearA, $targetYearA);
        ['student' => $studentB, 'enrollment' => $enrollmentB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        $this->insertItem($schoolA->id, $planA->id, $studentB->id, $enrollmentB->id, 'pgsql_admin');
    }

    #[Test]
    public function an_item_cannot_reference_another_schools_plan(): void
    {
        ['school' => $schoolA, 'student' => $studentA, 'enrollment' => $enrollmentA] = $this->buildFullContext();
        ['sourceYear' => $sourceYearB, 'targetYear' => $targetYearB] = $this->buildFullContext();
        $planB = $this->createEnrollmentRolloverPlan($sourceYearB, $targetYearB);

        $this->expectException(QueryException::class);

        $this->insertItem($schoolA->id, $planB->id, $studentA->id, $enrollmentA->id, 'pgsql_admin');
    }

    #[Test]
    public function the_database_rejects_a_source_enrollment_belonging_to_a_different_student_in_the_same_school(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'enrollment' => $enrollmentForStudentA] = $this->buildFullContext();
        $studentB = $this->createStudent($school, ['student_number' => 'S-0002']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $this->expectException(QueryException::class);

        // student_id = Student B, source_enrollment_id = Student A's
        // Enrollment -- the exact structural guarantee the double
        // composite FK on enrollment_rollover_items exists to prevent.
        $this->insertItem($school->id, $plan->id, $studentB->id, $enrollmentForStudentA->id, 'pgsql_admin');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_rollover_item_row(): void
    {
        $schoolA = $this->createSchool();
        ['sourceYear' => $sourceYearB, 'targetYear' => $targetYearB, 'student' => $studentB, 'enrollment' => $enrollmentB] = $this->buildFullContext();
        $planB = $this->createEnrollmentRolloverPlan($sourceYearB, $targetYearB);
        $itemB = $this->createEnrollmentRolloverItem($planB, $studentB, $enrollmentB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from enrollment_rollover_items where id = ?', [$itemB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's rollover item row");
    }
}
