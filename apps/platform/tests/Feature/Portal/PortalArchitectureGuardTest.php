<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\EnsurePortalDevelopmentOnly;
use App\Support\Portal\PortalUnavailableException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * POR.1 (ADR 0070 §8, §17, §18.2): structural guards. They fail on the shape
 * of a change that would expose the portal without its production block or
 * capability, reach it through the API, check the Guardian role by name, or
 * let staff code treat a Guardian grant as staff.
 */
class PortalArchitectureGuardTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    #[Test]
    public function every_portal_route_is_session_only_blocked_first_and_capability_gated(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())->filter(fn (Route $r) => str_starts_with($r->uri(), 'app/portal'));
        $this->assertSame(['app.portal.communications.attachments.download', 'app.portal.communications.index', 'app.portal.communications.show'],
            $routes->map(fn (Route $r) => (string) $r->getName())->sort()->values()->all(), 'POR.1 is the read-only inbox only: no reply, compose, Student, Attendance or Fees route.');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('school-context', $middleware, $route->uri());
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri().' is read-only.');
            $block = array_search('portal-development-only', $middleware, true);
            $capability = array_search('capability:portal.communications.view', $middleware, true);
            $this->assertIsInt($block, $route->uri().' carries the production block.');
            $this->assertIsInt($capability, $route->uri().' carries the portal capability.');
            $this->assertLessThan($capability, $block, 'The production block answers first.');
        }

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertEmpty(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_contains($m, 'portal.')), "{$route->uri()}: no bearer/API portal access (ADR 0070 §17).");
            }
        }
    }

    #[Test]
    public function the_production_block_is_code_not_configuration_and_every_portal_entry_point_asserts_it(): void
    {
        $availability = $this->code(app_path('Support/Portal/PortalAvailability.php'));
        $this->assertStringContainsString("public const array ENVIRONMENTS = ['local', 'testing'];", $availability);
        foreach (['config(', 'env(', 'getenv', '$_ENV', '$_SERVER', 'request('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $availability);
        }

        $service = $this->code(app_path('Domain/Communications/Application/Portal/GuardianAnnouncementReadService.php'));
        foreach (['inbox', 'show', 'attachmentForDownload'] as $method) {
            $this->assertMatchesRegularExpression('/public function '.$method.'\([^{]*\{\s*PortalAvailability::assertAvailable\(\);/', $service, "{$method}() refuses outside local/testing first");
        }
    }

    #[Test]
    public function the_service_block_still_refuses_without_its_middleware(): void
    {
        $school = $this->createSchool();
        $p = $this->portalGuardian($school);
        $this->signInTo($p['user'], $school);
        $this->withoutMiddleware([PreventRequestForgery::class, EnsurePortalDevelopmentOnly::class]);

        $this->app['env'] = 'production';
        $this->get('/app/portal/communications')->assertForbidden()->assertSee(PortalUnavailableException::MESSAGE);
        $this->app['env'] = 'testing';
    }

    #[Test]
    public function nothing_checks_the_guardian_role_by_name_and_staff_code_counts_school_scope_only(): void
    {
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $code = $this->code($file->getRealPath());
            $this->assertDoesNotMatchRegularExpression("/->key\s*(===|==|!==|!=)\s*'guardian'|'guardian'\s*(===|==)\s*\\\$\w+->key|hasRole\(/", $code, $file->getRelativePathname());
            if (str_contains($code, 'Role::GUARDIAN')) {
                $this->assertContains($file->getRelativePathname(), ['Models/Role.php', 'Domain/Identity/Application/Portal/GuardianPortalRoleGrants.php'], 'Only the grant writer looks the Guardian role up (to grant it).');
            }
        }

        $staff = $this->code(app_path('Domain/Identity/Application/Staff/StaffAccessService.php'));
        $this->assertMatchesRegularExpression('/\$isStaff = MembershipRoleAssignment::query\(\)->where\(\'school_membership_id\', \$membership->id\)->staff\(\)->exists\(\);/', $staff);
        $this->assertMatchesRegularExpression('/function revokeAll[^{]*\{\s*\$grants = MembershipRoleAssignment::query\(\)\s*->where\(\'school_membership_id\', \$membership->id\)\s*->staff\(\)/', $staff);
        $this->assertStringContainsString('->staff()', $this->code(app_path('Domain/Identity/Application/Staff/StaffAccountDirectory.php')));
    }

    #[Test]
    public function acting_guardian_is_never_cached(): void
    {
        $resolver = $this->code(app_path('Domain/Identity/Application/Portal/ActingGuardianResolver.php'));
        foreach (['Cache::', 'TenantCache', 'session(', 'remember('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $resolver);
        }
        $this->assertStringNotContainsString('Cache::', $this->code(app_path('Domain/Guardians/Application/GuardianStudentScope.php')));
    }
}
