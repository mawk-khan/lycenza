<?php

namespace Tests\Feature\Postgres;

use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SR.1 (ADR 0071 §11): the staff role catalogue's database foundation, proven
 * with raw SQL against the real runtime role -- catalogue writes refused, grant
 * history undeletable, role identity immutable, retirement, empty roles, the
 * staff catalogue filter, and the database grantor-coverage rule with its two
 * narrow exceptions (the administrative boundary; the ADR 0047 bootstrap).
 */
class StaffRoleCatalogueHardeningTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    private function rejected(callable $statement, string $needle): void
    {
        try {
            DB::transaction(fn () => $statement());
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Expected the database to refuse the statement ({$needle}).");
    }

    private function role(string $key): Role
    {
        return Role::query()->where('key', $key)->firstOrFail();
    }

    /** A raw runtime grant naming $issuer as the assigning user. */
    private function grant(School $school, SchoolMembership $target, Role $role, ?User $issuer): void
    {
        app(TenantContext::class)->withSchool($school, fn () => DB::table('membership_role_assignments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $target->id,
            'role_id' => $role->id, 'assigned_by_user_id' => $issuer?->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function grantCount(School $school, SchoolMembership $target, Role $role): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('membership_role_assignments')
            ->where('school_membership_id', $target->id)->where('role_id', $role->id)->count());
    }

    /** @return array{0: User, 1: SchoolMembership} an issuer holding exactly `school_admin` in $school */
    private function schoolAdmin(School $school): array
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');

        return [$user, $membership];
    }

    #[Test]
    public function the_runtime_role_cannot_write_the_catalogue_but_the_administrative_role_can(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $teacher = $this->role('teacher');

        $this->rejected(fn () => DB::table('roles')->insert(['id' => (string) Str::uuid7(), 'key' => 'x_'.Str::random(6), 'name' => 'X', 'scope' => 'school', 'is_system' => false]), 'permission denied for table roles');
        $this->rejected(fn () => DB::table('roles')->where('id', $teacher->id)->update(['name' => 'Renamed']), 'permission denied for table roles');
        $this->rejected(fn () => DB::table('roles')->where('id', $teacher->id)->delete(), 'permission denied for table roles');
        $this->rejected(fn () => DB::statement('TRUNCATE roles CASCADE'), 'permission denied for table roles');
        $this->rejected(fn () => DB::table('role_capabilities')->insert(['role_id' => $teacher->id, 'capability_key' => 'students.view']), 'permission denied for table role_capabilities');
        $this->rejected(fn () => DB::table('role_capabilities')->where('role_id', $teacher->id)->delete(), 'permission denied for table role_capabilities');
        $this->rejected(fn () => DB::table('role_capabilities')->where('role_id', $teacher->id)->update(['capability_key' => 'students.view']), 'permission denied for table role_capabilities');
        $this->rejected(fn () => DB::table('capabilities')->insert(['key' => 'students.bogus', 'label' => 'X', 'namespace' => 'school']), 'permission denied for table capabilities');
        $this->rejected(fn () => DB::table('capabilities')->where('key', 'students.view')->update(['label' => 'X']), 'permission denied for table capabilities');
        $this->rejected(fn () => DB::table('capabilities')->where('key', 'students.view')->delete(), 'permission denied for table capabilities');

        // Reads stay available to the runtime role.
        $this->assertGreaterThan(0, DB::table('role_capabilities')->where('role_id', $teacher->id)->count());

        // The migration/admin role keeps catalogue maintenance.
        $admin = (string) DB::connection('pgsql_admin')->selectOne('select current_user as u')->u;
        foreach (['roles', 'role_capabilities', 'capabilities'] as $table) {
            $this->assertTrue((bool) DB::selectOne('select has_table_privilege(?, ?, ?) as p', [$admin, $table, 'INSERT,UPDATE,DELETE'])->p, $table);
        }
        $this->assertSame(['students.view'], LocalCatalogueFixtures::createRole(['students.view'])->capabilities()->pluck('key')->all());
    }

    #[Test]
    public function a_role_with_grant_history_can_never_be_deleted(): void
    {
        $school = $this->createSchool();
        $active = $this->createMembership($this->createUser(), $school);
        $revoked = $this->createMembership($this->createUser(), $school);
        $current = LocalCatalogueFixtures::createRole(['students.view']);
        $historical = LocalCatalogueFixtures::createRole(['students.view']);
        LocalCatalogueFixtures::grantRole($active, $current);
        $grant = LocalCatalogueFixtures::grantRole($revoked, $historical);
        app(TenantContext::class)->withSchool($school, fn () => $grant->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));

        foreach ([$current, $historical] as $role) {
            $this->rejected(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $role->id)->delete()), 'membership_role_assignments_role_id_foreign');
            $this->rejected(fn () => DB::table('roles')->where('id', $role->id)->delete(), 'permission denied for table roles');
        }
        $this->assertSame(1, $this->grantCount($school, $active, $current));
        $this->assertSame(1, $this->grantCount($school, $revoked, $historical));

        // A role with no grants at all may still be removed by the administrative role.
        $unused = LocalCatalogueFixtures::createRole(['students.view']);
        LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $unused->id)->delete());
        $this->assertNull(Role::query()->find($unused->id));
    }

    #[Test]
    public function role_identity_is_permanent_and_retirement_is_one_way(): void
    {
        $role = LocalCatalogueFixtures::createRole(['students.view']);

        $this->rejected(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $role->id)->update(['key' => 'renamed_'.Str::random(5)])), 'key, scope and is_system are permanent');
        $this->rejected(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $role->id)->update(['scope' => 'group'])), 'key, scope and is_system are permanent');
        $this->rejected(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $role->id)->update(['is_system' => true])), 'key, scope and is_system are permanent');

        // Display name and retirement are the mutable lifecycle fields.
        LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $role->id)->update(['name' => 'Renamed label', 'retired_at' => now()]));
        $this->assertNotNull($role->fresh()->retired_at);
        $this->rejected(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $role->id)->update(['retired_at' => null])), 'retirement is permanent');
    }

    #[Test]
    public function a_retired_or_empty_role_receives_no_new_grant_but_an_active_grant_keeps_its_meaning(): void
    {
        $school = $this->createSchool();
        [$admin] = $this->schoolAdmin($school);
        $holder = $this->createMembership($this->createUser(), $school);
        $later = $this->createMembership($this->createUser(), $school);
        $role = LocalCatalogueFixtures::createRole(['students.view']);
        LocalCatalogueFixtures::grantRole($holder, $role);
        LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $role->id)->update(['retired_at' => now()]));

        $this->rejected(fn () => $this->grant($school, $later, $role, $admin), 'is retired and cannot be granted');
        $this->rejected(fn () => LocalCatalogueFixtures::grantRole($later, $role), 'is retired and cannot be granted');

        // Retirement stops NEW grants only; the existing grant stays active and authorizes until revoked.
        $this->assertTrue(app(CapabilityResolver::class)->canInSchool(User::query()->findOrFail($holder->user_id), 'students.view', $school));

        $empty = LocalCatalogueFixtures::createRole([]);
        $this->rejected(fn () => $this->grant($school, $later, $empty, $admin), 'has no capabilities and cannot be granted');
        $this->rejected(fn () => LocalCatalogueFixtures::grantRole($later, $empty), 'has no capabilities and cannot be granted');
    }

    #[Test]
    public function the_staff_catalogue_offers_only_active_system_school_roles_with_capabilities(): void
    {
        $school = $this->createSchool();
        [$admin] = $this->schoolAdmin($school);
        $retired = LocalCatalogueFixtures::createRole(['students.view'], 'school', 'test_retired_system_'.Str::random(5), 'Retired', true);
        LocalCatalogueFixtures::asOwner(fn () => DB::table('roles')->where('id', $retired->id)->update(['retired_at' => now()]));
        $empty = LocalCatalogueFixtures::createRole([], 'school', 'test_empty_system_'.Str::random(5), 'Empty', true);
        $custom = LocalCatalogueFixtures::createRole(['students.view']);

        $keys = collect(app(StaffRoleCatalog::class)->catalogFor($admin, $school))->pluck('key');

        $this->assertEqualsCanonicalizing(['principal', 'school_admin', 'staff_self_service', 'teacher'], $keys->all());
        foreach ([$retired->key, $empty->key, $custom->key, 'guardian', 'group_admin', 'platform_super_admin'] as $excluded) {
            $this->assertNotContains($excluded, $keys->all());
        }
    }

    #[Test]
    public function a_runtime_grant_needs_a_covering_issuer_in_the_same_school(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        [$admin, $adminMembership] = $this->schoolAdmin($school);
        $target = $this->createMembership($this->createUser(), $school);
        $teacher = $this->role('teacher');
        $principal = $this->role('principal');

        // An ordinary, covered grant succeeds.
        $this->grant($school, $target, $teacher, $admin);
        $this->assertSame(1, $this->grantCount($school, $target, $teacher));

        // Uncovered: role management but not the role's capabilities.
        $narrow = $this->createUserWithCapabilities($school, ['school.roles.manage', 'school.members.manage', 'students.view']);
        $this->rejected(fn () => $this->grant($school, $target, $principal, $narrow), 'does not hold or cover every capability of role principal');

        // Covering capabilities without role management: refused.
        $covering = $this->createUserWithCapabilities($school, ['curriculum.delivery.teacher', 'attendance.teacher', 'lms.content.teacher', 'lms.assignments.teacher', 'examinations.marks.teacher']);
        $fresh = $this->createMembership($this->createUser(), $school);
        $this->rejected(fn () => $this->grant($school, $fresh, $teacher, $covering), 'does not hold school.roles.manage');

        // Another School's administrator, no assigner, oneself: refused.
        [$foreignAdmin] = $this->schoolAdmin($other);
        $this->rejected(fn () => $this->grant($school, $fresh, $teacher, $foreignAdmin), 'has no active membership in this School');
        $this->rejected(fn () => $this->grant($school, $fresh, $teacher, null), 'must name its assigning user');
        $this->rejected(fn () => $this->grant($school, $adminMembership, $teacher, $admin), 'cannot grant a role to themselves');
    }

    #[Test]
    public function a_suspended_disabled_or_revoked_issuer_or_a_guardian_identity_grants_nothing(): void
    {
        $school = $this->createSchool();
        $teacher = $this->role('teacher');
        $target = $this->createMembership($this->createUser(), $school);

        [$suspended, $suspendedMembership] = $this->schoolAdmin($school);
        DB::table('school_memberships')->where('id', $suspendedMembership->id)->update(['status' => 'suspended']);
        $this->rejected(fn () => $this->grant($school, $target, $teacher, $suspended), 'has no active membership in this School');

        [$disabled] = $this->schoolAdmin($school);
        DB::table('users')->where('id', $disabled->id)->update(['is_disabled' => true, 'disabled_at' => now()]);
        $this->rejected(fn () => $this->grant($school, $target, $teacher, $disabled), 'unknown or disabled');

        [$revoked, $revokedMembership] = $this->schoolAdmin($school);
        app(TenantContext::class)->withSchool($school, fn () => DB::table('membership_role_assignments')
            ->where('school_membership_id', $revokedMembership->id)->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->rejected(fn () => $this->grant($school, $target, $teacher, $revoked), 'does not hold school.roles.manage');

        // A Guardian-scope grant (its only one) never counts as School-staff authority.
        $guardianUser = $this->portalGuardian($school)['user'];
        $this->rejected(fn () => $this->grant($school, $target, $teacher, $guardianUser), 'does not hold school.roles.manage');

        $this->assertSame(0, $this->grantCount($school, $target, $teacher));
    }

    #[Test]
    public function a_grant_right_covers_only_what_it_names_and_never_role_management_itself(): void
    {
        $school = $this->createSchool();
        $target = $this->createMembership($this->createUser(), $school);
        LocalCatalogueFixtures::asOwner(function (): void {
            DB::table('capabilities')->insert(['key' => 'school.sr1_test.grant', 'label' => 'Test grant right', 'namespace' => 'school']);
            DB::table('capabilities')->insert(['key' => 'students.sr1_protected', 'label' => 'Test protected', 'namespace' => 'school', 'grant_right' => 'school.sr1_test.grant']);
        });
        $protected = LocalCatalogueFixtures::createRole(['students.sr1_protected']);

        $withRight = $this->createUserWithCapabilities($school, ['school.roles.manage', 'school.sr1_test.grant']);
        $withoutRight = $this->createUserWithCapabilities($school, ['school.roles.manage']);
        $this->rejected(fn () => $this->grant($school, $target, $protected, $withoutRight), 'does not hold or cover every capability');
        $this->grant($school, $target, $protected, $withRight);
        $this->assertSame(1, $this->grantCount($school, $target, $protected));

        // A grant right is never itself covered by another grant right.
        $this->rejected(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('capabilities')->where('key', 'school.sr1_test.grant')->update(['grant_right' => 'students.view'])), 'never itself covered');
        $this->rejected(fn () => LocalCatalogueFixtures::asOwner(fn () => DB::table('capabilities')->where('key', 'students.view')->update(['grant_right' => 'students.view'])), 'capabilities_grant_right_not_self');

        // Role management must be HELD: a grant right mapped over it never substitutes.
        LocalCatalogueFixtures::asOwner(fn () => DB::table('capabilities')->where('key', 'school.roles.manage')->update(['grant_right' => 'school.sr1_test.grant']));
        $rightOnly = $this->createUserWithCapabilities($school, ['school.sr1_test.grant']);
        $fresh = $this->createMembership($this->createUser(), $school);
        $this->rejected(fn () => $this->grant($school, $fresh, $protected, $rightOnly), 'does not hold school.roles.manage');
    }

    #[Test]
    public function the_adr_0047_bootstrap_exception_is_exactly_the_first_school_admin_of_a_provisioning_school(): void
    {
        $root = $this->createPlatformRoot();
        $provisioning = School::factory()->provisioning()->create();
        $active = $this->createSchool();
        $schoolAdmin = $this->role('school_admin');

        $first = $this->createMembership($this->createUser(), $provisioning);
        $this->grant($provisioning, $first, $schoolAdmin, $root);
        $this->assertSame(1, $this->grantCount($provisioning, $first, $schoolAdmin));

        // Not another role, not after provisioning, not without platform.schools.manage.
        $second = $this->createMembership($this->createUser(), $provisioning);
        $this->rejected(fn () => $this->grant($provisioning, $second, $this->role('principal'), $root), 'has no active membership in this School');
        $activeTarget = $this->createMembership($this->createUser(), $active);
        $this->rejected(fn () => $this->grant($active, $activeTarget, $schoolAdmin, $root), 'has no active membership in this School');

        $auditor = $this->createUser();
        $this->assignPlatformRole($auditor, 'platform_auditor');
        $this->rejected(fn () => $this->grant($provisioning, $second, $schoolAdmin, $auditor), 'has no active membership in this School');
        $this->rejected(fn () => $this->grant($provisioning, $second, $schoolAdmin, $this->createUser()), 'has no active membership in this School');
    }

    #[Test]
    public function guardian_grants_keep_their_own_link_rule_and_other_scopes_stay_refused(): void
    {
        $school = $this->createSchool();
        $membership = $this->createMembership($this->createUser(), $school);
        [$admin] = $this->schoolAdmin($school);

        $this->rejected(fn () => $this->grant($school, $membership, $this->role(Role::GUARDIAN), $admin), 'needs an active Guardian account link');
        $this->rejected(fn () => $this->grant($school, $membership, $this->role('platform_super_admin'), $admin), 'scope=school or scope=guardian');
        $this->rejected(fn () => $this->grant($school, $membership, $this->role('group_admin'), $admin), 'scope=school or scope=guardian');
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->where('school_membership_id', $membership->id)->count()));
    }

    #[Test]
    public function the_fixture_seam_is_development_only_and_no_application_code_uses_it(): void
    {
        // The verifier passes here (testing) and fails the same database outside local/testing.
        $check = fn () => collect(app(DatabaseRoleVerifier::class)->verify())->firstWhere('code', 'local_fixture_seam_absent_outside_development');
        $this->assertSame(CheckResult::PASS, $check()->status);
        $this->app['env'] = 'production';
        try {
            $this->assertSame(CheckResult::FAIL, $check()->status);
            $this->assertThrows(fn () => LocalCatalogueFixtures::createRole(['students.view']), \LogicException::class);
        } finally {
            $this->app['env'] = 'testing';
        }

        // Only the seam itself, its install command, the guarded demo builder -- and the
        // verifier, which names the schema to prove it absent -- refer to it.
        $allowed = ['app/Support/Testing/LocalCatalogueFixtures.php', 'app/Console/Commands/InstallLocalCatalogueFixtures.php', 'database/seeders/Demo/DemoDataBuilder.php', 'app/Support/Operations/DatabaseRoleVerifier.php'];
        foreach (['app', 'database', 'routes', 'config'] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace(base_path().'/', '', $file->getPathname());
                if (str_contains((string) file_get_contents($file->getPathname()), 'LocalCatalogueFixtures') || str_contains((string) file_get_contents($file->getPathname()), 'local_fixtures')) {
                    $this->assertContains($relative, $allowed, "{$relative} must not use the local/testing fixture seam.");
                }
            }
        }
    }
}
