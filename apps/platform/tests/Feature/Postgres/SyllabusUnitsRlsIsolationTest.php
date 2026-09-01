<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\Syllabus\Concerns\CreatesSyllabusFixtures;
use Tests\TestCase;

/**
 * Phase 0H.3A, mandatory per root CLAUDE.md rule 28. Every test here
 * writes RAW SQL, deliberately bypassing the controller and all
 * application validation, to prove the DATABASE -- not the application
 * -- enforces tenant isolation, the tenant-pinned parent reference,
 * case-insensitive code uniqueness, the status vocabulary, and
 * historical delete protection.
 *
 * Mirrors TimetableEntriesRlsIsolationTest/
 * AttendanceRecordsContextIntegrityTest, including the SAVEPOINT-based
 * rejection helper: a constraint violation aborts the current
 * (sub)transaction, so without a savepoint to roll back to, every later
 * statement in this test's enclosing DatabaseTransactions transaction
 * would fail with SQLSTATE 25P02 instead of its own real error.
 */
class SyllabusUnitsRlsIsolationTest extends TestCase
{
    use CreatesSyllabusFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertUnit(School $school, string $offeringId, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('syllabus_units')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'subject_offering_id' => $offeringId,
            'code' => 'U1',
            'title' => 'Unit one',
            'sequence' => 1,
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

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['syllabus_units', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity, 'syllabus_units must have RLS ENABLED');
        $this->assertTrue($row->relforcerowsecurity, 'syllabus_units must have RLS FORCED');
    }

    #[Test]
    public function tenant_isolation_holds_at_the_raw_sql_layer(): void
    {
        $w = $this->syllabusWorld();
        $this->setSchool($w['school']->id);
        $this->insertUnit($w['school'], $w['offering']->id);

        $this->assertSame(1, DB::connection('pgsql')->table('syllabus_units')->count());

        // School B sees none of School A's rows.
        $other = $this->syllabusWorld();
        $this->setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table('syllabus_units')->count());

        // Cross-School UPDATE affects zero rows.
        $this->assertSame(0, DB::connection('pgsql')->table('syllabus_units')
            ->where('school_id', $w['school']->id)->update(['title' => 'hijacked']));

        // Missing tenant context fails closed rather than exposing all.
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::connection('pgsql')->table('syllabus_units')->count());
    }

    #[Test]
    public function a_raw_insert_for_another_school_is_rejected_by_the_policy(): void
    {
        $w = $this->syllabusWorld();
        $other = $this->syllabusWorld();

        // Active context is School B; try to write a School A row.
        $this->setSchool($other['school']->id);

        $this->assertRejectedBy(
            'row-level security',
            fn () => $this->insertUnit($w['school'], $w['offering']->id),
            'A raw insert for a different School than the active context must be rejected by RLS.',
        );
    }

    #[Test]
    public function a_cross_school_subject_offering_is_rejected_by_the_composite_fk(): void
    {
        $w = $this->syllabusWorld();
        $other = $this->syllabusWorld();
        $this->setSchool($w['school']->id);

        // School A's row claiming School B's SubjectOffering.
        $this->assertRejectedBy(
            'syllabus_units_subject_offering_fk',
            fn () => $this->insertUnit($w['school'], $other['offering']->id),
            'A SyllabusUnit must not reference another School\'s SubjectOffering.',
        );
    }

    #[Test]
    public function a_case_insensitive_duplicate_code_is_rejected_by_the_database(): void
    {
        $w = $this->syllabusWorld();
        $this->setSchool($w['school']->id);
        $this->insertUnit($w['school'], $w['offering']->id, ['code' => 'U1']);

        // Differs only in case -- the expression index must reject it,
        // with no application validation involved.
        $this->assertRejectedBy(
            'syllabus_units_offering_code_ci_unique',
            fn () => $this->insertUnit($w['school'], $w['offering']->id, ['code' => 'u1', 'sequence' => 2]),
            'A case-variant duplicate code within one Offering must be rejected by the database.',
        );

        // An INACTIVE row still reserves its code -- the index is
        // deliberately unconditional, which is what makes reactivation
        // conflict-free and removes the need for a lifecycle command.
        DB::connection('pgsql')->table('syllabus_units')
            ->where('school_id', $w['school']->id)->update(['status' => 'inactive']);

        $this->assertRejectedBy(
            'syllabus_units_offering_code_ci_unique',
            fn () => $this->insertUnit($w['school'], $w['offering']->id, ['code' => 'U1', 'sequence' => 3]),
            'An inactive unit must continue to reserve its code.',
        );
    }

    #[Test]
    public function the_same_code_under_a_different_offering_is_accepted(): void
    {
        $w = $this->syllabusWorld();
        $this->setSchool($w['school']->id);
        $this->insertUnit($w['school'], $w['offering']->id, ['code' => 'U1']);

        $other = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'OTH']),
            ['is_required' => true, 'status' => 'active'],
        );
        $this->setSchool($w['school']->id);

        $this->insertUnit($w['school'], $other->id, ['code' => 'U1']);

        $this->assertSame(2, DB::connection('pgsql')->table('syllabus_units')->count());
    }

    #[Test]
    public function an_invalid_status_is_rejected_by_the_check_constraint(): void
    {
        $w = $this->syllabusWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'syllabus_units_status_check',
            fn () => $this->insertUnit($w['school'], $w['offering']->id, ['status' => 'archived']),
            'An out-of-vocabulary status must be rejected by the CHECK constraint.',
        );
    }

    #[Test]
    public function a_referenced_subject_offering_cannot_be_hard_deleted(): void
    {
        $w = $this->syllabusWorld();
        $this->setSchool($w['school']->id);
        $this->insertUnit($w['school'], $w['offering']->id);

        $this->assertRejectedBy(
            'syllabus_units_subject_offering_fk',
            fn () => DB::connection('pgsql')->table('subject_offerings')->where('id', $w['offering']->id)->delete(),
            'A SubjectOffering carrying syllabus content must not be hard-deletable.',
        );
    }

    #[Test]
    public function the_designed_constraints_exist_with_the_exact_expected_shape(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['syllabus_units'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'FOREIGN KEY (subject_offering_id, school_id) REFERENCES subject_offerings(id, school_id) ON DELETE RESTRICT',
            $constraints['syllabus_units_subject_offering_fk'] ?? null,
        );
        $this->assertSame(
            'UNIQUE (id, school_id)',
            $constraints['syllabus_units_id_school_id_unique'] ?? null,
        );
        $this->assertArrayHasKey('syllabus_units_status_check', $constraints);

        // The unique code index must be UNCONDITIONAL -- a partial index
        // scoped to active rows would silently allow a retired unit's
        // code to be reused and then collide on reactivation.
        $index = DB::connection('pgsql_admin')->selectOne(
            'select indexdef from pg_indexes where indexname = ?',
            ['syllabus_units_offering_code_ci_unique'],
        );
        $this->assertNotNull($index);
        // PostgreSQL renders the expression as `upper((code)::text)`.
        $this->assertStringContainsString('upper(', $index->indexdef);
        $this->assertStringContainsString('code', $index->indexdef);
        $this->assertStringContainsString('subject_offering_id', $index->indexdef);
        $this->assertStringNotContainsString('WHERE', $index->indexdef);
    }
}
