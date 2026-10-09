<?php

namespace Tests\Feature\Postgres;

use App\Domain\Identity\Application\AccountLinkService;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * POR.1 (ADR 0070 §8.2): what the DATABASE enforces for the `guardian`
 * scope, independent of application code -- raw SQL as the runtime role,
 * each violation inside its own savepoint.
 */
class GuardianScopeDatabaseInvariantsTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function grantRow(string $schoolId, string $membershipId, string $roleId): array
    {
        return ['id' => (string) Str::uuid7(), 'school_id' => $schoolId, 'school_membership_id' => $membershipId, 'role_id' => $roleId, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }

    #[Test]
    public function portal_capabilities_and_staff_capabilities_never_mix(): void
    {
        $guardian = Role::query()->where('key', Role::GUARDIAN)->value('id');
        $schoolAdmin = Role::query()->where('key', 'school_admin')->value('id');
        $groupAdmin = Role::query()->where('key', 'group_admin')->value('id');
        $platform = Role::query()->where('key', 'platform_super_admin')->value('id');

        foreach ([[$guardian, 'students.view'], [$guardian, 'communications.view'], [$guardian, 'group.schools.view'], [$guardian, 'platform.schools.view'],
            [$schoolAdmin, 'portal.communications.view'], [$schoolAdmin, 'portal.attendance.view'], [$groupAdmin, 'portal.communications.view'], [$platform, 'portal.communications.view']] as [$role, $capability]) {
            $this->assertRejected(fn () => DB::table('role_capabilities')->insert(['role_id' => $role, 'capability_key' => $capability]), 'scopes never mix');
        }

        $this->assertRejected(fn () => DB::table('capabilities')->insert(['key' => 'portal.bogus', 'label' => 'X', 'namespace' => 'school']), 'capabilities_guardian_namespace_check');
        $this->assertRejected(fn () => DB::table('capabilities')->insert(['key' => 'students.bogus', 'label' => 'X', 'namespace' => 'guardian']), 'capabilities_guardian_namespace_check');
        $this->assertRejected(fn () => DB::table('roles')->insert(['id' => (string) Str::uuid7(), 'key' => 'x_'.Str::random(6), 'name' => 'X', 'scope' => 'parent', 'is_system' => false]), 'roles_scope_check');
    }

    #[Test]
    public function a_guardian_grant_needs_an_active_guardian_link_on_the_same_membership(): void
    {
        $school = $this->createSchool();
        $guardianRole = Role::query()->where('key', Role::GUARDIAN)->value('id');
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->setSchool($school->id);

        // No link at all.
        $this->assertRejected(fn () => DB::table('membership_role_assignments')->insert($this->grantRow($school->id, $membership->id, $guardianRole)), 'active Guardian account link');

        // A Student link is not a Guardian link.
        $student = $this->createStudent($school);
        app(AccountLinkService::class)->linkStudent($school, $student, $membership, $this->portalAdmin($school));
        $this->setSchool($school->id);
        $this->assertRejected(fn () => DB::table('membership_role_assignments')->insert($this->grantRow($school->id, $membership->id, $guardianRole)), 'active Guardian account link');

        // Another School: the composite membership/School key refuses it.
        $p = $this->portalGuardian($school);
        $other = $this->createSchool();
        $this->setSchool($other->id);
        $this->assertRejected(fn () => DB::table('membership_role_assignments')->insert($this->grantRow($other->id, $p['membership']->id, $guardianRole)), 'membership_role_assignments');

        // A group or platform role never enters a membership grant.
        $this->setSchool($school->id);
        foreach (['group_admin', 'platform_super_admin'] as $key) {
            $this->assertRejected(fn () => DB::table('membership_role_assignments')->insert($this->grantRow($school->id, $p['membership']->id, Role::query()->where('key', $key)->value('id'))), 'scope=school or scope=guardian');
        }
    }

    #[Test]
    public function an_active_guardian_link_cannot_end_while_its_portal_grant_is_active(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->setSchool($school->id);

        $this->assertRejected(fn () => DB::table('student_guardian_account_links')->where('id', $p['link']->id)->update(['status' => 'revoked', 'unlinked_at' => now()]), 'revoke the guardian-scope role grant');
        $this->assertRejected(fn () => DB::table('student_guardian_account_links')->where('id', $p['link']->id)->update(['school_membership_id' => $this->createMembership($this->createUser(), $school)->id]), 'revoke the guardian-scope role grant');

        // Grant first, then the link: allowed (the application order).
        DB::transaction(function () use ($p) {
            DB::table('membership_role_assignments')->where('school_membership_id', $p['membership']->id)
                ->whereIn('role_id', Role::query()->where('scope', 'guardian')->select('id'))
                ->update(['revoked_at' => now(), 'revocation_reason' => 'guardian_link_revoked']);
            DB::table('student_guardian_account_links')->where('id', $p['link']->id)->update(['status' => 'revoked', 'unlinked_at' => now()]);
        });
        $this->assertSame('revoked', app(TenantContext::class)->withSchool($school, fn () => DB::table('student_guardian_account_links')->where('id', $p['link']->id)->value('status')));
    }

    #[Test]
    public function the_revocation_reasons_are_closed(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->setSchool($school->id);

        $this->assertRejected(fn () => DB::table('membership_role_assignments')->where('school_membership_id', $p['membership']->id)
            ->update(['revoked_at' => now(), 'revocation_reason' => 'forgotten']), 'membership_role_assignments_revocation_check');
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
