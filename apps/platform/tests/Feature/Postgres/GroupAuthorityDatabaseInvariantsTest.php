<?php

namespace Tests\Feature\Postgres;

use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0N.5 (ADR 0045 sections 3, 8, 9, 13): what the DATABASE enforces
 * for Group authority, independent of application code -- raw SQL as the
 * runtime role, each violation inside its own savepoint.
 */
class GroupAuthorityDatabaseInvariantsTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function exactly_three_role_scopes_exist_and_capabilities_never_cross_them(): void
    {
        $this->assertSame([], DB::table('roles')->whereNotIn('scope', ['platform', 'school', 'group'])->pluck('key')->all());
        $this->assertRejected(fn () => DB::table('roles')->insert(['id' => (string) Str::uuid7(), 'key' => 'x_'.Str::random(6), 'name' => 'X', 'scope' => 'trust', 'is_system' => false]), 'roles_scope_check');

        $this->assertRejected(fn () => DB::table('capabilities')->insert(['key' => 'group.bogus.x', 'label' => 'X', 'namespace' => 'school']), 'capabilities_group_namespace_check');
        $this->assertRejected(fn () => DB::table('capabilities')->insert(['key' => 'students.bogus', 'label' => 'X', 'namespace' => 'group']), 'capabilities_group_namespace_check');

        $groupAdmin = Role::query()->where('key', 'group_admin')->value('id');
        $schoolAdmin = Role::query()->where('key', 'school_admin')->value('id');
        $platform = Role::query()->where('key', 'platform_super_admin')->value('id');

        foreach ([[$groupAdmin, 'students.view'], [$groupAdmin, 'platform.schools.view'], [$schoolAdmin, 'group.schools.view'], [$platform, 'group.schools.elevate']] as [$role, $capability]) {
            $this->assertRejected(fn () => DB::table('role_capabilities')->insert(['role_id' => $role, 'capability_key' => $capability]), 'scopes never mix');
        }

        $this->assertSame(['group.schools.elevate', 'group.schools.view'], DB::table('role_capabilities')->where('role_id', $groupAdmin)->orderBy('capability_key')->pluck('capability_key')->all());
    }

    #[Test]
    public function a_group_role_only_enters_group_grants_and_no_other_scope_enters_them(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $groupId = $this->insertGroup();
        $groupRole = Role::query()->where('key', 'group_admin')->value('id');

        $this->assertRejected(fn () => DB::table('platform_role_assignments')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'role_id' => $groupRole, 'granted_at' => now()]), 'scope=platform');
        $this->assertRejected(fn () => DB::table('membership_role_assignments')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $groupRole, 'created_at' => now(), 'updated_at' => now()]), 'scope=school');

        foreach (['platform_super_admin', 'school_admin'] as $key) {
            $this->assertRejected(fn () => $this->insertGrant($user->id, $groupId, Role::query()->where('key', $key)->value('id')), 'scope=group');
        }
    }

    #[Test]
    public function grants_are_never_self_granted_one_active_per_role_immutable_once_revoked_and_undeletable(): void
    {
        $user = $this->createUser();
        $grantor = $this->createUser();
        $groupId = $this->insertGroup();

        $this->assertRejected(fn () => $this->insertGrant($user->id, $groupId, grantor: $user->id), 'group_role_assignments_no_self_grant');

        $id = $this->insertGrant($user->id, $groupId, grantor: $grantor->id);
        $this->assertRejected(fn () => $this->insertGrant($user->id, $groupId, grantor: $grantor->id), 'group_role_assignments_one_active');
        $this->assertRejected(fn () => DB::table('group_role_assignments')->where('id', $id)->update(['user_id' => $grantor->id]), 'only permitted change is revocation');
        $this->assertRejected(fn () => DB::table('group_role_assignments')->where('id', $id)->update(['revoked_at' => now()]), 'group_role_assignments_revocation_check');

        DB::table('group_role_assignments')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by_user_id' => $grantor->id]);

        $this->assertRejected(fn () => DB::table('group_role_assignments')->where('id', $id)->update(['revoked_at' => null, 'revoked_by_user_id' => null]), 'cannot be reactivated');
        $this->assertRejected(fn () => DB::table('group_role_assignments')->where('id', $id)->delete(), 'permission denied');
        $this->assertRejected(fn () => $this->insertGrant($user->id, $groupId, grantor: $grantor->id, revoked: true), 'already revoked');

        // Re-granting is a new row.
        $this->insertGrant($user->id, $groupId, grantor: $grantor->id);
        $this->assertSame(2, DB::table('group_role_assignments')->where('user_id', $user->id)->count());
    }

    #[Test]
    public function an_archived_group_takes_no_grant_and_a_group_is_never_deleted(): void
    {
        $user = $this->createUser();
        $groupId = $this->insertGroup('archived');

        $this->assertRejected(fn () => $this->insertGrant($user->id, $groupId), 'archived School Group cannot receive a grant');
        $this->assertRejected(fn () => DB::table('school_groups')->insert(['id' => (string) Str::uuid7(), 'name' => 'X', 'slug' => 'x-'.Str::random(6), 'status' => 'dissolved']), 'school_groups_status_check');

        $active = $this->insertGroup();
        DB::table('school_group_members')->insert(['id' => (string) Str::uuid7(), 'school_group_id' => $active, 'school_id' => $this->createSchool()->id]);
        $this->assertRejected(fn () => DB::table('school_groups')->where('id', $active)->delete(), 'permission denied');

        // Even the admin role cannot cascade membership away: RESTRICT.
        $fk = DB::connection('pgsql_admin')->selectOne("select confdeltype from pg_constraint where conname = 'school_group_members_school_group_id_foreign'");
        $this->assertSame('r', $fk->confdeltype);
    }

    #[Test]
    public function a_school_may_be_in_several_groups_but_never_twice_in_one(): void
    {
        $school = $this->createSchool();
        [$a, $b] = [$this->insertGroup(), $this->insertGroup()];

        DB::table('school_group_members')->insert(['id' => (string) Str::uuid7(), 'school_group_id' => $a, 'school_id' => $school->id]);
        DB::table('school_group_members')->insert(['id' => (string) Str::uuid7(), 'school_group_id' => $b, 'school_id' => $school->id]);
        $this->assertRejected(fn () => DB::table('school_group_members')->insert(['id' => (string) Str::uuid7(), 'school_group_id' => $a, 'school_id' => $school->id]), 'unique');

        $uniqueOnSchoolAlone = DB::connection('pgsql_admin')->select(
            "select indexdef from pg_indexes where tablename = 'school_group_members' and indexdef like '%UNIQUE%' and indexdef like '%(school_id)%'",
        );
        $this->assertSame([], $uniqueOnSchoolAlone);
    }

    #[Test]
    public function elevation_authority_is_a_valid_immutable_combination_checked_against_live_group_authority(): void
    {
        $actor = $this->createUser();
        $grantor = $this->createUser();
        $school = $this->createSchool();
        $groupId = $this->insertGroup();
        $otherGroup = $this->insertGroup();
        DB::table('school_group_members')->insert(['id' => (string) Str::uuid7(), 'school_group_id' => $groupId, 'school_id' => $school->id]);
        $grant = $this->insertGrant($actor->id, $groupId, grantor: $grantor->id);

        // Combination CHECK.
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['authority_type' => 'group']), 'school_elevations_authority_check');
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['school_group_id' => $groupId, 'group_role_assignment_id' => $grant]), 'school_elevations_authority_check');
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['authority_type' => 'tenant']), 'school_elevations_authority_check');
        // A grant of a different Group: refused by the INSERT trigger (it runs
        // before the composite FK, which backs it up structurally).
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['authority_type' => 'group', 'school_group_id' => $otherGroup, 'group_role_assignment_id' => $grant]), 'not an active grant of this actor');
        $fk = DB::connection('pgsql_admin')->selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'school_elevations_group_grant_fk'");
        $this->assertStringContainsString('FOREIGN KEY (group_role_assignment_id, school_group_id) REFERENCES group_role_assignments(id, school_group_id)', $fk->d);
        // Someone else's grant.
        $this->assertRejected(fn () => $this->insertElevation($grantor->id, $school->id, ['authority_type' => 'group', 'school_group_id' => $groupId, 'group_role_assignment_id' => $grant]), 'not an active grant of this actor');
        // A School outside the Group.
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $this->createSchool()->id, ['authority_type' => 'group', 'school_group_id' => $groupId, 'group_role_assignment_id' => $grant]), 'not a member of the authorizing School Group');

        $id = $this->insertElevation($actor->id, $school->id, ['authority_type' => 'group', 'school_group_id' => $groupId, 'group_role_assignment_id' => $grant]);

        foreach ([['authority_type' => 'platform', 'school_group_id' => null, 'group_role_assignment_id' => null], ['school_group_id' => $otherGroup]] as $change) {
            $this->assertRejected(fn () => DB::table('school_elevations')->where('id', $id)->update($change + ['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'exited']), 'school_elevations_');
        }

        foreach (['school_left_group', 'group_authority_revoked', 'group_inactive'] as $reason) {
            $this->assertTrue(str_contains(DB::connection('pgsql_admin')->selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'school_elevations_end_check'")->d, $reason));
        }

        // A revoked grant or an archived Group admits no new Group-derived row.
        DB::table('school_elevations')->where('id', $id)->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'exited']);
        DB::table('group_role_assignments')->where('id', $grant)->update(['revoked_at' => now(), 'revoked_by_user_id' => $grantor->id]);
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['authority_type' => 'group', 'school_group_id' => $groupId, 'group_role_assignment_id' => $grant]), 'not an active grant of this actor');

        $grant2 = $this->insertGrant($actor->id, $groupId, grantor: $grantor->id);
        DB::table('school_groups')->where('id', $groupId)->update(['status' => 'archived']);
        $this->assertRejected(fn () => $this->insertElevation($actor->id, $school->id, ['authority_type' => 'group', 'school_group_id' => $groupId, 'group_role_assignment_id' => $grant2]), 'School Group is not active');

        // History keeps its references: every FK from an elevation or a grant
        // to its Group, grant, School or people RESTRICTs deletion.
        $actions = collect(DB::connection('pgsql_admin')->select(
            "select conname, confdeltype from pg_constraint where contype = 'f' and conrelid in ('school_elevations'::regclass, 'group_role_assignments'::regclass)",
        ))->pluck('confdeltype', 'conname');
        $this->assertSame('r', $actions['school_elevations_group_grant_fk']);
        $this->assertSame('r', $actions['school_elevations_school_group_id_foreign']);
        $this->assertSame(['r'], $actions->filter(fn ($v, $k) => str_starts_with($k, 'group_role_assignments_'))->unique()->values()->all());
    }

    private function insertGroup(string $status = 'active'): string
    {
        $id = (string) Str::uuid7();
        DB::table('school_groups')->insert(['id' => $id, 'name' => 'Trust', 'slug' => 'trust-'.Str::lower(Str::random(10)), 'status' => $status]);

        return $id;
    }

    private function insertGrant(string $userId, string $groupId, ?string $roleId = null, ?string $grantor = null, bool $revoked = false): string
    {
        $id = (string) Str::uuid7();
        $grantor ??= $this->createUser()->id;

        DB::table('group_role_assignments')->insert([
            'id' => $id,
            'user_id' => $userId,
            'school_group_id' => $groupId,
            'role_id' => $roleId ?? Role::query()->where('key', 'group_admin')->value('id'),
            'granted_by_user_id' => $grantor,
            'granted_at' => now(),
            'revoked_at' => $revoked ? now() : null,
            'revoked_by_user_id' => $revoked ? $grantor : null,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertElevation(string $actorId, string $schoolId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        $now = now()->startOfSecond();

        DB::table('school_elevations')->insert(array_merge([
            'id' => $id, 'actor_user_id' => $actorId, 'school_id' => $schoolId,
            'reason_code' => 'operational_support', 'status' => 'active',
            'started_at' => $now, 'expires_at' => $now->copy()->addMinutes(30),
        ], $overrides));

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
