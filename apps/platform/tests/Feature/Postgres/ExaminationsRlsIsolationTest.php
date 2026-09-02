<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Examinations\Concerns\CreatesExaminationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4A, mandatory per root CLAUDE.md rule 28. Every test here
 * writes RAW SQL, deliberately bypassing the service and all application
 * validation, to prove the DATABASE -- not the application -- enforces
 * tenant isolation, the tenant-pinned AcademicYear reference,
 * case-insensitive code uniqueness, the status vocabulary, date ordering
 * and historical delete protection.
 *
 * Mirrors SyllabusUnitsRlsIsolationTest/CurriculumDeliveriesRlsIsolationTest,
 * including the SAVEPOINT-based rejection helper: a constraint violation
 * aborts the current (sub)transaction, so without a savepoint to roll
 * back to, every later statement in this test's enclosing
 * DatabaseTransactions transaction would fail with SQLSTATE 25P02
 * instead of its own real error.
 *
 * THERE IS DELIBERATELY NO CONCURRENCY TEST IN THIS MODULE, and that is
 * a positive architectural statement rather than an omission:
 *
 *   - there is NO multi-row invariant of any kind -- overlapping
 *     examination windows are explicitly PERMITTED (a School may run
 *     "Grade 10 Board Prep" and "Grade 6 Unit Test" in the same weeks),
 *     unlike `academic_terms`, which forbids overlap only because terms
 *     partition a year by definition;
 *   - there is NO state machine and NO expected-status compare-and-swap
 *     -- `status` is an ordinary PATCH field, so there is no
 *     lost-update race to lose;
 *   - the ONLY race is two concurrent creates of the same code, and
 *     that is settled by a single PostgreSQL unique index
 *     (`examinations_year_code_ci_unique`), whose behaviour is asserted
 *     directly below rather than through a two-process harness.
 *
 * Consequently this module also introduces no TenantLock, no advisory
 * lock and no `lockForUpdate()` anywhere.
 */
class ExaminationsRlsIsolationTest extends TestCase
{
    use CreatesExaminationFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertExamination(School $school, AcademicYear $year, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('examinations')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'academic_year_id' => $year->id,
            'code' => 'MID1',
            'name' => 'Mid-Term Examination',
            'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(20)->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function assertRejectedBy(string $constraint, callable $op, string $message): void
    {
        try {
            DB::connection('pgsql')->transaction($op);
            $this->fail($message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage(), $message);
        }
    }

    // --- RLS ----------------------------------------------------------

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['examinations', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity, 'examinations must have RLS ENABLED');
        $this->assertTrue($row->relforcerowsecurity, 'examinations must have RLS FORCED');
    }

    #[Test]
    public function tenant_isolation_holds_at_the_raw_sql_layer(): void
    {
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);
        $this->insertExamination($w['school'], $w['year']);

        $this->assertSame(1, DB::connection('pgsql')->table('examinations')->count());

        // School B sees none of School A's rows.
        $other = $this->examinationWorld();
        $this->setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table('examinations')->count());

        // Cross-School UPDATE affects zero rows.
        $this->assertSame(0, DB::connection('pgsql')->table('examinations')
            ->where('school_id', $w['school']->id)->update(['name' => 'hijacked']));

        // Missing tenant context fails closed rather than exposing all.
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::connection('pgsql')->table('examinations')->count());
    }

    #[Test]
    public function a_raw_insert_for_another_school_is_rejected_by_the_policy(): void
    {
        $w = $this->examinationWorld();
        $other = $this->examinationWorld();

        // Active context is School B; try to write a School A row.
        $this->setSchool($other['school']->id);

        $this->assertRejectedBy(
            'row-level security',
            fn () => $this->insertExamination($w['school'], $w['year']),
            'A raw insert for a different School than the active context must be rejected by RLS.',
        );
    }

    // --- tenant-pinned parent -----------------------------------------

    #[Test]
    public function a_cross_school_academic_year_is_rejected_by_the_composite_fk(): void
    {
        $w = $this->examinationWorld();
        $other = $this->examinationWorld();
        $this->setSchool($w['school']->id);

        // School A's row claiming School B's AcademicYear.
        $this->assertRejectedBy(
            'examinations_academic_year_fk',
            fn () => $this->insertExamination($w['school'], $other['year']),
            'An Examination must not reference another School\'s AcademicYear.',
        );
    }

    #[Test]
    public function a_referenced_academic_year_cannot_be_hard_deleted(): void
    {
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);
        $this->insertExamination($w['school'], $w['year']);

        $this->assertRejectedBy(
            'examinations_academic_year_fk',
            fn () => DB::connection('pgsql')->table('academic_years')->where('id', $w['year']->id)->delete(),
            'An AcademicYear carrying examinations must not be hard-deletable.',
        );
    }

    // --- code uniqueness ----------------------------------------------

    #[Test]
    public function a_case_insensitive_duplicate_code_is_rejected_by_the_database(): void
    {
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);
        $this->insertExamination($w['school'], $w['year'], ['code' => 'MID1']);

        // Differs only in case -- the expression index must reject it,
        // with no application validation involved.
        $this->assertRejectedBy(
            'examinations_year_code_ci_unique',
            fn () => $this->insertExamination($w['school'], $w['year'], ['code' => 'mid1']),
            'A case-variant duplicate code within one AcademicYear must be rejected by the database.',
        );
    }

    #[Test]
    public function an_inactive_examination_still_reserves_its_code(): void
    {
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);
        $this->insertExamination($w['school'], $w['year'], ['code' => 'MID1', 'status' => 'inactive']);

        // The index is deliberately unconditional, which is exactly what
        // makes reinstatement conflict-free and removes any need for an
        // activate/deactivate lifecycle command.
        $this->assertRejectedBy(
            'examinations_year_code_ci_unique',
            fn () => $this->insertExamination($w['school'], $w['year'], ['code' => 'MID1']),
            'An inactive Examination must continue to reserve its code.',
        );
    }

    #[Test]
    public function the_same_code_in_a_different_academic_year_is_accepted(): void
    {
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);
        $this->insertExamination($w['school'], $w['year'], ['code' => 'MID1']);

        // "MID1" recurs every year as a distinct row -- that is intended.
        $nextYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT',
            'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);
        $this->setSchool($w['school']->id);

        $this->insertExamination($w['school'], $nextYear, [
            'code' => 'MID1',
            'starts_on' => $this->today()->addMonths(8)->toDateString(),
            'ends_on' => $this->today()->addMonths(9)->toDateString(),
        ]);

        $this->assertSame(2, DB::connection('pgsql')->table('examinations')->count());
    }

    // --- CHECK constraints --------------------------------------------

    #[Test]
    public function an_invalid_status_is_rejected_by_the_check_constraint(): void
    {
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examinations_status_check',
            fn () => $this->insertExamination($w['school'], $w['year'], ['status' => 'draft']),
            'An out-of-vocabulary status must be rejected by the CHECK constraint.',
        );
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'examinations_date_order_check',
            fn () => $this->insertExamination($w['school'], $w['year'], [
                'starts_on' => $this->today()->addDays(20)->toDateString(),
                'ends_on' => $this->today()->addDays(10)->toDateString(),
            ]),
            'An examination window cannot end before it starts.',
        );
    }

    #[Test]
    public function future_dated_and_overlapping_windows_are_accepted_at_the_database_layer(): void
    {
        // Both are deliberate departures from CurriculumDelivery and
        // must never be "fixed" by adding a constraint.
        $w = $this->examinationWorld();
        $this->setSchool($w['school']->id);

        $this->insertExamination($w['school'], $w['year'], [
            'code' => 'A1',
            'starts_on' => $this->today()->addDays(30)->toDateString(),
            'ends_on' => $this->today()->addDays(40)->toDateString(),
        ]);
        $this->insertExamination($w['school'], $w['year'], [
            'code' => 'B1',
            'starts_on' => $this->today()->addDays(35)->toDateString(),
            'ends_on' => $this->today()->addDays(45)->toDateString(),
        ]);

        $this->assertSame(2, DB::connection('pgsql')->table('examinations')->count(),
            'Future-dated AND overlapping examination windows must both be permitted.');
    }

    // --- designed shape -----------------------------------------------

    #[Test]
    public function the_designed_constraints_exist_with_the_exact_expected_shape(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['examinations'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'FOREIGN KEY (academic_year_id, school_id) REFERENCES academic_years(id, school_id) ON DELETE RESTRICT',
            $constraints['examinations_academic_year_fk'] ?? null,
        );
        $this->assertSame('UNIQUE (id, school_id)', $constraints['examinations_id_school_id_unique'] ?? null);
        $this->assertArrayHasKey('examinations_status_check', $constraints);
        $this->assertArrayHasKey('examinations_date_order_check', $constraints);

        // No composite-context FK and no extra parent: School +
        // AcademicYear only.
        $foreignKeys = collect(DB::connection('pgsql_admin')->select(
            'select conname from pg_constraint where conrelid = ?::regclass and contype = ?',
            ['examinations', 'f'],
        ))->pluck('conname')->sort()->values()->all();

        $this->assertSame(
            ['examinations_academic_year_fk', 'examinations_school_id_foreign'],
            $foreignKeys,
            'Examination has exactly two foreign keys: the tenant root and the AcademicYear.',
        );
    }

    #[Test]
    public function the_code_index_is_unconditional(): void
    {
        $index = DB::connection('pgsql_admin')->selectOne(
            'select indexdef from pg_indexes where indexname = ?',
            ['examinations_year_code_ci_unique'],
        );

        $this->assertNotNull($index);
        // PostgreSQL renders the expression as `upper((code)::text)`.
        $this->assertStringContainsString('upper(', $index->indexdef);
        $this->assertStringContainsString('academic_year_id', $index->indexdef);
        // A partial index scoped to active rows would silently allow a
        // retired examination's code to be reused and then collide on
        // reinstatement.
        $this->assertStringNotContainsString('WHERE', $index->indexdef);
    }

    #[Test]
    public function the_table_carries_no_person_paper_or_result_column(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ?',
            ['examinations'],
        ))->pluck('column_name')->all();

        $this->assertSame([
            'id', 'school_id', 'academic_year_id', 'code', 'name',
            'starts_on', 'ends_on', 'status', 'created_at', 'updated_at',
        ], $columns, 'The examinations column set is closed and reviewed; adding one is an architecture decision.');
    }
}
