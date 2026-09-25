<?php

namespace Tests\Feature\Postgres;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.7 (ADR 0046 sections 3-4): what the DATABASE enforces for
 * platform authority, independent of application code -- raw SQL as the
 * runtime role, each violation inside its own savepoint.
 */
class PlatformRoleAssignmentInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function roleId(string $key): string
    {
        return (string) DB::table('roles')->where('key', $key)->value('id');
    }

    #[Test]
    public function only_system_platform_non_root_roles_are_runtime_assignable_and_only_the_auditor_is(): void
    {
        $this->assertSame(['platform_auditor'], DB::table('roles')->where('runtime_assignable', true)->pluck('key')->all());

        // The root role holds root-reserved capabilities, so the trigger
        // refuses it even before the CHECK constraint is evaluated.
        $this->assertRejected(fn () => DB::table('roles')->where('key', 'platform_super_admin')->update(['runtime_assignable' => true]), 'a role holding a root-reserved capability cannot be runtime-assignable');
        $this->assertRejected(fn () => DB::table('roles')->where('key', 'school_admin')->update(['runtime_assignable' => true]), 'roles_runtime_assignable_check');
        $this->assertRejected(fn () => DB::table('roles')->where('key', 'group_admin')->update(['runtime_assignable' => true]), 'roles_runtime_assignable_check');
        $this->assertRejected(fn () => DB::table('roles')->insert(['id' => (string) Str::uuid7(), 'key' => 'custom_'.Str::random(5), 'name' => 'X', 'scope' => 'platform', 'is_system' => false, 'runtime_assignable' => true]), 'roles_runtime_assignable_check');
    }

    #[Test]
    public function a_runtime_assignable_role_never_holds_a_root_reserved_capability(): void
    {
        foreach (['platform.role_grants.manage', 'platform.schools.manage'] as $capability) {
            $this->assertRejected(fn () => DB::table('role_capabilities')->insert(['role_id' => $this->roleId('platform_auditor'), 'capability_key' => $capability]), 'root-reserved capability');
        }

        // Nor can a role already holding one be made runtime-assignable.
        $id = (string) Str::uuid7();
        DB::table('roles')->insert(['id' => $id, 'key' => 'sys_'.Str::random(5), 'name' => 'X', 'scope' => 'platform', 'is_system' => true]);
        DB::table('role_capabilities')->insert(['role_id' => $id, 'capability_key' => 'platform.schools.manage']);
        $this->assertRejected(fn () => DB::table('roles')->where('id', $id)->update(['runtime_assignable' => true]), 'root-reserved capability');

        $this->assertSame(['platform.audit.view'], DB::table('role_capabilities')->where('role_id', $this->roleId('platform_auditor'))->pluck('capability_key')->all());
    }

    #[Test]
    public function the_root_role_is_never_granted_or_revoked_at_runtime_and_nobody_acts_on_themselves(): void
    {
        $grantor = $this->createUser();
        $target = $this->createUser();

        // Provisioned (no grantor) is the out-of-band path, and since Phase
        // 0O.1A only the administrative boundary may take it
        // (PlatformRootBoundaryTest): the runtime role is refused.
        $this->assertRejected(fn () => $this->insertAssignment($target->id, 'platform_super_admin'), 'only the trusted administrative database role may provision');
        $root = $this->createPlatformRoot();
        $provisioned = (string) DB::table('platform_role_assignments')->where('user_id', $root->id)->value('id');

        $this->assertRejected(fn () => $this->insertAssignment($target->id, 'platform_super_admin', $grantor->id), 'only a runtime-assignable role can be granted at runtime');
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->where('id', $provisioned)->update(['revoked_at' => now(), 'revoked_by_user_id' => $grantor->id]), 'only a runtime-assignable role can be revoked at runtime');
        $this->assertRejected(fn () => $this->insertAssignment($target->id, 'platform_auditor', $target->id), 'platform_role_assignments_no_self_grant');

        $grant = $this->insertAssignment($target->id, 'platform_auditor', $grantor->id);
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->where('id', $grant)->update(['revoked_at' => now(), 'revoked_by_user_id' => $target->id]), 'platform_role_assignments_no_self_revoke');

        // Other scopes still never enter this table (CLAUDE.md rule 25).
        foreach (['group_admin', 'school_admin'] as $key) {
            $this->assertRejected(fn () => $this->insertAssignment($target->id, $key), 'scope=platform');
        }
    }

    #[Test]
    public function grants_keep_history_one_active_at_a_time_and_cannot_be_deleted_or_reactivated(): void
    {
        $grantor = $this->createUser();
        $target = $this->createUser();
        $first = $this->insertAssignment($target->id, 'platform_auditor', $grantor->id);

        $this->assertRejected(fn () => $this->insertAssignment($target->id, 'platform_auditor', $grantor->id), 'platform_role_assignments_one_active');
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->where('id', $first)->update(['granted_by_user_id' => $this->createUser()->id]), 'the only permitted change is revocation');
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->where('id', $first)->update(['revoked_at' => now()]), 'platform_role_assignments_revocation_check');
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->where('id', $first)->delete(), 'permission denied');

        DB::table('platform_role_assignments')->where('id', $first)->update(['revoked_at' => now(), 'revoked_by_user_id' => $grantor->id]);
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->where('id', $first)->update(['revoked_at' => null, 'revoked_by_user_id' => null]), 'cannot be reactivated');
        $this->assertRejected(fn () => $this->insertAssignment($target->id, 'platform_auditor', $grantor->id, revoked: true), 'already revoked');

        $this->insertAssignment($target->id, 'platform_auditor', $grantor->id);
        $this->assertSame(2, DB::table('platform_role_assignments')->where('user_id', $target->id)->count());
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', $target->id)->whereNull('revoked_at')->count());
    }

    #[Test]
    public function deleting_a_person_or_role_never_erases_grant_history(): void
    {
        $actions = collect(DB::connection('pgsql_admin')->select(
            "select conname, confdeltype from pg_constraint where contype = 'f' and conrelid = 'platform_role_assignments'::regclass",
        ))->pluck('confdeltype', 'conname')->all();

        ksort($actions);
        $this->assertSame([
            'platform_role_assignments_granted_by_user_id_foreign' => 'r',
            'platform_role_assignments_revoked_by_user_id_foreign' => 'r',
            'platform_role_assignments_role_id_foreign' => 'r',
            'platform_role_assignments_user_id_foreign' => 'r',
        ], $actions);

        $total = DB::connection('pgsql_admin')->select("select indexname from pg_indexes where tablename = 'platform_role_assignments' and indexdef like '%UNIQUE%' and indexdef not like '%WHERE%' and indexname <> 'platform_role_assignments_pkey'");
        $this->assertSame([], $total, 'Uniqueness is on active rows only.');
    }

    private function insertAssignment(string $userId, string $roleKey, ?string $grantor = null, bool $revoked = false): string
    {
        $id = (string) Str::uuid7();

        DB::table('platform_role_assignments')->insert([
            'id' => $id,
            'user_id' => $userId,
            'role_id' => $this->roleId($roleKey),
            'granted_by_user_id' => $grantor,
            'granted_at' => now(),
            'revoked_at' => $revoked ? now() : null,
            'revoked_by_user_id' => $revoked ? $grantor : null,
        ]);

        return $id;
    }

    private function assertRejected(callable $statement, string $needle): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Expected the database to reject the statement ({$needle}).");
    }
}
