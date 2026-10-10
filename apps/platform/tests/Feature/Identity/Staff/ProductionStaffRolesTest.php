<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\Identity\Application\Staff\RoleGrantAuthority;
use App\Domain\Identity\Application\Staff\RoleGrantRefusalAudit;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * SR.3 (ADR 0071 §4, §10, §15, §17, §20): the thirteen production staff roles
 * through the REAL SR.2 grant rule (no test-only capability grants for the
 * issuer): who can grant each, the grant right each needs, additive
 * combinations, multi-School isolation, the HR identity substrate, and how
 * Settings -> Staff accounts presents the fixed catalogue.
 */
class ProductionStaffRolesTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail, StaffAccountTestHelpers;

    /** role => the grant right school_admin uses for it (none = held directly). */
    private const ROLES = [
        'hr_officer' => ['school.roles.grant.hr'],
        'hr_sensitive_records' => ['school.roles.grant.hr_sensitive'],
        'payroll_officer' => ['school.roles.grant.payroll_sensitive'],
        'accountant' => [],
        'cashier' => [],
        'librarian' => [],
        'transport_coordinator' => [],
        'hostel_warden' => [],
        'front_office' => [],
        'stores_officer' => [],
        'canteen_operator' => [],
        'admissions_officer' => [],
        'communications_coordinator' => [],
    ];

    private function access(): StaffAccessService
    {
        return app(StaffAccessService::class);
    }

    /** @return list<string> */
    private function capabilities(User $user, School $school): array
    {
        app(CapabilityResolver::class)->forgetCache($user, $school);
        $capabilities = app(CapabilityResolver::class)->schoolCapabilities($user, $school);
        sort($capabilities);

        return $capabilities;
    }

    /** @return list<string> */
    private function roleCapabilities(string ...$keys): array
    {
        $capabilities = Role::query()->whereIn('key', $keys)->with('capabilities')->get()
            ->flatMap(fn (Role $role) => $role->capabilities->pluck('key'))->unique()->sort()->values()->all();

        return $capabilities;
    }

    private function hrRefusal(callable $operation): string
    {
        try {
            $operation();
        } catch (HrException $e) {
            return $e->errorCode();
        }

        return 'allowed';
    }

    private function refusal(callable $operation): string
    {
        try {
            $operation();
        } catch (StaffAccountException $e) {
            return $e->outcome;
        }

        return 'allowed';
    }

    #[Test]
    public function school_admin_grants_every_production_role_through_the_real_rule_and_audits_the_grant_right_used(): void
    {
        [$admin, $school] = $this->staffAdmin();

        foreach (self::ROLES as $roleKey => $rights) {
            [$member, $membership] = $this->staffMember($school, 'staff_self_service');
            $this->access()->grantRole($school, $admin, $membership->id, $roleKey);

            $this->assertSame(collect(['staff_self_service', $roleKey])->sort()->values()->all(), $this->activeRoles($membership), $roleKey);
            $assigned = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
                ->where('event_type', StaffAccessService::ROLE_ASSIGNED)->where('school_id', $school->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail()->metadata);
            $this->assertSame($roleKey, $assigned['roleKey']);
            $this->assertSame($rights, $assigned['grantRights'] ?? [], "{$roleKey}: the grant right(s) used are audited, and only when used.");
            $this->assertSame($this->roleCapabilities('staff_self_service', $roleKey), $this->capabilities($member, $school), "{$roleKey}: exactly its capabilities, additively.");
        }

        // Granting conferred nothing on the issuer: school_admin still holds none of the 19 covered keys.
        $held = $this->capabilities($admin, $school);
        foreach (['hr.positions.view', 'hr.employees.sensitive.view', 'payroll.compensation.sensitive.view'] as $covered) {
            $this->assertNotContains($covered, $held);
        }
    }

    #[Test]
    public function principal_cross_school_guardian_only_self_and_retired_issuers_cannot_grant_a_production_role(): void
    {
        [$admin, $school, $adminMembership] = $this->staffAdmin();
        [$principal] = $this->staffMember($school, 'principal');
        [, $target] = $this->staffMember($school, 'staff_self_service');
        [$foreignAdmin] = $this->staffAdmin();
        $guardian = $this->portalGuardian($school)['user'];
        $authority = app(RoleGrantAuthority::class);

        foreach (array_keys(self::ROLES) as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->sole();
            $this->assertSame('not_authorized', $this->refusal(fn () => $this->access()->grantRole($school, $principal, $target->id, $roleKey)), "principal: {$roleKey}");
            $this->assertSame('not_authorized', $this->refusal(fn () => $this->access()->grantRole($school, $foreignAdmin, $target->id, $roleKey)), "cross-School: {$roleKey}");
            $this->assertSame('not_role_manager', $authority->forGrant($guardian, $school, $role, $target->user_id)->refusal, "Guardian-only: {$roleKey}");
            $this->assertSame('self_administration', $this->refusal(fn () => $this->access()->grantRole($school, $admin, $adminMembership->id, $roleKey)), "self: {$roleKey}");
        }

        // A retired production role leaves the catalogue and accepts no new grant
        // (rolled back with the test); an existing grant stays identifiable.
        [, $holder] = $this->staffMember($school, 'staff_self_service');
        $this->access()->grantRole($school, $admin, $holder->id, 'librarian');
        $this->asCatalogueOwner(fn () => DB::table('roles')->where('key', 'librarian')->update(['retired_at' => now()]));

        $this->assertNotContains('librarian', collect(app(StaffRoleCatalog::class)->catalogFor($admin, $school))->pluck('key')->all());
        $this->assertSame('role_unavailable', $this->refusal(fn () => $this->access()->grantRole($school, $admin, $target->id, 'librarian')));
        $this->assertContains('librarian', $this->activeRoles($holder), 'The existing grant stays until revoked.');
        $this->assertSame('retired', app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', RoleGrantRefusalAudit::REFUSED)->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail()->metadata['refusal']));
    }

    #[Test]
    public function granting_a_production_role_over_http_still_needs_a_fresh_mfa_code(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [, $target] = $this->staffMember($school, 'staff_self_service');

        foreach (array_keys(self::ROLES) as $roleKey) {
            $this->staffPost($admin, $school, "/members/{$target->id}/roles", ['role' => $roleKey], withCode: false)->assertStatus(422);
        }
        $this->assertSame(['staff_self_service'], $this->activeRoles($target));

        foreach (['hr_officer', 'hr_sensitive_records', 'payroll_officer'] as $roleKey) {
            $this->staffPost($admin, $school, "/members/{$target->id}/roles", ['role' => $roleKey])->assertOk();
        }
        $this->assertSame(['hr_officer', 'hr_sensitive_records', 'payroll_officer', 'staff_self_service'], $this->activeRoles($target));
    }

    #[Test]
    public function additive_roles_combine_within_one_school_and_the_sensitive_add_on_is_removable(): void
    {
        [$admin, $school] = $this->staffAdmin();

        foreach ([['teacher', 'librarian'], ['staff_self_service', 'hr_officer'], ['stores_officer', 'canteen_operator']] as $pair) {
            [$user, $membership] = $this->staffMember($school, $pair[0]);
            $this->access()->grantRole($school, $admin, $membership->id, $pair[1]);
            $this->assertSame($this->roleCapabilities(...$pair), $this->capabilities($user, $school), implode(' + ', $pair));
        }
        $this->assertFalse(Role::query()->where('scope', 'school')->where('is_system', true)->whereIn('key', array_keys(self::ROLES))->get()
            ->contains(fn (Role $role) => $role->capabilities->contains('key', 'canteen.orders.manage') && $role->capabilities->contains('key', 'inventory.stock.manage')), 'No merged canteen + stores operational role.');

        // hr_officer alone: no sensitive keys; + hr_sensitive_records: the union; removing the add-on removes only them.
        [$hr, $hrMembership] = $this->staffMember($school, 'hr_officer');
        $this->assertNotContains('hr.employees.sensitive.view', $this->capabilities($hr, $school));
        $this->access()->grantRole($school, $admin, $hrMembership->id, 'hr_sensitive_records');
        $this->assertSame($this->roleCapabilities('hr_officer', 'hr_sensitive_records'), $this->capabilities($hr, $school));
        $this->access()->revokeRole($school, $admin, $hrMembership->id, 'hr_sensitive_records');
        $this->assertSame($this->roleCapabilities('hr_officer'), $this->capabilities($hr, $school));

        // hr_sensitive_records alone reaches exactly its two keys.
        [$sensitiveOnly] = $this->staffMember($school, 'hr_sensitive_records');
        $this->assertSame(['hr.employees.sensitive.manage', 'hr.employees.sensitive.view'], $this->capabilities($sensitiveOnly, $school));
    }

    #[Test]
    public function a_role_in_one_school_never_reaches_another_school(): void
    {
        [$adminA, $schoolA] = $this->staffAdmin();
        [$adminB, $schoolB] = $this->staffAdmin();
        $user = $this->createUser(['password' => Hash::make(self::STAFF_PASSWORD)]);
        $membershipA = $this->createMembership($user, $schoolA);
        $membershipB = $this->createMembership($user, $schoolB);
        $this->assignSchoolRole($membershipA, 'staff_self_service');
        $this->assignSchoolRole($membershipB, 'staff_self_service');
        $this->access()->grantRole($schoolA, $adminA, $membershipA->id, 'librarian');
        $this->access()->grantRole($schoolB, $adminB, $membershipB->id, 'accountant');

        $this->assertContains('library.circulation.manage', $this->capabilities($user, $schoolA));
        $this->assertNotContains('finance.payments.record', $this->capabilities($user, $schoolA));
        $this->assertContains('finance.payments.record', $this->capabilities($user, $schoolB));
        $this->assertNotContains('library.circulation.manage', $this->capabilities($user, $schoolB));

        $this->enterSchool($user, $schoolA);
        $this->get('http://localhost/app/library/titles')->assertOk();
        $this->get('http://localhost/app/finance/payments')->assertForbidden();
        $this->enterSchool($user, $schoolB);
        $this->get('http://localhost/app/finance/payments')->assertOk();
        $this->get('http://localhost/app/library/titles')->assertForbidden();
    }

    #[Test]
    public function the_hr_identity_substrate_confers_no_authority_on_the_hr_officer(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$officer, $membership] = $this->staffMember($school, 'hr_officer');
        $before = $this->capabilities($officer, $school);

        foreach (['school.roles.manage', 'school.members.manage', 'school.roles.grant.hr', 'attendance.teacher', 'curriculum.delivery.teacher', 'hr.leave.self', 'hr.leave.approve', 'payroll.payslips.self', 'payroll.runs.view', 'finance.ledger.view', 'teaching.assignments.manage'] as $absent) {
            $this->assertNotContains($absent, $before, $absent);
        }
        $this->assertSame([], array_values(array_filter($before, fn ($key) => str_starts_with($key, 'school.') || str_starts_with($key, 'payroll.') || str_starts_with($key, 'finance.'))));

        // SR.4 (ADR 0071 §26.2): the officer never links or employs themselves;
        // a peer HR officer does. ActingEmployee then resolves -- and that
        // changes no capability: every ActingEmployee surface still needs a
        // role grant the officer cannot make.
        [$peer] = $this->staffMember($school, 'hr_officer');
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Self Linked', 'employee_number' => 'SR3-SELF'], $officer);
        $this->assertSame('HR_SELF_ADMINISTRATION', $this->hrRefusal(fn () => app(EmployeeService::class)->linkUser($employee, $officer->id, $officer)));
        $linked = app(EmployeeService::class)->linkUser($employee, $officer->id, $peer);
        $this->assertSame($officer->id, $linked->user_id);
        $this->assertSame('HR_SELF_ADMINISTRATION', $this->hrRefusal(fn () => app(EmploymentService::class)->create($linked, ['employment_type' => 'permanent', 'starts_on' => '2024-01-01'], $officer)));
        app(EmploymentService::class)->create($linked, ['employment_type' => 'permanent', 'starts_on' => '2024-01-01'], $peer);

        $this->assertSame($before, $this->capabilities($officer, $school), 'The HR identity substrate grants no capability.');
        $this->assertSame('not_authorized', $this->refusal(fn () => $this->access()->grantRole($school, $officer, $membership->id, 'teacher')));
        [, $colleague] = $this->staffMember($school, 'staff_self_service');
        $this->assertSame('not_authorized', $this->refusal(fn () => $this->access()->grantRole($school, $officer, $colleague->id, 'teacher')));
    }

    #[Test]
    public function staff_accounts_presents_the_fixed_catalogue_without_any_role_editing(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$principal] = $this->staffMember($school, 'principal');

        $this->enterSchool($admin, $school);
        $catalog = null;
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(function ($page) use (&$catalog) {
            $catalog = $page->toArray()['props']['roleCatalog'];

            return $page;
        });

        $this->assertSame([
            'school_admin', 'principal', 'teacher', 'staff_self_service',
            'hr_officer', 'hr_sensitive_records', 'payroll_officer', 'accountant', 'cashier',
            'librarian', 'transport_coordinator', 'hostel_warden', 'front_office', 'stores_officer', 'canteen_operator',
            'admissions_officer', 'communications_coordinator',
        ], array_column($catalog, 'key'), 'Stable functional order.');
        $byKey = collect($catalog)->keyBy('key');
        $this->assertTrue($byKey->every(fn ($role) => $role['grantable'] === true), 'School Admin can grant every role.');
        $this->assertTrue($byKey['hr_sensitive_records']['addOn']);
        $this->assertSame(['Highly sensitive HR data'], $byKey['hr_sensitive_records']['sensitivity']);
        $this->assertSame(['Payroll amounts', 'Financial'], $byKey['payroll_officer']['sensitivity']);
        $this->assertSame('HR and payroll', $byKey['hr_officer']['groupLabel']);
        $this->assertNotEmpty($byKey['cashier']['purpose']);
        $this->assertStringNotContainsString('school.roles.grant', json_encode($catalog), 'No grant-right mechanics.');
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+\.[a-z_.]+"/', json_encode(array_map(fn ($r) => array_diff_key($r, ['key' => 1]), $catalog)), 'No capability key reaches the page.');

        // Principal: the staff list, no catalogue (SR.2, unchanged).
        $this->enterSchool($principal, $school);
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(fn ($page) => $page->where('canViewRoles', false)->where('roleCatalog', []));

        // No route creates, edits, clones or deletes a role: every mutating
        // role route grants or revokes an EXISTING role, nothing else.
        /** @var Router $router */
        $router = app('router');
        $mutating = collect($router->getRoutes()->getRoutes())
            ->filter(fn ($route) => ! in_array('GET', $route->methods(), true) && str_contains($route->uri(), 'role'))
            ->map(fn ($route) => $route->uri())->sort()->values()->all();
        $this->assertSame([
            'app/platform/roles/grants',
            'app/platform/roles/grants/{assignment}/revoke',
            'app/settings/staff/members/{membership}/roles',
            'app/settings/staff/members/{membership}/roles/{role}/revoke',
        ], $mutating);
    }
}
