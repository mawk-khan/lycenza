<?php

namespace Tests\Feature\Postgres;

use App\Domain\Identity\Application\Staff\RoleGrantAuthority;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SR.2 (ADR 0071 §6.2, §11.6, §19): the application grant-authority decision
 * (RoleGrantAuthority) and the SR.1 database grantor trigger agree, case by
 * case. Each case asks the application, then attempts the SAME grant as a raw
 * runtime-role INSERT naming the issuer (inside a savepoint): an application
 * approval is accepted by the database, and an application refusal is refused
 * by the database too -- never a grant the application would refuse.
 */
class StaffRoleGrantParityTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    private School $school;

    private SchoolMembership $target;

    private function systemRole(array $capabilities): Role
    {
        return $this->createFixtureRole($capabilities, key: 'sr2.parity.'.Str::uuid(), isSystem: true);
    }

    /** @return array{0: User, 1: SchoolMembership} */
    private function issuer(School $school, string|Role ...$roles): array
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        foreach ($roles as $role) {
            $this->assignSchoolRole($membership, $role instanceof Role ? $role->key : $role);
        }

        return [$user, $membership];
    }

    private function databaseAccepts(User $issuer, Role $role, ?SchoolMembership $target = null): bool
    {
        $target ??= $this->target;

        try {
            app(TenantContext::class)->withSchool($this->school, fn () => DB::transaction(fn () => DB::table('membership_role_assignments')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'school_membership_id' => $target->id,
                'role_id' => $role->id, 'assigned_by_user_id' => $issuer->id, 'created_at' => now(), 'updated_at' => now(),
            ])));
        } catch (QueryException $e) {
            $this->assertStringContainsString('membership_role_assignments:', $e->getMessage(), 'Refused by the grantor rule, nothing else.');

            return false;
        }

        // Undo the accepted probe so later cases start from the same state.
        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('membership_role_assignments')
            ->where('school_membership_id', $target->id)->where('role_id', $role->id)
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));

        return true;
    }

    private function assertParity(string $case, bool $expected, User $issuer, Role $role, ?SchoolMembership $target = null): void
    {
        $target ??= $this->target;
        $application = app(RoleGrantAuthority::class)->forGrant($issuer, $this->school, $role, $target->user_id)->allowed();

        $this->assertSame($expected, $application, "{$case}: application decision");
        $this->assertSame($application, $this->databaseAccepts($issuer, $role, $target), "{$case}: database agrees with the application");
    }

    #[Test]
    public function the_application_and_the_database_agree_on_every_representative_case(): void
    {
        $this->school = $this->createSchool();
        $this->target = $this->createMembership($this->createUser(), $this->school);
        $role = fn (string $key) => Role::query()->where('key', $key)->sole();

        [$admin, $adminMembership] = $this->issuer($this->school, 'school_admin');
        [$principal] = $this->issuer($this->school, 'principal');
        [$limited] = $this->issuer($this->school, $this->createFixtureRole(['school.members.manage', 'school.roles.manage', 'hr.employees.view']));
        [$rightWithoutManager] = $this->issuer($this->school, $this->createFixtureRole(['school.roles.grant.hr', 'hr.employees.view']));
        [$foreignAdmin] = $this->issuer($this->createSchool(), 'school_admin');
        [$suspended, $suspendedMembership] = $this->issuer($this->school, 'school_admin');
        [$disabled] = $this->issuer($this->school, 'school_admin');
        $guardian = $this->portalGuardian($this->school)['user'];

        app(TenantContext::class)->withSchool($this->school, fn () => $suspendedMembership->update(['status' => SchoolMembership::STATUS_SUSPENDED]));
        $disabled->forceFill(['is_disabled' => true])->save();

        $hr = $this->systemRole(['hr.positions.view', 'hr.employees.view']);
        $hrSensitive = $this->systemRole(['hr.employees.sensitive.view', 'hr.employees.sensitive.manage']);
        $payrollSensitive = $this->systemRole(['payroll.compensation.sensitive.manage', 'payroll.runs.view']);
        $statutory = $this->systemRole(['payroll.statutory.view']);
        $retired = $this->systemRole(['students.view']);
        $this->asCatalogueOwner(fn () => DB::table('roles')->where('id', $retired->id)->update(['retired_at' => now()]));
        $empty = $this->systemRole([]);

        $this->assertParity('ordinary covered grant (school_admin -> teacher)', true, $admin, $role('teacher'));
        $this->assertParity('ordinary uncovered grant', false, $limited, $role('teacher'));
        $this->assertParity('HR via school.roles.grant.hr', true, $admin, $hr);
        $this->assertParity('HR-sensitive via .hr_sensitive', true, $admin, $hrSensitive);
        $this->assertParity('payroll-sensitive via .payroll_sensitive', true, $admin, $payrollSensitive);
        $this->assertParity('missing grant right', false, $limited, $hr);
        $this->assertParity('grant right without school.roles.manage', false, $rightWithoutManager, $hr);
        $this->assertParity('legal-gated statutory key (covered by nobody)', false, $admin, $statutory);
        $this->assertParity('cross-School administrator', false, $foreignAdmin, $role('teacher'));
        $this->assertParity('principal', false, $principal, $role('teacher'));
        $this->assertParity('suspended issuer membership', false, $suspended, $role('teacher'));
        $this->assertParity('disabled issuer', false, $disabled, $role('teacher'));
        $this->assertParity('Guardian-only issuer', false, $guardian, $role('teacher'));
        $this->assertParity('self-grant', false, $admin, $role('teacher'), $adminMembership);
        $this->assertParity('retired role', false, $admin, $retired);
        $this->assertParity('empty role', false, $admin, $empty);
    }
}
