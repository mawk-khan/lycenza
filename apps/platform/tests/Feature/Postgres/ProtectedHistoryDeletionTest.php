<?php

namespace Tests\Feature\Postgres;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.1A (ADR 0076 §24, V-3): audit, accounting, settlement and grant
 * history fail closed under deletion. The runtime role cannot delete a
 * School or a User at all; these proofs use the PRIVILEGED (migration)
 * connection, inside a transaction that is always rolled back, so nothing
 * is committed. Only the classified history keys changed: ordinary School
 * data and User credential material still cascade.
 */
class ProtectedHistoryDeletionTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** The 22 keys ORG.1A made RESTRICT (2026_12_18_090200), each with its exact definition. */
    private const RESTRICTED = [
        'school_audit_events_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'ledger_accounts_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'journal_entries_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'journal_lines_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'financial_periods_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'financial_period_account_balances_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'financial_period_charge_states_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'financial_period_expiries_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payroll_run_postings_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payroll_statutory_run_postings_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'charges_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'fee_adjustments_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payments_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payment_allocations_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payment_receipts_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payment_corrections_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payment_correction_lines_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'payment_provider_events_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'school_memberships_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'school_memberships_user_id_foreign' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT',
        'membership_role_assignments_school_id_foreign' => 'FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT',
        'membership_role_assignments_school_membership_id_school_id_fore' => 'FOREIGN KEY (school_membership_id, school_id) REFERENCES school_memberships(id, school_id) ON DELETE RESTRICT',
    ];

    private function admin(): Connection
    {
        return DB::connection('pgsql_admin');
    }

    /** Runs $scenario on the privileged connection and ALWAYS rolls it back. */
    private function inRolledBackAdminTransaction(callable $scenario): void
    {
        $admin = $this->admin();
        $admin->beginTransaction();
        try {
            $scenario($admin);
        } finally {
            $admin->rollBack();
        }
    }

    /** One statement inside its own savepoint, expected to be refused naming $needle. */
    private function assertRefused(Connection $admin, callable $statement, string $needle): void
    {
        try {
            $admin->transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Expected the database to refuse the deletion ({$needle}).");
    }

    private function school(Connection $admin): string
    {
        $id = (string) Str::uuid7();
        $admin->table('schools')->insert(['id' => $id, 'name' => 'History School', 'slug' => 'history-'.Str::lower(Str::random(10)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function user(Connection $admin): string
    {
        $id = (string) Str::uuid7();
        $admin->table('users')->insert(['id' => $id, 'name' => 'History Person', 'email' => 'history.'.Str::lower(Str::random(10)).'@example.test', 'password' => bcrypt('irrelevant-password'), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function membership(Connection $admin, string $userId, string $schoolId): string
    {
        $id = (string) Str::uuid7();
        $admin->table('school_memberships')->insert(['id' => $id, 'user_id' => $userId, 'school_id' => $schoolId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    #[Test]
    public function exactly_the_classified_history_keys_are_restrict(): void
    {
        $rows = $this->admin()->select(
            "SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE contype = 'f' AND conname = ANY(?)",
            ['{'.implode(',', array_keys(self::RESTRICTED)).'}'],
        );

        $this->assertCount(count(self::RESTRICTED), $rows);
        foreach ($rows as $row) {
            $this->assertSame(self::RESTRICTED[$row->conname], $row->def, $row->conname);
        }
    }

    #[Test]
    public function the_change_is_not_blanket_ordinary_data_and_credential_material_still_cascade(): void
    {
        $cascading = $this->admin()->select(
            "SELECT conname FROM pg_constraint WHERE contype = 'f' AND confdeltype = 'c'
               AND conname IN ('campuses_school_id_foreign', 'school_settings_school_id_foreign', 'students_school_id_foreign',
                               'user_mfa_factors_user_id_foreign', 'user_mfa_recovery_codes_user_id_foreign',
                               'communication_preferences_school_id_foreign')",
        );
        $this->assertCount(6, $cascading, 'operational School rows and User credential material keep their cascades');

        $schoolCascades = (int) $this->admin()->scalar("SELECT count(*) FROM pg_constraint WHERE contype = 'f' AND confrelid = 'schools'::regclass AND confdeltype = 'c'");
        $this->assertGreaterThan(150, $schoolCascades, 'only the classified history keys changed, not every School cascade');
    }

    #[Test]
    public function a_privileged_school_delete_cannot_erase_its_audit_ledger(): void
    {
        $this->inRolledBackAdminTransaction(function (Connection $admin): void {
            $school = $this->school($admin);
            $admin->table('school_audit_events')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school, 'event_type' => 'test.history', 'occurred_at' => now()]);

            $this->assertRefused($admin, fn () => $admin->table('schools')->where('id', $school)->delete(), 'school_audit_events_school_id_foreign');
            $this->assertSame(1, $admin->table('school_audit_events')->where('school_id', $school)->count());
        });
    }

    #[Test]
    public function a_privileged_school_delete_cannot_erase_accounting_or_settlement_history(): void
    {
        $this->inRolledBackAdminTransaction(function (Connection $admin): void {
            $school = $this->school($admin);
            $admin->table('ledger_accounts')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now()]);

            $this->assertRefused($admin, fn () => $admin->table('schools')->where('id', $school)->delete(), 'ledger_accounts_school_id_foreign');
        });

        $this->inRolledBackAdminTransaction(function (Connection $admin): void {
            $school = $this->school($admin);
            $admin->table('payment_provider_events')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school, 'provider' => 'test', 'provider_event_id' => 'evt-'.Str::random(8),
                'event_type' => 'payment.captured', 'provider_payment_reference' => 'pay-'.Str::random(8), 'amount' => '100.00', 'currency' => 'INR',
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->assertRefused($admin, fn () => $admin->table('schools')->where('id', $school)->delete(), 'payment_provider_events_school_id_foreign');
        });
    }

    #[Test]
    public function neither_a_school_nor_a_user_delete_can_erase_grant_history(): void
    {
        $this->inRolledBackAdminTransaction(function (Connection $admin): void {
            $school = $this->school($admin);
            $user = $this->user($admin);
            $membership = $this->membership($admin, $user, $school);
            $admin->table('membership_role_assignments')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school, 'school_membership_id' => $membership,
                'role_id' => $admin->table('roles')->where('key', 'school_admin')->value('id'), 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->assertRefused($admin, fn () => $admin->table('users')->where('id', $user)->delete(), 'school_memberships_user_id_foreign');
            $this->assertRefused($admin, fn () => $admin->table('schools')->where('id', $school)->delete(), 'school_memberships_school_id_foreign');
            $this->assertRefused($admin, fn () => $admin->table('school_memberships')->where('id', $membership)->delete(), 'membership_role_assignments_school_membership_id_school_id_fore');

            $this->assertSame(1, $admin->table('membership_role_assignments')->where('school_membership_id', $membership)->count());
        });
    }

    #[Test]
    public function a_school_holding_only_ordinary_data_still_deletes_through_the_privileged_connection(): void
    {
        $this->inRolledBackAdminTransaction(function (Connection $admin): void {
            $school = $this->school($admin);
            $admin->table('campuses')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            $this->assertSame(1, $admin->table('schools')->where('id', $school)->delete());
            $this->assertSame(0, $admin->table('campuses')->where('school_id', $school)->count());
        });
    }

    #[Test]
    public function the_runtime_role_still_cannot_delete_schools_or_users(): void
    {
        foreach (['schools', 'users'] as $table) {
            $this->assertFalse((bool) $this->admin()->selectOne("select has_table_privilege('school_os_app', ?, 'DELETE') as p", [$table])->p, $table);
        }

        $school = $this->createSchool();
        try {
            DB::transaction(fn () => DB::table('schools')->where('id', $school->id)->delete());
            $this->fail('The runtime role must not delete a School.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied for table schools', $e->getMessage());
        }
    }
}
