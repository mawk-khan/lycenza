<?php

namespace Tests\Feature\Postgres;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * HR.R1-C4: this migration must not repeat PAY-15. The documented migration role
 * (`infrastructure/postgres/production-bootstrap.sql`) is NOSUPERUSER NOBYPASSRLS,
 * and FORCE ROW LEVEL SECURITY binds a table's owner too, so a plain read in
 * down() sees no rows there. This runs the real down() as such an owner, against
 * a real row, inside one transaction that is always rolled back.
 */
class PayrollAttestationRollbackGuardTest extends TestCase
{
    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    #[Test]
    public function the_down_guard_sees_evidence_as_an_owner_without_bypassrls(): void
    {
        $admin = DB::connection('pgsql_admin');
        $default = DB::getDefaultConnection();
        $admin->beginTransaction();
        try {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $admin->statement("INSERT INTO payroll_posting_attestations (id, school_id, payroll_run_id, payroll_run_calculation_id, payroll_run_posting_id, evidence_version, statement_code, attested_by_user_id)
                VALUES (gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), repeat('a', 64), 'independence_changed_v1', gen_random_uuid())");
            $admin->statement("SET LOCAL session_replication_role = 'origin'");
            $admin->statement('CREATE ROLE c4_rollback_probe NOSUPERUSER NOBYPASSRLS NOLOGIN');
            $admin->statement('GRANT ALL ON SCHEMA public TO c4_rollback_probe');
            $admin->statement('ALTER TABLE payroll_posting_attestations OWNER TO c4_rollback_probe');
            $admin->statement('SET LOCAL ROLE c4_rollback_probe');

            $this->assertFalse($admin->table('payroll_posting_attestations')->exists(), 'PAY-15: a plain read sees nothing on this role');

            DB::setDefaultConnection('pgsql_admin');
            $migration = require database_path('migrations/2027_02_06_090000_create_payroll_posting_attestations.php');
            try {
                $migration->down();
                $this->fail('down() dropped recorded evidence');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Refusing to roll back', $e->getMessage());
            }
        } finally {
            DB::setDefaultConnection($default);
            $admin->rollBack();
        }
    }
}
