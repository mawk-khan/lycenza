<?php

namespace Tests\Feature\Navigation;

use App\Http\Middleware\RequireSchoolContext;
use App\Support\Domains\SchoolHostSurface;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Navigation\NavigationEntry;
use App\Support\Navigation\StaffNavigation;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SWC.1 (ADR 0073 sections 6, 11): the integrity of the one navigation
 * catalogue -- every entry names a real GET page, an existing capability of
 * the right scope, and no role; gated surfaces never get an entry.
 */
class StaffNavigationCatalogueTest extends TestCase
{
    private function route(NavigationEntry $entry): Route
    {
        $route = RouteFacade::getRoutes()->getByName($entry->routeName);
        $this->assertNotNull($route, "[{$entry->key}] route {$entry->routeName} is not defined");

        return $route;
    }

    #[Test]
    public function keys_are_unique_and_every_entry_belongs_to_a_declared_group(): void
    {
        $keys = array_map(fn (NavigationEntry $e) => $e->key, StaffNavigation::entries());

        $this->assertSame(array_unique($keys), $keys, 'duplicate navigation key');
        $this->assertNotContains('home', $keys, '`home` is reserved for the landing link');

        foreach (StaffNavigation::entries() as $entry) {
            $this->assertArrayHasKey($entry->group, StaffNavigation::GROUPS, $entry->key);
            $this->assertSame($entry->group === StaffNavigation::ACCOUNT_GROUP, $entry->scope === NavigationEntry::SCOPE_ACCOUNT, $entry->key);
        }
    }

    #[Test]
    public function every_entry_is_a_get_page_with_a_controller_action(): void
    {
        foreach (StaffNavigation::entries() as $entry) {
            $route = $this->route($entry);

            $this->assertContains('GET', $route->methods(), $entry->key);
            $this->assertStringContainsString('@', $route->getActionName(), "[{$entry->key}] must resolve to a controller action");
            [$class, $method] = explode('@', $route->getActionName());
            $this->assertTrue(method_exists($class, $method), "[{$entry->key}] {$class}@{$method} does not exist");
            $this->assertStringStartsWith('app', $route->uri(), $entry->key);
        }
    }

    #[Test]
    public function every_school_entry_is_a_school_context_route_on_the_school_host_surface(): void
    {
        foreach (StaffNavigation::entries() as $entry) {
            $route = $this->route($entry);
            $middleware = RouteFacade::gatherRouteMiddleware($route);

            if ($entry->scope === NavigationEntry::SCOPE_SCHOOL) {
                $this->assertContains(RequireSchoolContext::class, $middleware, "[{$entry->key}] School entry outside the school-context group");
                $this->assertFalse($entry->platformHostOnly, $entry->key);
            }

            // ADR 0054 section 8.2: what a School host serves, it may advertise;
            // what it never serves, it never advertises.
            $this->assertSame(! $entry->platformHostOnly, SchoolHostSurface::admits($route->uri()), "[{$entry->key}] {$route->uri()} vs School host surface");
        }
    }

    #[Test]
    public function every_capability_exists_in_the_namespace_of_its_scope(): void
    {
        $namespaces = DB::table('capabilities')->pluck('namespace', 'key')->all();

        foreach (StaffNavigation::entries() as $entry) {
            if ($entry->scope === NavigationEntry::SCOPE_ACCOUNT) {
                $this->assertSame([], $entry->capabilities(), $entry->key);

                continue;
            }

            $this->assertNotSame([], $entry->capabilities(), "[{$entry->key}] needs a requirement");

            foreach ($entry->capabilities() as $capability) {
                $this->assertArrayHasKey($capability, $namespaces, "[{$entry->key}] unknown capability {$capability}");
                $expected = match ($entry->scope) {
                    NavigationEntry::SCOPE_PLATFORM => ['platform'],
                    NavigationEntry::SCOPE_GROUP => ['group'],
                    default => ['school', 'guardian'],
                };
                $this->assertContains($namespaces[$capability], $expected, "[{$entry->key}] {$capability}");
            }
        }
    }

    #[Test]
    public function navigation_never_names_a_role(): void
    {
        $roleKeys = DB::table('roles')->pluck('key')->all();
        $this->assertNotEmpty($roleKeys);

        foreach (StaffNavigation::entries() as $entry) {
            foreach ([$entry->key, $entry->routeName, ...$entry->capabilities()] as $value) {
                $this->assertNotContains($value, $roleKeys, "[{$entry->key}] references a role key");
            }
        }

        foreach (glob(app_path('Support/Navigation/*.php')) as $file) {
            $source = (string) file_get_contents($file);
            foreach ($roleKeys as $role) {
                $this->assertStringNotContainsString("'{$role}'", $source, basename($file)." names role {$role}");
            }
            $this->assertDoesNotMatchRegularExpression('/\\\\Models\\\\Role\b|->roles?\b|role_id|hasRole/', $source, basename($file).' reads roles');
        }
    }

    #[Test]
    public function gated_surfaces_have_no_entry_or_only_behind_their_availability_guard(): void
    {
        foreach (StaffNavigation::entries() as $entry) {
            // StudentMark (E35-E37), statutory payroll (E45): never a primary entry.
            $this->assertStringNotContainsString('marks', $entry->routeName, $entry->key);
            $this->assertStringNotContainsString('statutory', $entry->routeName, $entry->key);
            foreach ($entry->capabilities() as $capability) {
                $this->assertFalse(Str::startsWith($capability, ['examinations.marks.', 'payroll.statutory.']), "[{$entry->key}] {$capability}");
            }

            // The Guardian portal (E46): only behind PortalAvailability + ActingGuardian.
            if (Str::startsWith($entry->routeName, 'app.portal.')) {
                $this->assertSame(NavigationBuilder::AVAILABILITY_GUARDIAN_PORTAL, $entry->availability, $entry->key);
            }
        }
    }

    #[Test]
    public function every_declared_active_pattern_matches_a_defined_route(): void
    {
        $names = collect(RouteFacade::getRoutes()->getRoutes())->map(fn (Route $r) => $r->getName())->filter()->values();

        // Declared patterns only: the default `<route>.*` child pattern may
        // legitimately match nothing yet.
        foreach (StaffNavigation::entries() as $entry) {
            foreach ($entry->activeWhen as $pattern) {
                $this->assertTrue($names->contains(fn (string $name) => Str::is($pattern, $name)), "[{$entry->key}] pattern {$pattern} matches no route");
            }
        }
    }

    #[Test]
    public function the_nine_previously_unlinked_modules_are_now_discoverable(): void
    {
        $routes = array_map(fn (NavigationEntry $e) => $e->routeName, StaffNavigation::entries());

        foreach ([
            'app.attendance.index', 'app.examinations.index', 'app.hostels.index', 'app.inventory-items.index',
            'app.library.titles.index', 'app.transport.routes.index', 'app.visitor.directory.index',
            'app.syllabus.index', 'app.syllabus-delivery.index', 'app.learning-content.index', 'app.assignments.index',
        ] as $route) {
            $this->assertContains($route, $routes);
        }
    }
}
