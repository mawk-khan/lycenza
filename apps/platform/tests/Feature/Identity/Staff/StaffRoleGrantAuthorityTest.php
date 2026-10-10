<?php

namespace Tests\Feature\Identity\Staff;

use App\Domain\Identity\Application\Staff\RoleGrantAuthority;
use App\Domain\Identity\Application\Staff\RoleGrantRefusalAudit;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Domain\Identity\Application\Staff\StaffInvitationAcceptanceService;
use App\Domain\Identity\Application\Staff\StaffInvitationService;
use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityClasses;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\TestCase;

/**
 * SR.2 (ADR 0071 §6, §7, §10, §13): the application grant-authority contract.
 * Class-scoped grant rights (exact mapping pinned here), grantability decided
 * inside the mutation transaction, the revoke rule, invitation issue/resend/
 * acceptance under current authority, `school.roles.view`, and the audit of
 * refused and sensitive grants (classes only, never capabilities).
 */
class StaffRoleGrantAuthorityTest extends TestCase
{
    use CreatesGuardianPortalFixtures, CreatesMfaFixtures, CreatesTenancyFixtures, FakesEmail, StaffAccountTestHelpers;

    /** The approved coverage (ADR 0071 §6.1, Appendix A), written out independently of the code map. */
    private const EXPECTED_GRANT_RIGHTS = [
        'school.roles.grant.hr' => [
            'hr.categories.manage', 'hr.categories.view',
            'hr.departments.manage', 'hr.departments.view',
            'hr.employees.assignments.manage', 'hr.employees.assignments.view',
            'hr.employees.documents.manage', 'hr.employees.documents.view',
            'hr.employees.notes.manage', 'hr.employees.notes.view',
            'hr.employees.personal.manage',
            'hr.employees.qualifications.manage', 'hr.employees.qualifications.view',
            'hr.positions.manage', 'hr.positions.view',
        ],
        'school.roles.grant.hr_sensitive' => ['hr.employees.sensitive.manage', 'hr.employees.sensitive.view'],
        'school.roles.grant.payroll_sensitive' => ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view'],
    ];

    /** ADR 0071 §4.1 `hr_officer` (23 keys) -- built here as a fixture; SR.3 seeds the real role. */
    private const HR_OFFICER_KEYS = [
        'hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view', 'hr.employees.personal.manage',
        'hr.employees.assignments.view', 'hr.employees.assignments.manage',
        'hr.employees.qualifications.view', 'hr.employees.qualifications.manage',
        'hr.employees.documents.view', 'hr.employees.documents.manage',
        'hr.employees.notes.view', 'hr.employees.notes.manage',
        'hr.departments.view', 'hr.departments.manage', 'hr.positions.view', 'hr.positions.manage',
        'hr.categories.view', 'hr.categories.manage',
        'hr.leave.view', 'hr.leave.manage', 'hr.leave.configure',
        'hr.staff_attendance.view', 'hr.staff_attendance.manage',
    ];

    /** ADR 0071 §4.3 `payroll_officer` (9 keys). */
    private const PAYROLL_OFFICER_KEYS = [
        'payroll.structures.view', 'payroll.structures.manage', 'payroll.compensation.view',
        'payroll.compensation.sensitive.view', 'payroll.compensation.sensitive.manage',
        'payroll.periods.manage', 'payroll.runs.view', 'payroll.runs.prepare', 'payroll.accounting.manage',
    ];

    /** SR.3: the offered catalogue -- the four original School roles and the thirteen production roles. */
    private const SR3_CATALOGUE = [
        'accountant', 'admissions_officer', 'canteen_operator', 'cashier', 'communications_coordinator', 'front_office',
        'hostel_warden', 'hr_officer', 'hr_sensitive_records', 'librarian', 'payroll_officer', 'principal',
        'school_admin', 'staff_self_service', 'stores_officer', 'teacher', 'transport_coordinator',
    ];

    /** Staff administration without any grant right. */
    private const ADMIN_WITHOUT_GRANT_RIGHTS = ['school.members.view', 'school.members.manage', 'school.roles.view', 'school.roles.manage', 'hr.employees.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmail();
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<string> $capabilities */
    private function systemRole(array $capabilities, string $name = 'SR2 fixture role'): Role
    {
        return $this->createFixtureRole($capabilities, key: 'sr2.fixture.'.Str::uuid(), name: $name, isSystem: true);
    }

    /** @return array{0: User, 1: SchoolMembership} an issuer holding exactly the given fixture role(s) */
    private function issuer(School $school, Role ...$roles): array
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        foreach ($roles as $role) {
            $this->assignSchoolRole($membership, $role->key);
        }

        return [$user, $membership];
    }

    /** @return Collection<int, SchoolAuditEvent> */
    private function events(School $school, string $type): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('school_id', $school->id)->where('event_type', $type)->orderBy('occurred_at')->orderBy('id')->get());
    }

    /** @return array<string, mixed> */
    private function lastRefusal(School $school): array
    {
        $events = $this->events($school, RoleGrantRefusalAudit::REFUSED);
        $this->assertNotEmpty($events, 'A refused role-grant decision is audited.');

        return $events->last()->metadata;
    }

    /**
     * Audit metadata is jsonb (key order not preserved): compare as maps.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertMetadata(array $expected, array $actual, string $message = ''): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, $message);
    }

    private function assertRefused(callable $operation, string $outcome): void
    {
        try {
            $operation();
        } catch (StaffAccountException $e) {
            $this->assertSame($outcome, $e->outcome);

            return;
        }

        $this->fail("Expected the staff operation to be refused ({$outcome}).");
    }

    private function access(): StaffAccessService
    {
        return app(StaffAccessService::class);
    }

    /** @return array{0: string, 1: string} selector + secret of a freshly issued invitation */
    private function invite(School $school, User $issuer, string $email, string $roleKey): array
    {
        app(StaffInvitationService::class)->issue($school, $issuer, $email, [$roleKey]);

        return $this->staffLink();
    }

    private function acceptNew(School $school, string $selector, string $secret): string
    {
        return app(StaffInvitationAcceptanceService::class)
            ->accept($school, $selector, $secret, null, 'New Staff', self::STAFF_PASSWORD, self::STAFF_PASSWORD)->outcome;
    }

    private function retire(Role $role): void
    {
        $this->asCatalogueOwner(fn () => DB::table('roles')->where('id', $role->id)->update(['retired_at' => now()]));
    }

    /** @return list<string> */
    private function freshCapabilities(User $user, School $school): array
    {
        app(CapabilityResolver::class)->forgetCache($user, $school);

        return app(CapabilityResolver::class)->schoolCapabilities($user, $school);
    }

    // ------------------------------------------------------- the grant rights

    #[Test]
    public function the_three_grant_rights_cover_exactly_the_approved_capabilities_in_code_and_in_the_database(): void
    {
        $this->assertSame(['school.roles.grant.hr', 'school.roles.grant.hr_sensitive', 'school.roles.grant.payroll_sensitive'], CapabilityClasses::GRANT_RIGHT_KEYS);
        $this->assertCount(15, self::EXPECTED_GRANT_RIGHTS['school.roles.grant.hr']);

        $expected = [];
        foreach (self::EXPECTED_GRANT_RIGHTS as $right => $covered) {
            foreach ($covered as $capability) {
                $expected[$capability] = $right;
            }
        }
        ksort($expected);

        $code = CapabilityClasses::GRANT_RIGHTS;
        ksort($code);
        $this->assertSame($expected, $code, 'The code map is exactly the approved coverage.');

        $database = DB::table('capabilities')->whereNotNull('grant_right')->orderBy('key')->pluck('grant_right', 'key')->all();
        $this->assertSame($expected, $database, 'capabilities.grant_right equals the code map.');

        foreach (CapabilityClasses::GRANT_RIGHT_KEYS as $right) {
            $row = DB::table('capabilities')->where('key', $right)->first();
            $this->assertNotNull($row, "{$right} exists.");
            $this->assertSame('school', $row->namespace);
            $this->assertNull($row->grant_right, "{$right} is never itself covered.");
        }
    }

    #[Test]
    public function only_school_admin_holds_a_grant_right_and_no_grant_right_confers_a_covered_capability(): void
    {
        $holders = DB::table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')
            ->where('r.is_system', true)
            ->whereIn('rc.capability_key', CapabilityClasses::GRANT_RIGHT_KEYS)
            ->orderBy('rc.capability_key')->get(['r.key', 'rc.capability_key'])
            ->map(fn ($row) => $row->key.':'.$row->capability_key)->all();

        $this->assertSame([
            'school_admin:school.roles.grant.hr',
            'school_admin:school.roles.grant.hr_sensitive',
            'school_admin:school.roles.grant.payroll_sensitive',
        ], $holders, 'Only school_admin holds a grant right (principal, teacher, staff_self_service and guardian hold none).');

        // D3: the sensitive keys stay "nobody by default" -- school_admin holds none of the covered capabilities.
        $adminKeys = DB::table('role_capabilities as rc')->join('roles as r', 'r.id', '=', 'rc.role_id')
            ->where('r.key', 'school_admin')->pluck('rc.capability_key')->all();
        $this->assertSame([], array_values(array_intersect(array_keys(CapabilityClasses::GRANT_RIGHTS), $adminKeys)));
    }

    #[Test]
    public function grant_rights_never_chain_never_cover_authority_legal_gated_owned_scope_portal_or_marks(): void
    {
        $rows = DB::table('capabilities')->whereNotNull('grant_right')->get(['key', 'grant_right']);
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertContains($row->grant_right, CapabilityClasses::GRANT_RIGHT_KEYS, "{$row->key}: an existing authority grant right.");
            $this->assertNotSame($row->key, $row->grant_right, 'Never self-covered.');
            $this->assertNotContains($row->key, CapabilityClasses::GRANT_RIGHT_KEYS, 'A grant right is never covered.');
            $this->assertNull(DB::table('capabilities')->where('key', $row->grant_right)->value('grant_right'), 'No chain.');

            $classes = CapabilityClasses::of($row->key);
            foreach (['authority', 'legal-gated', 'owned-scope'] as $forbidden) {
                $this->assertNotContains($forbidden, $classes, "{$row->key} ({$forbidden}) is never covered by a grant right.");
            }
            $this->assertDoesNotMatchRegularExpression('/^portal\.|\.teacher$|\.self$|^examinations\.marks\.|^payroll\.statutory\.|^analytics\.export$|^communications\.conversations\.students$|^school\.(members|roles)\./', $row->key);
        }

        foreach (CapabilityClasses::GRANT_RIGHT_KEYS as $right) {
            $this->assertSame(['authority'], CapabilityClasses::of($right));
        }
    }

    #[Test]
    public function every_school_capability_is_classified_from_the_closed_class_set(): void
    {
        $database = DB::table('capabilities')->where('namespace', 'school')->orderBy('key')->pluck('key')->all();
        $code = array_keys(CapabilityClasses::CLASSES);
        sort($code);

        $this->assertSame($database, $code, 'Every school capability is classified, and no unknown key is.');
        $this->assertCount(177, $code, '174 classified in SR.0 + the three SR.2 grant rights.');

        foreach (CapabilityClasses::CLASSES as $capability => $classes) {
            $this->assertNotEmpty($classes, $capability);
            $this->assertSame([], array_values(array_diff($classes, CapabilityClasses::CLASS_SET)), "{$capability}: only closed classes.");
        }
    }

    #[Test]
    public function a_grant_right_confers_no_data_access_and_no_code_authorizes_by_a_role_key(): void
    {
        $staffRoleKeys = 'school_admin|principal|staff_self_service|hr_officer|hr_sensitive_records|payroll_officer|accountant|cashier|librarian|transport_coordinator|hostel_warden|front_office|stores_officer|canteen_operator|admissions_officer|communications_coordinator';

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $path = str_replace(base_path().'/', '', $file->getRealPath());
            $source = $file->getContents();

            // ADR 0071 §6.3: no module checks a grant-right key -- only the code map names them.
            if (str_contains($source, 'school.roles.grant.')) {
                $this->assertSame('app/Support/Authorization/CapabilityClasses.php', $path, "{$path} names a grant right: grant rights authorize role grants only.");
            }

            // CLAUDE.md rule 24: no authorization by a School role key. The ADR 0047
            // bootstrap target (`school_admin`) is the one sanctioned role identity;
            // SR.3's StaffRolePresentation names keys for catalogue display only.
            if (preg_match("/['\"]({$staffRoleKeys})['\"]/", $source) === 1) {
                $this->assertContains($path, [
                    'app/Domain/Platform/Application/Schools/SchoolBootstrapAdministrationService.php',
                    'app/Domain/Identity/Application/Staff/StaffRolePresentation.php',
                ], "{$path} names a School role key.");
            }
            $this->assertDoesNotMatchRegularExpression("/(->key|\\['key'\\])\\s*[!=]==?\\s*['\"]teacher['\"]|where\\(\\s*['\"](roles\\.)?key['\"]\\s*,\\s*['\"]teacher['\"]/", $source, "{$path} branches on the teacher role key.");
        }
    }

    // ----------------------------------------------------- grantability (§6.2)

    #[Test]
    public function school_admin_grants_an_hr_role_through_the_grant_right_without_holding_hr_data_capabilities(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $hrOfficer = $this->systemRole(self::HR_OFFICER_KEYS, 'HR officer (fixture)');

        $held = $this->freshCapabilities($admin, $school);
        $this->assertNotContains('hr.positions.view', $held);
        $this->assertNotContains('hr.employees.assignments.manage', $held);
        $this->assertContains('school.roles.grant.hr', $held);

        $this->access()->grantRole($school, $admin, $target->id, $hrOfficer->key);

        $this->assertContains($hrOfficer->key, $this->activeRoles($target));
        $this->assertNotContains('hr.positions.view', $this->freshCapabilities($admin, $school), 'Granting confers nothing on the issuer.');
        $assigned = $this->events($school, StaffAccessService::ROLE_ASSIGNED)->last()->metadata;
        $this->assertSame(['school.roles.grant.hr'], $assigned['grantRights']);
        $this->assertSame(['hr'], $assigned['classes']);
        $this->assertSame($hrOfficer->key, $assigned['roleKey']);
        $this->assertStringNotContainsString('hr.positions', json_encode($assigned));
    }

    #[Test]
    public function sensitive_grants_record_every_grant_right_used_deterministically_and_ordinary_grants_stay_light(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [, $target] = $this->staffMember($school, 'staff_self_service');

        $cases = [
            [['hr.employees.sensitive.view', 'hr.employees.sensitive.manage'], ['school.roles.grant.hr_sensitive'], ['hr-sensitive']],
            [self::PAYROLL_OFFICER_KEYS, ['school.roles.grant.payroll_sensitive'], ['financial', 'payroll-sensitive']],
            [['payroll.compensation.sensitive.view', 'hr.employees.sensitive.view', 'hr.positions.view'], ['school.roles.grant.hr', 'school.roles.grant.hr_sensitive', 'school.roles.grant.payroll_sensitive'], ['financial', 'hr', 'hr-sensitive', 'payroll-sensitive']],
        ];
        foreach ($cases as [$capabilities, $rights, $classes]) {
            $role = $this->systemRole($capabilities);
            $this->access()->grantRole($school, $admin, $target->id, $role->key);
            $metadata = $this->events($school, StaffAccessService::ROLE_ASSIGNED)->last()->metadata;
            $this->assertSame([$rights, $classes], [$metadata['grantRights'], $metadata['classes']]);
        }

        $this->access()->grantRole($school, $admin, $target->id, 'teacher');
        $ordinary = $this->events($school, StaffAccessService::ROLE_ASSIGNED)->last()->metadata;
        $this->assertMetadata(['schoolMembershipId' => $target->id, 'roleKey' => 'teacher'], $ordinary, 'An ordinary grant stays lightweight.');
    }

    #[Test]
    public function an_issuer_without_the_grant_right_is_refused_and_the_refusal_names_classes_only(): void
    {
        $school = $this->createSchool();
        [$issuer] = $this->issuer($school, $this->createFixtureRole(self::ADMIN_WITHOUT_GRANT_RIGHTS));
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $hrOfficer = $this->systemRole(self::HR_OFFICER_KEYS);

        $this->assertRefused(fn () => $this->access()->grantRole($school, $issuer, $target->id, $hrOfficer->key), 'role_escalation');

        $this->assertNotContains($hrOfficer->key, $this->activeRoles($target));
        $refusal = $this->lastRefusal($school);
        $this->assertMetadata(['stage' => 'grant', 'schoolMembershipId' => $target->id, 'roleKey' => $hrOfficer->key, 'refusal' => 'not_covered', 'uncoveredClasses' => ['hr']], $refusal);
        $this->assertStringNotContainsString('hr.', json_encode($refusal), 'Never a capability key.');
        $this->assertCount(1, $this->events($school, RoleGrantRefusalAudit::REFUSED), 'One decision, one event.');

        // The statutory payroll keys are legal-gated: nobody covers them, school_admin included.
        [$admin] = $this->staffAdmin($school);
        $statutory = $this->systemRole(['payroll.statutory.view']);
        $this->assertRefused(fn () => $this->access()->grantRole($school, $admin, $target->id, $statutory->key), 'role_escalation');
        $this->assertSame(['financial', 'legal-gated'], $this->lastRefusal($school)['uncoveredClasses']);
    }

    #[Test]
    public function principal_and_a_role_viewer_cannot_grant_and_a_guardian_identity_never_satisfies_grant_authority(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$principal] = $this->staffMember($school, 'principal');
        [, $target] = $this->staffMember($school, 'staff_self_service');

        $this->assertRefused(fn () => $this->access()->grantRole($school, $principal, $target->id, 'teacher'), 'not_authorized');
        $this->assertSame('not_role_manager', $this->lastRefusal($school)['refusal']);

        // school.roles.view never manufactures authority.
        $principalMembership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $principal->id)->sole();
        LocalCatalogueFixtures::grantRole($principalMembership, $this->createFixtureRole(['school.roles.view']));
        $this->assertRefused(fn () => $this->access()->grantRole($school, $principal, $target->id, 'teacher'), 'not_authorized');

        $authority = app(RoleGrantAuthority::class);
        foreach (['school.roles.grant.hr', 'school.roles.grant.hr_sensitive', 'school.roles.grant.payroll_sensitive'] as $right) {
            $this->assertNotContains($right, $authority->held($principal, $school));
        }

        // Guardian-only: an active `guardian` grant never counts toward staff authority.
        $guardian = $this->portalGuardian($school);
        $this->assertSame([], $authority->held($guardian['user'], $school), 'A Guardian-only membership holds no staff capability.');
        $this->assertSame('not_role_manager', $authority->forGrant($guardian['user'], $school, Role::query()->where('key', 'teacher')->sole())->refusal);

        // Dual persona: an administrator who is also a Guardian acts with the staff grants only.
        $adminMembership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $admin->id)->sole();
        $this->portalGuardian($school, $admin, $adminMembership);
        $held = $authority->held($admin, $school);
        $this->assertContains('school.roles.manage', $held);
        $this->assertSame([], array_values(array_filter($held, fn ($key) => str_starts_with($key, 'portal.'))));
        $this->assertTrue($authority->forGrant($admin, $school, Role::query()->where('key', 'teacher')->sole(), $target->user_id)->allowed());
    }

    #[Test]
    public function retired_empty_cross_school_and_self_grants_are_refused_and_audited(): void
    {
        [$admin, $school, $adminMembership] = $this->staffAdmin();
        [, $target] = $this->staffMember($school, 'staff_self_service');

        $retired = $this->systemRole(['students.view']);
        $this->retire($retired);
        $this->assertRefused(fn () => $this->access()->grantRole($school, $admin, $target->id, $retired->key), 'role_unavailable');
        $this->assertSame('retired', $this->lastRefusal($school)['refusal']);

        $empty = $this->systemRole([]);
        $this->assertRefused(fn () => $this->access()->grantRole($school, $admin, $target->id, $empty->key), 'role_unavailable');
        $this->assertSame('empty_role', $this->lastRefusal($school)['refusal']);

        $this->assertRefused(fn () => $this->access()->grantRole($school, $admin, $adminMembership->id, 'teacher'), 'self_administration');
        $this->assertSame('self_administration', $this->lastRefusal($school)['refusal']);

        // Another School's administrator holds no authority here.
        [$foreignAdmin] = $this->staffAdmin();
        $this->assertRefused(fn () => $this->access()->grantRole($school, $foreignAdmin, $target->id, 'teacher'), 'not_authorized');
        $this->assertSame('inactive_issuer', $this->lastRefusal($school)['refusal']);

        // Validation is never audited: an unknown or non-system key.
        $before = $this->events($school, RoleGrantRefusalAudit::REFUSED)->count();
        $this->assertRefused(fn () => $this->access()->grantRole($school, $admin, $target->id, 'no-such-role'), 'role_unknown');
        $this->assertRefused(fn () => $this->access()->grantRole($school, $admin, $target->id, $this->createFixtureRole(['students.view'])->key), 'role_unknown');
        $this->assertSame($before, $this->events($school, RoleGrantRefusalAudit::REFUSED)->count());
        $this->assertSame(['staff_self_service'], $this->activeRoles($target));
    }

    // ------------------------------------------------------------ revoke (§10.2)

    #[Test]
    public function revoking_needs_the_same_authority_and_off_boarding_always_removes_a_sensitive_role(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$limited] = $this->issuer($school, $this->createFixtureRole(self::ADMIN_WITHOUT_GRANT_RIGHTS));
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $sensitive = $this->systemRole(['hr.employees.sensitive.view', 'hr.employees.sensitive.manage']);
        $this->access()->grantRole($school, $admin, $target->id, $sensitive->key);

        $this->assertRefused(fn () => $this->access()->revokeRole($school, $limited, $target->id, $sensitive->key), 'revoke_escalation');
        $this->assertMetadata(['stage' => 'revoke', 'schoolMembershipId' => $target->id, 'roleKey' => $sensitive->key, 'refusal' => 'not_covered', 'uncoveredClasses' => ['hr-sensitive']], $this->lastRefusal($school));
        $this->assertContains($sensitive->key, $this->activeRoles($target));

        // §10.3: off-boarding is the emergency path -- never coverage-gated.
        $this->access()->suspend($school, $limited, $target->id);
        $this->assertSame([], $this->activeRoles($target));
        $this->assertSame('suspended', $target->fresh()->status);
    }

    #[Test]
    public function losing_a_grant_right_never_strands_a_dangerous_grant(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $grantRight = $this->systemRole(['school.roles.grant.hr_sensitive'], 'Grant right holder (fixture)');
        [$officer, $officerMembership] = $this->issuer($school, $this->createFixtureRole(self::ADMIN_WITHOUT_GRANT_RIGHTS), $grantRight);
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $sensitive = $this->systemRole(['hr.employees.sensitive.view']);

        $this->access()->grantRole($school, $officer, $target->id, $sensitive->key);
        $this->assertSame(['school.roles.grant.hr_sensitive'], $this->events($school, StaffAccessService::ROLE_ASSIGNED)->last()->metadata['grantRights']);

        // The officer loses the grant right ...
        $this->access()->revokeRole($school, $admin, $officerMembership->id, $grantRight->key);
        // ... and can no longer revoke (or grant) the sensitive role,
        $this->assertRefused(fn () => $this->access()->revokeRole($school, $officer, $target->id, $sensitive->key), 'revoke_escalation');
        // but another administrator holding the right still can, and off-boarding always could.
        $this->access()->revokeRole($school, $admin, $target->id, $sensitive->key);
        $this->assertSame(['staff_self_service'], $this->activeRoles($target));
    }

    #[Test]
    public function a_retired_or_emptied_role_stays_revocable(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $role = $this->systemRole(['students.view', 'hr.positions.view']);
        $this->access()->grantRole($school, $admin, $target->id, $role->key);

        $this->retire($role);
        LocalCatalogueFixtures::setRoleCapabilities($role, []);

        $this->access()->revokeRole($school, $admin, $target->id, $role->key);
        $this->assertSame(['staff_self_service'], $this->activeRoles($target));
    }

    #[Test]
    public function reactivation_decides_every_chosen_role_inside_its_transaction(): void
    {
        $school = $this->createSchool();
        [$limited] = $this->issuer($school, $this->createFixtureRole([...self::ADMIN_WITHOUT_GRANT_RIGHTS, 'hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self']));
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $this->access()->suspend($school, $limited, $target->id);
        $hr = $this->systemRole(['hr.positions.view']);

        $this->assertRefused(fn () => $this->access()->reactivate($school, $limited, $target->id, ['staff_self_service', $hr->key]), 'role_escalation');
        $this->assertSame('suspended', $target->fresh()->status, 'The refused reactivation rolled back entirely.');
        $this->assertMetadata(['stage' => 'reactivation', 'schoolMembershipId' => $target->id, 'roleKey' => $hr->key, 'refusal' => 'not_covered', 'uncoveredClasses' => ['hr']], $this->lastRefusal($school));

        $this->access()->reactivate($school, $limited, $target->id, ['staff_self_service']);
        $this->assertSame(['staff_self_service'], $this->activeRoles($target));
    }

    // ------------------------------------------------------------- invitations

    #[Test]
    public function invitation_issue_decides_under_the_lock_and_a_refused_issue_leaves_nothing(): void
    {
        $school = $this->createSchool();
        [$limited] = $this->issuer($school, $this->createFixtureRole(self::ADMIN_WITHOUT_GRANT_RIGHTS));
        $hr = $this->systemRole(['hr.positions.view']);

        $this->assertRefused(fn () => app(StaffInvitationService::class)->issue($school, $limited, 'hr.person@example.test', [$hr->key]), 'role_escalation');
        $this->assertSame(0, app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->count()));
        $this->assertMetadata(['stage' => 'invitation', 'roleKey' => $hr->key, 'refusal' => 'not_covered', 'uncoveredClasses' => ['hr']], $this->lastRefusal($school));
        $this->assertStringNotContainsString('hr.person', json_encode($this->lastRefusal($school)), 'Never the invited address.');

        $retired = $this->systemRole(['hr.employees.view']);
        $this->retire($retired);
        $this->assertRefused(fn () => app(StaffInvitationService::class)->issue($school, $limited, 'retired@example.test', [$retired->key]), 'role_unavailable');
        $this->assertSame('retired', $this->lastRefusal($school)['refusal']);
    }

    #[Test]
    public function a_resend_reissues_only_roles_the_resender_could_grant_now(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$memberManager] = $this->issuer($school, $this->createFixtureRole(['school.members.view', 'school.members.manage']));
        $hr = $this->systemRole(['hr.positions.view']);
        $invitation = app(StaffInvitationService::class)->issue($school, $admin, 'resend.me@example.test', [$hr->key]);

        $this->assertRefused(fn () => app(StaffInvitationService::class)->resend($school, $memberManager, $invitation->id), 'not_authorized');
        $this->assertMetadata(['stage' => 'invitation_resend', 'invitationId' => $invitation->id, 'roleKey' => $hr->key, 'refusal' => 'not_role_manager'], $this->lastRefusal($school));
        $this->assertSame('pending', app(TenantContext::class)->withSchool($school, fn () => $invitation->fresh()->status), 'The refused resend rolled back.');

        $this->retire($hr);
        $this->assertRefused(fn () => app(StaffInvitationService::class)->resend($school, $admin, $invitation->id), 'role_unavailable');
    }

    #[Test]
    public function acceptance_revalidates_the_issuers_current_authority_and_stale_authority_never_grants(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$other] = $this->staffAdmin($school);
        $issuerMembership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $admin->id)->sole();

        [$selector, $secret] = $this->invite($school, $admin, 'stale.issuer@example.test', 'teacher');
        $this->access()->revokeRole($school, $other, $issuerMembership->id, 'school_admin');

        $this->assertSame('invalid', $this->acceptNew($school, $selector, $secret));
        $this->assertSame(0, User::query()->where('email', 'stale.issuer@example.test')->count());
        $refusal = $this->lastRefusal($school);
        $this->assertSame(['invitation_acceptance', 'teacher', 'not_role_manager'], [$refusal['stage'], $refusal['roleKey'], $refusal['refusal']]);
        $this->assertSame('pending', app(TenantContext::class)->withSchool($school, fn () => StaffAccountInvitation::query()->where('destination_email', 'stale.issuer@example.test')->sole()->status));
    }

    #[Test]
    public function acceptance_refuses_a_retired_role_and_a_lost_grant_right(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $role = $this->systemRole(['students.view']);
        [$selector, $secret] = $this->invite($school, $admin, 'retired.role@example.test', $role->key);
        $this->retire($role);

        $this->assertSame('invalid', $this->acceptNew($school, $selector, $secret));
        $this->assertSame('retired', $this->lastRefusal($school)['refusal']);

        // The issuer's grant right lost before acceptance.
        $grantRight = $this->systemRole(['school.roles.grant.hr']);
        [$officer, $officerMembership] = $this->issuer($school, $this->createFixtureRole(self::ADMIN_WITHOUT_GRANT_RIGHTS), $grantRight);
        $hr = $this->systemRole(['hr.positions.view']);
        [$selector, $secret] = $this->invite($school, $officer, 'lost.right@example.test', $hr->key);
        $this->access()->revokeRole($school, $admin, $officerMembership->id, $grantRight->key);

        $this->assertSame('invalid', $this->acceptNew($school, $selector, $secret));
        $this->assertSame(['invitation_acceptance', 'not_covered', ['hr']], [$this->lastRefusal($school)['stage'], $this->lastRefusal($school)['refusal'], $this->lastRefusal($school)['uncoveredClasses']]);
    }

    #[Test]
    public function an_accepted_class_scoped_invitation_records_the_grant_right_used(): void
    {
        [$admin, $school] = $this->staffAdmin();
        $hr = $this->systemRole(self::HR_OFFICER_KEYS);
        [$selector, $secret] = $this->invite($school, $admin, 'hr.officer@example.test', $hr->key);

        $this->assertSame('accepted_new', $this->acceptNew($school, $selector, $secret));

        $assigned = $this->events($school, StaffAccessService::ROLE_ASSIGNED)->last()->metadata;
        $this->assertSame([$hr->key, ['school.roles.grant.hr'], ['hr']], [$assigned['roleKey'], $assigned['grantRights'], $assigned['classes']]);
        $grant = app(TenantContext::class)->withSchool($school, fn () => DB::table('membership_role_assignments')->where('role_id', $hr->id)->sole());
        $this->assertSame($admin->id, $grant->assigned_by_user_id);
    }

    // ------------------------------------------------- backstop + audit hygiene

    #[Test]
    public function a_database_backstop_refusal_becomes_one_audited_controlled_refusal(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$principal] = $this->staffMember($school, 'principal');
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $teacher = Role::query()->where('key', 'teacher')->sole();

        try {
            app(TenantContext::class)->withSchool($school, fn () => DB::transaction(fn () => RoleGrantAuthority::backstopped(
                fn () => DB::table('membership_role_assignments')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'school_membership_id' => $target->id,
                    'role_id' => $teacher->id, 'assigned_by_user_id' => $principal->id, 'created_at' => now(), 'updated_at' => now(),
                ]),
                ['stage' => 'grant', 'schoolMembershipId' => $target->id, 'roleKey' => 'teacher'],
            )));
            $this->fail('The database refuses a grant beyond the recorded assigner.');
        } catch (StaffAccountException $e) {
            $this->assertSame('role_escalation', $e->outcome);
            $this->assertStringNotContainsString('membership_role_assignments', $e->getMessage(), 'Never the SQL to the user.');
            app(RoleGrantRefusalAudit::class)->recordAfterRollback($school, $principal, $e);
        }

        $this->assertMetadata(['stage' => 'grant', 'schoolMembershipId' => $target->id, 'roleKey' => 'teacher', 'refusal' => 'database_backstop'], $this->lastRefusal($school));
        $this->assertSame(['staff_self_service'], $this->activeRoles($target));
    }

    // ---------------------------------------------------------- HTTP surface

    #[Test]
    public function every_staff_role_mutation_still_needs_a_fresh_mfa_code(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [, $target] = $this->staffMember($school, 'staff_self_service');
        $invitation = app(StaffInvitationService::class)->issue($school, $admin, 'mfa.check@example.test', ['teacher']);

        foreach ([
            ['/invitations', ['email' => 'other@example.test', 'roles' => ['teacher']]],
            ["/invitations/{$invitation->id}/resend", []],
            ["/invitations/{$invitation->id}/revoke", []],
            ["/members/{$target->id}/roles", ['role' => 'teacher']],
            ["/members/{$target->id}/roles/staff_self_service/revoke", []],
            ["/members/{$target->id}/suspend", []],
            ["/members/{$target->id}/reactivate", ['roles' => ['teacher']]],
        ] as [$path, $data]) {
            $response = $this->staffPost($admin, $school, $path, $data, withCode: false);
            $this->assertContains($response->status(), [403, 422], "{$path} without a fresh MFA code");
            $this->assertArrayNotHasKey('granted', (array) $response->json());
        }

        $this->assertSame(['staff_self_service'], $this->activeRoles($target));
        $this->assertSame('active', $target->fresh()->status);
        $this->assertSame('pending', app(TenantContext::class)->withSchool($school, fn () => $invitation->fresh()->status));
    }

    #[Test]
    public function the_role_catalogue_and_assignments_need_school_roles_view_and_viewing_never_mutates(): void
    {
        [$admin, $school] = $this->staffAdmin();
        [$principal] = $this->staffMember($school, 'principal');
        [, $target] = $this->staffMember($school, 'staff_self_service');

        // school.members.view alone: who has staff access, never with which roles.
        $this->enterSchool($principal, $school);
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canViewRoles', false)->where('roleCatalog', [])
            ->where('staff', fn ($staff) => collect($staff)->every(fn ($member) => $member['roles'] === [])));

        // school.roles.view: the catalogue and assignments, with nothing grantable.
        $viewer = $this->createUserWithCapabilities($school, ['school.members.view', 'school.roles.view']);
        $this->withMfaCodes($viewer);
        $this->enterSchool($viewer, $school);
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canViewRoles', true)
            ->where('roleCatalog', fn ($catalog) => collect($catalog)->pluck('key')->sort()->values()->all() === self::SR3_CATALOGUE
                && collect($catalog)->every(fn ($role) => $role['grantable'] === false
                    && array_keys($role) === ['key', 'name', 'grantable', 'group', 'groupLabel', 'purpose', 'addOn', 'sensitivity']
                    && ! str_contains(json_encode($role), 'school.') && ! str_contains(json_encode($role), 'hr.')))
            ->where('staff', fn ($staff) => collect($staff)->contains(fn ($member) => $member['membershipId'] === $target->id && $member['roles'] === [['key' => 'staff_self_service', 'name' => 'Staff Self-Service']])));

        $this->staffPost($viewer, $school, "/members/{$target->id}/roles", ['role' => 'teacher'])->assertForbidden();
        $this->staffPost($viewer, $school, "/members/{$target->id}/roles/staff_self_service/revoke")->assertForbidden();
        $this->assertSame(['staff_self_service'], $this->activeRoles($target));

        // The administrator sees grantability, and the HTTP refusal never leaks internals.
        $this->enterSchool($admin, $school);
        $this->get('http://localhost/app/settings/staff')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canViewRoles', true)
            ->where('roleCatalog', fn ($catalog) => collect($catalog)->every(fn ($role) => $role['grantable'] === true)));

        $hr = $this->systemRole(['payroll.statutory.view']);
        $response = $this->staffPost($admin, $school, "/members/{$target->id}/roles", ['role' => $hr->key])->assertStatus(422);
        $this->assertSame([StaffAccountException::MESSAGES['role_escalation']], $response->json('error.errors.staff'));
        $this->assertStringNotContainsString('payroll', $response->getContent());
    }
}
