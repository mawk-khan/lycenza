<?php

namespace Tests\Feature\Identity\Staff;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Phase 0O.12B (ADR 0059; owner amendment): the staff-account boundaries as
 * permanent architecture checks. Before this phase no School route or
 * service suspended, reactivated or revoked a staff member's School access,
 * and role grants had no revocation history -- now exactly the Identity
 * staff services do, and nothing else may start doing it silently.
 */
class StaffAccountArchitectureGuardTest extends TestCase
{
    /** @return iterable<string, string> relative path => source */
    private function appSources(): iterable
    {
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            yield str_replace(base_path().'/', '', $file->getRealPath()) => $file->getContents();
        }
    }

    #[Test]
    public function only_the_sanctioned_paths_create_users(): void
    {
        $allowed = [
            'app/Domain/Platform/Application/Roles/PlatformRootProvisioningService.php', // first root (ADR 0046)
            'app/Domain/Identity/Application/GuardianAccountActivationService.php',       // Guardian activation
            'app/Domain/Identity/Application/Staff/BootstrapAccountProvisioningService.php', // flow A (ADR 0059)
            'app/Domain/Identity/Application/Staff/StaffInvitationAcceptanceService.php',  // flow B (ADR 0059)
        ];

        foreach ($this->appSources() as $path => $source) {
            if (preg_match('/User::(query\(\)->)?(create|forceCreate|firstOrCreate|updateOrCreate)\(|new User\(/', $source) === 1) {
                $this->assertContains($path, $allowed, "{$path} creates a User: account creation belongs to the sanctioned identity paths only (ADR 0059).");
            }
        }
    }

    #[Test]
    public function only_the_sanctioned_paths_write_school_memberships_and_role_grants(): void
    {
        $membershipWriters = [
            'app/Domain/Platform/Application/Schools/SchoolBootstrapAdministrationService.php', // ADR 0047 bootstrap only
            'app/Domain/Identity/Application/GuardianAccountActivationService.php',
            'app/Domain/Identity/Application/Staff/StaffInvitationAcceptanceService.php',
            'app/Domain/Identity/Application/Staff/StaffAccessService.php',
            // POR.1 (ADR 0070 §9.2): Guardian off-boarding suspends a Guardian-only membership.
            'app/Domain/Identity/Application/Portal/GuardianOffboardingService.php',
        ];
        $grantWriters = [
            'app/Domain/Platform/Application/Schools/SchoolBootstrapAdministrationService.php',
            'app/Domain/Identity/Application/Staff/StaffInvitationAcceptanceService.php',
            'app/Domain/Identity/Application/Staff/StaffAccessService.php',
            // POR.1 (ADR 0070 §8.2): the only writer of the closed `guardian`-scope grant.
            'app/Domain/Identity/Application/Portal/GuardianPortalRoleGrants.php',
        ];

        foreach ($this->appSources() as $path => $source) {
            if (str_contains($source, 'SchoolMembership::query()->create(') || preg_match('/\$\w*[mM]embership->update\(\[\s*\'status\'/', $source) === 1) {
                $this->assertContains($path, $membershipWriters, "{$path} writes a School membership.");
            }
            if (str_contains($source, 'MembershipRoleAssignment::query()->create(') || str_contains($source, "'revocation_reason' =>")) {
                if (str_contains($source, 'MembershipRoleAssignment')) {
                    $this->assertContains($path, $grantWriters, "{$path} writes a School role grant.");
                }
            }
        }
    }

    #[Test]
    public function school_role_grants_are_never_deleted_and_revoked_ones_never_authorize(): void
    {
        foreach ($this->appSources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/MembershipRoleAssignment::query\(\)[^;]*->delete\(\)|roleAssignments\(\)->delete\(\)/s', $source, "{$path} deletes a School role grant: revoke it instead (history).");
        }

        $resolver = (string) file_get_contents(app_path('Support/Authorization/CapabilityResolver.php'));
        $this->assertMatchesRegularExpression('/MembershipRoleAssignment::query\(\)\s*->where\(\'school_membership_id\'[^;]*->active\(\)/s', $resolver, 'Only ACTIVE grants authorize.');
        $this->assertStringContainsString("->active()\n                    ->first();", $resolver, 'Only an ACTIVE membership authorizes (the off-boarding kill switch).');
    }

    #[Test]
    public function the_bootstrap_account_path_is_console_only_and_writes_no_school_authority(): void
    {
        $service = (string) file_get_contents(app_path('Domain/Identity/Application/Staff/BootstrapAccountProvisioningService.php'));
        foreach (['SchoolMembership::', 'MembershipRoleAssignment::', 'PlatformRoleAssignment::', 'GroupRoleAssignment::', 'Employee::', '\\Employee;'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $service, "The bootstrap account path never uses {$forbidden}.");
        }
        $this->assertStringNotContainsString("'password' => \$", $service, 'No operator-chosen password.');

        /** @var Router $router */
        $router = app('router');
        foreach ($router->getRoutes() as $route) {
            $action = $route->getActionName();
            $this->assertStringNotContainsString('BootstrapAccountProvisioning', $action, 'No HTTP route creates a bootstrap account.');
            $this->assertStringNotContainsString('provision-school-admin', $route->uri());
        }

        $command = (string) file_get_contents(app_path('Console/Commands/ProvisionSchoolAdminAccount.php'));
        $this->assertStringNotContainsString('{--force', $command, 'A display-once secret needs a human: no --force option.');
        $this->assertStringContainsString('isInteractive()', $command);
    }

    #[Test]
    public function the_identity_credential_tables_are_written_only_by_the_staff_services(): void
    {
        foreach ($this->appSources() as $path => $source) {
            if (str_contains($source, 'AccountActivationCredential::query()') || str_contains($source, 'StaffAccountInvitation::query()')) {
                $this->assertMatchesRegularExpression('#^app/(Domain/Identity/(Application/Staff|Infrastructure)/|Console/Commands/PruneStaffAccountCredentials\.php|Domain/Identity/Application/Staff/)#', $path, "{$path} reads or writes staff account credentials outside the Identity staff services.");
            }
        }
    }

    #[Test]
    public function staff_management_never_bumps_the_global_credential_version_or_touches_employment(): void
    {
        $access = (string) file_get_contents(app_path('Domain/Identity/Application/Staff/StaffAccessService.php'));
        foreach (["'credential_version'", "'is_disabled'", '->tokens()', 'Employee::', '\\Employee;', 'UserMfa', 'setPassword('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $access, "Off-boarding one School never touches {$forbidden}.");
        }
    }
}
