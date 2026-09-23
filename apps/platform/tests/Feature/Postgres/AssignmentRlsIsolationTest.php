<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Feature\LMS\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.3, mandatory per root CLAUDE.md rule 28. Every test here
 * writes RAW SQL, deliberately bypassing the controller/service and all
 * application validation, to prove the DATABASE -- not the application
 * -- enforces tenant isolation, the tenant-pinned SubjectOffering
 * reference, the status vocabulary, and historical delete protection.
 * Mirrors Tests\Feature\Postgres\LearningContentRlsIsolationTest exactly.
 */
class AssignmentRlsIsolationTest extends TestCase
{
    use CreatesAssignmentFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertAssignment(School $school, string $offeringId, array $overrides = []): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table('assignments')->insert(array_merge([
            'id' => $id,
            'school_id' => $school->id,
            'subject_offering_id' => $offeringId,
            'title' => 'Essay',
            'status' => 'draft',
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
            ['assignments', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity, 'assignments must have RLS ENABLED');
        $this->assertTrue($row->relforcerowsecurity, 'assignments must have RLS FORCED');
    }

    #[Test]
    public function tenant_isolation_holds_at_the_raw_sql_layer(): void
    {
        $w = $this->assignmentWorld();
        $this->setSchool($w['school']->id);
        $this->insertAssignment($w['school'], $w['offering']->id);

        $this->assertSame(1, DB::connection('pgsql')->table('assignments')->count());

        $other = $this->assignmentWorld();
        $this->setSchool($other['school']->id);
        $this->assertSame(0, DB::connection('pgsql')->table('assignments')->count());

        $this->assertSame(0, DB::connection('pgsql')->table('assignments')
            ->where('school_id', $w['school']->id)->update(['title' => 'hijacked']));

        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        $this->assertSame(0, DB::connection('pgsql')->table('assignments')->count());
    }

    #[Test]
    public function a_raw_insert_for_another_school_is_rejected_by_the_policy(): void
    {
        $w = $this->assignmentWorld();
        $other = $this->assignmentWorld();

        $this->setSchool($other['school']->id);

        $this->assertRejectedBy(
            'row-level security',
            fn () => $this->insertAssignment($w['school'], $w['offering']->id),
            'A raw insert for a different School than the active context must be rejected by RLS.',
        );
    }

    #[Test]
    public function a_cross_school_subject_offering_is_rejected_by_the_composite_fk(): void
    {
        $w = $this->assignmentWorld();
        $other = $this->assignmentWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'assignments_subject_offering_fk',
            fn () => $this->insertAssignment($w['school'], $other['offering']->id),
            'An Assignment must not reference another School\'s SubjectOffering.',
        );
    }

    #[Test]
    public function an_invalid_status_is_rejected_by_the_check_constraint(): void
    {
        $w = $this->assignmentWorld();
        $this->setSchool($w['school']->id);

        $this->assertRejectedBy(
            'assignments_status_check',
            fn () => $this->insertAssignment($w['school'], $w['offering']->id, ['status' => 'archived']),
            'An out-of-vocabulary status must be rejected by the CHECK constraint.',
        );
    }

    #[Test]
    public function a_referenced_subject_offering_cannot_be_hard_deleted(): void
    {
        $w = $this->assignmentWorld();
        $this->setSchool($w['school']->id);
        $this->insertAssignment($w['school'], $w['offering']->id);

        $this->assertRejectedBy(
            'assignments_subject_offering_fk',
            fn () => DB::connection('pgsql')->table('subject_offerings')->where('id', $w['offering']->id)->delete(),
            'A SubjectOffering carrying Assignments must not be hard-deletable.',
        );
    }

    #[Test]
    public function the_designed_constraints_exist_with_the_exact_expected_shape(): void
    {
        $constraints = collect(DB::connection('pgsql_admin')->select(
            'select conname, pg_get_constraintdef(oid) as def from pg_constraint where conrelid = ?::regclass',
            ['assignments'],
        ))->pluck('def', 'conname')->all();

        $this->assertSame(
            'FOREIGN KEY (subject_offering_id, school_id) REFERENCES subject_offerings(id, school_id) ON DELETE RESTRICT',
            $constraints['assignments_subject_offering_fk'] ?? null,
        );
        $this->assertSame(
            'UNIQUE (id, school_id)',
            $constraints['assignments_id_school_id_unique'] ?? null,
        );
        $this->assertArrayHasKey('assignments_status_check', $constraints);
    }
}
