<?php

namespace Tests\Feature\Postgres;

use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.1A (ADR 0046 section 2): root platform authority is provisioned
 * only across the trusted administrative database boundary. Proven with raw
 * SQL on the REAL runtime PostgreSQL role (the default `pgsql` connection,
 * asserted below to be that role and not a member of the table owner), each
 * refusal inside its own savepoint.
 */
class PlatformRootBoundaryTest extends TestCase
{
    use CreatesTenancyFixtures;

    public const REFUSAL = 'only the trusted administrative database role may provision';

    private function roleId(string $key): string
    {
        return (string) DB::table('roles')->where('key', $key)->value('id');
    }

    #[Test]
    public function the_runtime_role_cannot_insert_a_root_assignment_by_raw_sql(): void
    {
        $this->assertSame(config('database.connections.pgsql.username'), DB::selectOne('select current_user as u')->u);

        $target = $this->createUser();

        $this->assertRejected(fn () => DB::statement(
            'insert into platform_role_assignments (id, user_id, role_id, granted_by_user_id, granted_at, created_at, updated_at) values (?, ?, ?, null, now(), now(), now())',
            [(string) Str::uuid7(), $target->id, $this->roleId('platform_super_admin')],
        ), self::REFUSAL);

        $this->assertSame(0, DB::table('platform_role_assignments')->where('user_id', $target->id)->count());
    }

    #[Test]
    public function the_runtime_role_cannot_mint_root_by_eloquent_query_builder_or_a_forged_grantor(): void
    {
        $target = $this->createUser();
        $forged = $this->createUser();
        $root = $this->roleId('platform_super_admin');

        $this->assertRejected(fn () => PlatformRoleAssignment::query()->create(['user_id' => $target->id, 'role_id' => $root]), self::REFUSAL);
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $target->id, 'role_id' => $root, 'granted_at' => now(),
        ]), self::REFUSAL);

        // A forged grantor never makes the root role grantable.
        $this->assertRejected(fn () => PlatformRoleAssignment::query()->create([
            'user_id' => $target->id, 'role_id' => $root, 'granted_by_user_id' => $forged->id, 'granted_at' => now(),
        ]), 'only a runtime-assignable role can be granted at runtime');

        // Nor may the runtime role take the out-of-band path for any other platform role.
        $this->assertRejected(fn () => DB::table('platform_role_assignments')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $target->id, 'role_id' => $this->roleId('platform_auditor'), 'granted_at' => now(),
        ]), self::REFUSAL);

        $this->assertSame(0, DB::table('platform_role_assignments')->where('user_id', $target->id)->count());
    }

    #[Test]
    public function the_runtime_role_holds_no_authority_that_could_lift_the_boundary(): void
    {
        $runtime = (string) config('database.connections.pgsql.username');
        $this->assertSame($runtime, DB::selectOne('select current_user as u')->u);

        $attributes = DB::connection('pgsql_admin')->selectOne(
            "select r.rolsuper, r.rolbypassrls, pg_has_role(r.rolname, c.relowner, 'USAGE') as owner_privileges, pg_has_role(r.rolname, c.relowner, 'MEMBER') as owner_member
             from pg_roles r, pg_class c where r.rolname = ? and c.oid = 'platform_role_assignments'::regclass",
            [$runtime],
        );
        $this->assertFalse((bool) $attributes->rolsuper);
        $this->assertFalse((bool) $attributes->rolbypassrls);
        $this->assertFalse((bool) $attributes->owner_privileges, 'The runtime role must never hold the table owner\'s privileges.');
        $this->assertFalse((bool) $attributes->owner_member);

        // It can neither switch to the owner nor switch the trigger off.
        $owner = DB::connection('pgsql_admin')->selectOne("select pg_get_userbyid(relowner) as o from pg_class where oid = 'platform_role_assignments'::regclass")->o;
        $this->assertRejected(fn () => DB::statement('set local role '.DB::getPdo()->quote($owner)), 'permission denied');
        $this->assertRejected(fn () => DB::statement('alter table platform_role_assignments disable trigger trg_platform_role_assignments_governance'), 'must be owner');
        $this->assertRejected(fn () => DB::statement("set local session_replication_role = 'replica'"), 'permission denied');

        $enabled = DB::connection('pgsql_admin')->selectOne("select tgenabled from pg_trigger where tgname = 'trg_platform_role_assignments_governance'")->tgenabled;
        $this->assertSame('O', $enabled, 'The governance trigger stays enabled for every session.');
    }

    #[Test]
    public function the_approved_auditor_grant_still_works_on_the_runtime_role(): void
    {
        $root = $this->createPlatformRoot();
        $target = $this->createUser();

        $grant = app(PlatformRoleGovernanceService::class)->grant($root, $target->email, 'platform_auditor');

        $this->assertSame($root->id, $grant->granted_by_user_id);
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', $target->id)->whereNull('revoked_at')->count());
        $this->assertFalse((bool) DB::table('roles')->where('key', 'platform_super_admin')->value('runtime_assignable'));
    }

    #[Test]
    public function the_administrative_role_provisions_root_and_is_still_bound_by_the_other_rules(): void
    {
        // createPlatformRoot() writes the grantor-less root grant on pgsql_admin.
        $root = $this->createPlatformRoot();
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', $root->id)->where('role_id', $this->roleId('platform_super_admin'))->whereNull('granted_by_user_id')->count());

        $admin = DB::connection('pgsql_admin');
        $other = User::factory()->connection('pgsql_admin')->create();

        // Even the administrative role cannot "grant" root with a grantor, nor create it revoked.
        foreach ([['granted_by_user_id' => $root->id], ['revoked_at' => now(), 'revoked_by_user_id' => $root->id]] as $extra) {
            try {
                $admin->transaction(fn () => $admin->table('platform_role_assignments')->insert([
                    'id' => (string) Str::uuid7(), 'user_id' => $other->id, 'role_id' => $this->roleId('platform_super_admin'), 'granted_at' => now(), ...$extra,
                ]));
                $this->fail('The administrative role is still bound by the governance rules.');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/only a runtime-assignable role can be granted at runtime|cannot be created already revoked/', $e->getMessage());
            }
        }

        $admin->table('users')->where('id', $other->id)->delete();
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
