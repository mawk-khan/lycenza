<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Examinations\Concerns\CreatesGradeScaleFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4C, mandatory per root CLAUDE.md rule 28. Every test here
 * writes RAW SQL, deliberately bypassing the service and all
 * application validation, to prove the DATABASE -- not the
 * application -- enforces tenant isolation, code uniqueness, the
 * threshold domain/uniqueness, and historical delete protection.
 *
 * Mirrors ExaminationPapersRlsIsolationTest/ExaminationsRlsIsolationTest,
 * including the SAVEPOINT-based rejection helper.
 *
 * Lifecycle/concurrency correctness (the row-lock protocol, the
 * activation coverage check, the illegal-transition matrix) is proven
 * behaviorally in GradeScaleServiceTest and
 * GradeScaleConcurrencyTest, not here -- this file is purely about
 * what raw SQL alone can and cannot do.
 */
class GradeScalesRlsIsolationTest extends TestCase
{
    use CreatesGradeScaleFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertGradeScale(School $school, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('grade_scales')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'code' => 'GS1',
            'name' => 'Standard Scale',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function insertGradeBand(School $school, string $gradeScaleId, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('grade_bands')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'grade_scale_id' => $gradeScaleId,
            'min_percentage' => '50.00',
            'label' => 'P',
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

    // --- RLS --------------------------------------------------------------

    #[Test]
    public function the_grade_scales_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['grade_scales', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity, 'grade_scales must have RLS ENABLED');
        $this->assertTrue($row->relforcerowsecurity, 'grade_scales must have RLS FORCED');
    }

    #[Test]
    public function the_grade_bands_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['grade_bands', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity, 'grade_bands must have RLS ENABLED');
        $this->assertTrue($row->relforcerowsecurity, 'grade_bands must have RLS FORCED');
    }

    #[Test]
    public function tenant_isolation_holds_at_the_raw_sql_layer(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $scaleId = $this->insertGradeScale($w['school']);

        $this->assertSame(1, DB::connection('pgsql')->table('grade_scales')->count());

        $other = $this->gradeScaleWorld();
        $this->setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table('grade_scales')->count());

        $this->assertSame(0, DB::connection('pgsql')->table('grade_scales')
            ->where('id', $scaleId)->update(['name' => 'hijacked']));

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::connection('pgsql')->table('grade_scales')->count());
    }

    #[Test]
    public function a_raw_insert_for_another_school_is_rejected_by_the_policy(): void
    {
        $w = $this->gradeScaleWorld();
        $other = $this->gradeScaleWorld();

        $this->setSchool($other['school']->id);

        $this->assertRejectedBy(
            'row-level security',
            fn () => $this->insertGradeScale($w['school']),
            'A raw insert for a different School than the active context must be rejected by RLS.',
        );
    }

    // --- code uniqueness ----------------------------------------------------

    #[Test]
    public function a_case_insensitive_duplicate_code_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $this->insertGradeScale($w['school'], ['code' => 'GS1']);

        $this->assertRejectedBy(
            'grade_scales_school_id_code_ci_unique',
            fn () => $this->insertGradeScale($w['school'], ['code' => 'gs1']),
            'A case-variant duplicate code must be rejected by the database.',
        );
    }

    #[Test]
    public function code_uniqueness_is_unconditional_across_statuses(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $this->insertGradeScale($w['school'], ['code' => 'GS1', 'status' => 'inactive']);

        $this->assertRejectedBy(
            'grade_scales_school_id_code_ci_unique',
            fn () => $this->insertGradeScale($w['school'], ['code' => 'GS1', 'status' => 'draft']),
            'An inactive GradeScale must continue to reserve its code.',
        );
    }

    #[Test]
    public function an_invalid_status_is_rejected_by_the_check_constraint(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'grade_scales_status_check',
            fn () => $this->insertGradeScale($w['school'], ['status' => 'archived']),
            'An out-of-vocabulary status must be rejected by the CHECK constraint.',
        );
    }

    // --- grade_bands: cross-parent, thresholds -------------------------------

    #[Test]
    public function a_cross_school_grade_band_parent_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $other = $this->gradeScaleWorld();

        $this->setSchool($other['school']->id);
        $otherScaleId = $this->insertGradeScale($other['school']);

        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'grade_bands_grade_scale_fk',
            fn () => $this->insertGradeBand($w['school'], $otherScaleId),
            'A GradeBand must not reference another School\'s GradeScale.',
        );
    }

    #[Test]
    public function a_referenced_grade_scale_cannot_be_hard_deleted(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $scaleId = $this->insertGradeScale($w['school']);
        $this->insertGradeBand($w['school'], $scaleId);

        $this->assertRejectedBy(
            'grade_bands_grade_scale_fk',
            fn () => DB::connection('pgsql')->table('grade_scales')->where('id', $scaleId)->delete(),
            'A GradeScale carrying GradeBands must not be hard-deletable.',
        );
    }

    #[Test]
    public function min_percentage_zero_and_one_hundred_are_accepted(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $scaleId = $this->insertGradeScale($w['school']);

        $this->insertGradeBand($w['school'], $scaleId, ['min_percentage' => '0.00', 'label' => 'F']);
        $this->insertGradeBand($w['school'], $scaleId, ['min_percentage' => '100.00', 'label' => 'A+']);

        $this->assertSame(2, DB::connection('pgsql')->table('grade_bands')->where('grade_scale_id', $scaleId)->count());
    }

    #[Test]
    public function below_zero_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $scaleId = $this->insertGradeScale($w['school']);

        $this->assertRejectedBy(
            'grade_bands_min_percentage_range_check',
            fn () => $this->insertGradeBand($w['school'], $scaleId, ['min_percentage' => '-0.01']),
            'A negative threshold must be rejected.',
        );
    }

    #[Test]
    public function above_one_hundred_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $scaleId = $this->insertGradeScale($w['school']);

        $this->assertRejectedBy(
            'grade_bands_min_percentage_range_check',
            fn () => $this->insertGradeBand($w['school'], $scaleId, ['min_percentage' => '100.01']),
            'A threshold above 100 must be rejected.',
        );
    }

    #[Test]
    public function a_duplicate_threshold_within_one_scale_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $scaleId = $this->insertGradeScale($w['school']);
        $this->insertGradeBand($w['school'], $scaleId, ['min_percentage' => '50.00']);

        $this->assertRejectedBy(
            'grade_bands_min_percentage_unique',
            fn () => $this->insertGradeBand($w['school'], $scaleId, ['min_percentage' => '50.00', 'label' => 'Other']),
            'A duplicate threshold within one scale must be rejected.',
        );
    }

    #[Test]
    public function the_same_threshold_in_a_different_scale_is_accepted(): void
    {
        $w = $this->gradeScaleWorld();
        $this->setSchool($w['school']->id);
        $scaleAId = $this->insertGradeScale($w['school'], ['code' => 'GSA']);
        $scaleBId = $this->insertGradeScale($w['school'], ['code' => 'GSB']);

        $this->insertGradeBand($w['school'], $scaleAId, ['min_percentage' => '50.00']);
        $this->insertGradeBand($w['school'], $scaleBId, ['min_percentage' => '50.00']);

        $this->assertSame(2, DB::connection('pgsql')->table('grade_bands')->where('min_percentage', '50.00')->count());
    }

    // --- designed shape -------------------------------------------------------

    #[Test]
    public function the_grade_scales_designed_constraints_exist_with_the_exact_expected_shape(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['grade_scales'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame('UNIQUE (id, school_id)', $constraints['grade_scales_id_school_id_unique'] ?? null);
        $this->assertArrayHasKey('grade_scales_status_check', $constraints);

        $foreignKeys = collect(DB::connection('pgsql_admin')->select(
            'select conname from pg_constraint where conrelid = ?::regclass and contype = ?',
            ['grade_scales', 'f'],
        ))->pluck('conname')->sort()->values()->all();

        $this->assertSame(['grade_scales_school_id_foreign'], $foreignKeys,
            'GradeScale has exactly one foreign key: the tenant root.');
    }

    #[Test]
    public function the_grade_bands_designed_constraints_exist_with_the_exact_expected_shape(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['grade_bands'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame('UNIQUE (id, school_id)', $constraints['grade_bands_id_school_id_unique'] ?? null);
        $this->assertSame(
            'UNIQUE (grade_scale_id, min_percentage)',
            $constraints['grade_bands_min_percentage_unique'] ?? null,
        );
        $this->assertSame(
            'FOREIGN KEY (grade_scale_id, school_id) REFERENCES grade_scales(id, school_id) ON DELETE RESTRICT',
            $constraints['grade_bands_grade_scale_fk'] ?? null,
        );
        $this->assertArrayHasKey('grade_bands_min_percentage_range_check', $constraints);

        $foreignKeys = collect(DB::connection('pgsql_admin')->select(
            'select conname from pg_constraint where conrelid = ?::regclass and contype = ?',
            ['grade_bands', 'f'],
        ))->pluck('conname')->sort()->values()->all();

        $this->assertSame(
            ['grade_bands_grade_scale_fk', 'grade_bands_school_id_foreign'],
            $foreignKeys,
        );
    }

    #[Test]
    public function the_code_index_is_unconditional(): void
    {
        $index = DB::connection('pgsql_admin')->selectOne(
            'select indexdef from pg_indexes where indexname = ?',
            ['grade_scales_school_id_code_ci_unique'],
        );

        $this->assertNotNull($index);
        $this->assertStringContainsString('upper(', $index->indexdef);
        $this->assertStringNotContainsString('WHERE', $index->indexdef);
    }

    #[Test]
    public function no_range_type_or_exclusion_constraint_is_used(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select data_type from information_schema.columns where table_name = ? order by ordinal_position',
            ['grade_bands'],
        ))->pluck('data_type')->all();

        foreach ($columns as $type) {
            $this->assertStringNotContainsString('range', strtolower($type));
        }

        $exclusionConstraints = DB::connection('pgsql_admin')->select(
            "select conname from pg_constraint where conrelid IN ('grade_scales'::regclass, 'grade_bands'::regclass) and contype = 'x'",
        );
        $this->assertCount(0, $exclusionConstraints, 'No exclusion constraint may exist on either table.');
    }

    #[Test]
    public function the_grade_scales_column_set_is_closed(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ? order by ordinal_position',
            ['grade_scales'],
        ))->pluck('column_name')->all();

        $this->assertSame(
            ['id', 'school_id', 'code', 'name', 'status', 'created_at', 'updated_at'],
            $columns,
            'The grade_scales column set is closed and reviewed; adding one is an architecture decision.',
        );
    }

    #[Test]
    public function the_grade_bands_column_set_is_closed(): void
    {
        $columns = collect(DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ? order by ordinal_position',
            ['grade_bands'],
        ))->pluck('column_name')->all();

        $this->assertSame(
            ['id', 'school_id', 'grade_scale_id', 'min_percentage', 'label', 'created_at', 'updated_at'],
            $columns,
            'The grade_bands column set is closed and reviewed; adding one is an architecture decision.',
        );
    }
}
