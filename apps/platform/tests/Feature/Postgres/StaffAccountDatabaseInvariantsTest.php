<?php

namespace Tests\Feature\Postgres;

use App\Domain\Identity\Application\Staff\OneTimeCredential;
use App\Models\Role;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059 sections 4, 18; owner amendment): the staff account
 * guarantees the DATABASE enforces, proven with raw SQL on the runtime
 * connection -- independent of the application layer: the credential-less
 * state, the membership status set, School role-grant history (no runtime
 * DELETE, revoked rows immutable, one active grant), the activation and
 * invitation credential lifecycles, and forced RLS on the School-owned
 * invitation tables.
 */
class StaffAccountDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** Runs $statement in a savepoint and asserts PostgreSQL refuses it. */
    private function assertRefused(callable $statement, string $fragment): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());

            return;
        }

        $this->fail("PostgreSQL accepted a statement it must refuse ({$fragment}).");
    }

    private function inSchool(string $schoolId): void
    {
        DB::select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function roleId(string $key): string
    {
        return Role::query()->where('key', $key)->value('id');
    }

    #[Test]
    public function no_credential_has_exactly_one_form_and_is_never_restored_once_set(): void
    {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'name' => 'Pending', 'email' => 'pending@example.test', 'password' => null, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertRefused(fn () => DB::table('users')->insert(['id' => (string) Str::uuid7(), 'name' => 'Empty', 'email' => 'empty@example.test', 'password' => '']), 'users_password_not_empty');

        DB::table('users')->where('id', $id)->update(['password' => bcrypt('first-password-1')]);
        $this->assertSame(2, (int) DB::table('users')->where('id', $id)->value('credential_version'), 'The first credential moves the version on.');
        $this->assertRefused(fn () => DB::table('users')->where('id', $id)->update(['password' => null]), 'users_password_never_cleared');
    }

    #[Test]
    public function membership_status_is_the_closed_set(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();

        $this->assertRefused(fn () => DB::table('school_memberships')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'school_id' => $school->id, 'status' => 'revoked',
        ]), 'school_memberships_status_check');

        foreach (['invited', 'active', 'suspended'] as $status) {
            $this->createMembership($this->createUser(), $school, $status);
        }
        $this->assertSame(3, DB::table('school_memberships')->where('school_id', $school->id)->count());
    }

    #[Test]
    public function school_role_grants_keep_history_and_the_runtime_role_cannot_delete_them(): void
    {
        $school = $this->createSchool();
        $membership = $this->createMembership($this->createUser(), $school);
        $grant = $this->assignSchoolRole($membership, 'principal');
        $this->inSchool($school->id);

        $this->assertRefused(fn () => DB::table('membership_role_assignments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $this->roleId('principal'),
        ]), 'membership_role_assignments_one_active');
        $this->assertRefused(fn () => DB::table('membership_role_assignments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $this->roleId('school_admin'),
            'revoked_at' => now(), 'revocation_reason' => 'revoked',
        ]), 'cannot be created already revoked');
        $this->assertRefused(fn () => DB::table('membership_role_assignments')->where('id', $grant->id)->update(['role_id' => $this->roleId('school_admin')]), 'the only permitted change is revocation');
        $this->assertRefused(fn () => DB::table('membership_role_assignments')->where('id', $grant->id)->update(['revoked_at' => now(), 'revocation_reason' => 'fired']), 'membership_role_assignments_revocation_check');
        $this->assertRefused(fn () => DB::table('membership_role_assignments')->where('id', $grant->id)->delete(), 'permission denied for table membership_role_assignments');

        DB::table('membership_role_assignments')->where('id', $grant->id)->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']);
        $this->assertRefused(fn () => DB::table('membership_role_assignments')->where('id', $grant->id)->update(['revoked_at' => null, 'revocation_reason' => null]), 'cannot be reactivated');

        // A re-grant is a NEW row.
        DB::table('membership_role_assignments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $this->roleId('principal'),
        ]);
        $this->assertSame(2, DB::table('membership_role_assignments')->where('school_membership_id', $membership->id)->count());

        $privileges = DB::connection('pgsql_admin')->selectOne("select has_table_privilege('school_os_app', 'membership_role_assignments', 'DELETE') as d");
        $this->assertFalse($privileges->d);
    }

    #[Test]
    public function activation_credentials_are_bounded_single_open_and_end_one_way(): void
    {
        $user = $this->createUser(['password' => null]);
        $row = fn (array $overrides = []) => array_merge([
            'id' => (string) Str::uuid7(), 'selector' => OneTimeCredential::generate()->selector, 'user_id' => $user->id,
            'secret_hash' => hash('sha256', 'x'), 'credential_version' => 1, 'created_via' => 'console',
            'created_at' => now(), 'expires_at' => now()->addHours(24),
        ], $overrides);

        $this->assertRefused(fn () => DB::table('account_activation_credentials')->insert($row(['expires_at' => now()->addHours(73)])), 'account_activation_credentials_lifetime_check');
        $this->assertRefused(fn () => DB::table('account_activation_credentials')->insert($row(['created_via' => 'http'])), 'account_activation_credentials_via_check');

        $first = $row();
        DB::table('account_activation_credentials')->insert($first);
        $this->assertRefused(fn () => DB::table('account_activation_credentials')->insert($row()), 'account_activation_credentials_one_open');

        DB::table('account_activation_credentials')->where('id', $first['id'])->update(['consumed_at' => now()]);
        $this->assertRefused(fn () => DB::table('account_activation_credentials')->where('id', $first['id'])->update(['consumed_at' => null]), 'an ended credential stays ended');
        $this->assertRefused(fn () => DB::table('account_activation_credentials')->where('id', $first['id'])->update(['secret_hash' => hash('sha256', 'y')]), 'a credential never changes');

        // A User's credential-generation change ends every open one.
        $second = $row();
        DB::table('account_activation_credentials')->insert($second);
        DB::table('users')->where('id', $user->id)->update(['is_disabled' => true, 'disabled_at' => now()]);
        $this->assertSame('account_ineligible', DB::table('account_activation_credentials')->where('id', $second['id'])->value('invalidation_reason'));
    }

    #[Test]
    public function staff_invitations_are_bound_bounded_and_forced_rls(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $invitation = fn (string $schoolId, array $overrides = []) => array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $schoolId, 'destination_email' => 'staff@example.test',
            'selector' => OneTimeCredential::generate()->selector, 'secret_hash' => hash('sha256', 's'),
            'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);

        $this->inSchool($schoolA->id);
        $this->assertRefused(fn () => DB::table('staff_account_invitations')->insert($invitation($schoolA->id, ['destination_email' => 'Staff@Example.test'])), 'staff_account_invitations_email_check');
        $this->assertRefused(fn () => DB::table('staff_account_invitations')->insert($invitation($schoolA->id, ['expires_at' => now()->addDays(8)])), 'staff_account_invitations_lifetime_check');
        $this->assertRefused(fn () => DB::table('staff_account_invitations')->insert($invitation($schoolA->id, ['status' => 'accepted'])), 'staff_account_invitations_state_check');
        $this->assertRefused(fn () => DB::table('staff_account_invitations')->insert($invitation($schoolB->id)), 'row-level security');

        $a = $invitation($schoolA->id);
        DB::table('staff_account_invitations')->insert($a);
        $this->assertRefused(fn () => DB::table('staff_account_invitations')->insert($invitation($schoolA->id)), 'staff_account_invitations_one_pending');
        $this->assertRefused(fn () => DB::table('staff_account_invitations')->where('id', $a['id'])->update(['destination_email' => 'other@example.test']), 'an invitation never changes');

        // Roles: School scope only, same School only, immutable.
        $this->assertRefused(fn () => DB::table('staff_account_invitation_roles')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $schoolA->id, 'staff_account_invitation_id' => $a['id'], 'role_id' => $this->roleId('platform_super_admin'), 'created_at' => now(),
        ]), 'must reference a role with scope=school');
        $roleRow = ['id' => (string) Str::uuid7(), 'school_id' => $schoolA->id, 'staff_account_invitation_id' => $a['id'], 'role_id' => $this->roleId('principal'), 'created_at' => now()];
        DB::table('staff_account_invitation_roles')->insert($roleRow);
        $this->assertRefused(fn () => DB::table('staff_account_invitation_roles')->where('id', $roleRow['id'])->update(['role_id' => $this->roleId('school_admin')]), 'permission denied');

        // School B sees nothing; no context sees nothing.
        $this->inSchool($schoolB->id);
        $this->assertSame(0, DB::table('staff_account_invitations')->count());
        $this->assertSame(0, DB::table('staff_account_invitation_roles')->count());
        $this->assertSame(0, DB::table('staff_account_invitations')->where('id', $a['id'])->update(['status' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertSame(0, DB::table('membership_role_assignments')->where('school_id', $schoolA->id)->count());
        DB::statement('RESET '.TenantRls::SESSION_VAR);
        $this->assertSame(0, DB::table('staff_account_invitations')->count());

        foreach (['staff_account_invitations', 'staff_account_invitation_roles'] as $table) {
            $flags = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace", [$table]);
            $this->assertTrue($flags->relrowsecurity && $flags->relforcerowsecurity, "{$table} has forced RLS");
        }
    }

    #[Test]
    public function the_staff_invitation_email_purpose_is_critical_and_school_scoped(): void
    {
        $school = $this->createSchool();
        $this->inSchool($school->id);
        $message = fn (array $overrides) => array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'purpose' => 'staff_account_invitation', 'kind' => 'critical',
            'source_type' => 'staff_account_invitation', 'source_id' => (string) Str::uuid7(), 'recipient_encrypted' => encrypt('x@example.test'),
            'from_mailbox' => 'notifications', 'from_display_name' => 'Lycenza', 'subject' => 'S', 'sealed_content' => encrypt('{}'),
            'status' => 'pending', 'next_attempt_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);

        $this->assertRefused(fn () => DB::table('email_messages')->insert($message(['kind' => 'standard'])), 'email_messages_kind_check');
        $this->assertRefused(fn () => DB::table('email_messages')->insert($message(['source_type' => 'guardian_account_invitation'])), 'email_messages_source_check');
    }
}
