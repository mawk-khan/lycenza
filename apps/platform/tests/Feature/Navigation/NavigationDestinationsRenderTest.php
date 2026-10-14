<?php

namespace Tests\Feature\Navigation;

use App\Support\Navigation\NavigationEntry;
use App\Support\Navigation\StaffNavigation;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Platform\Elevation\ElevationTestHelpers;
use Tests\TestCase;

/**
 * SWC.1 (ADR 0073 section 11): every advertised destination renders for an
 * actor holding EXACTLY its navigation requirement (and nothing else) --
 * so the catalogue's requirement is the page's own authorization, the
 * page component exists, and the entry is offered to that same actor.
 */
class NavigationDestinationsRenderTest extends TestCase
{
    use CreatesTenancyFixtures, ElevationTestHelpers;

    /** Pages that additionally require current MFA assurance (rule 95), rendered with it. */
    private const MFA_PAGES = ['my-attendance', 'api-clients', 'platform-audit-log'];

    private function assertRendersInertiaPage(string $uri, string $key): void
    {
        $response = $this->get($uri);

        $this->assertSame(200, $response->getStatusCode(), "[{$key}] {$uri}");
        $component = $response->viewData('page')['component'] ?? null;
        $this->assertIsString($component, "[{$key}] is not an Inertia page");
        $this->assertFileExists(resource_path("js/Pages/{$component}.vue"), "[{$key}] page component {$component}");
    }

    #[Test]
    public function every_school_entry_renders_for_a_holder_of_exactly_its_requirement(): void
    {
        $school = $this->createSchool();

        foreach (StaffNavigation::entries() as $entry) {
            if ($entry->scope !== NavigationEntry::SCOPE_SCHOOL || $entry->availability !== null) {
                continue;
            }

            $capabilities = [...$entry->all, ...array_slice($entry->any, 0, 1)];
            $user = $this->createUserWithCapabilities($school, $capabilities);

            $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
            $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: [$entry->key])));

            if (in_array($entry->key, self::MFA_PAGES, true)) {
                // E33 (ADR 0063 section 43) and partner API clients (ADR 0049):
                // these pages open only with current MFA assurance.
                $this->enrollActiveMfaFactor($user);
                session(['mfa_verified_at' => now()->toIso8601String()]);
            }

            $this->assertRendersInertiaPage(route($entry->routeName, absolute: false), $entry->key);
        }
    }

    #[Test]
    public function every_platform_entry_renders_for_the_platform_root(): void
    {
        $root = $this->platformAdmin();
        $this->actingAs($root);
        // Platform audit review needs current MFA assurance (ADR 0046).
        session(['mfa_verified_at' => now()->toIso8601String()]);

        foreach (StaffNavigation::entries() as $entry) {
            if ($entry->scope !== NavigationEntry::SCOPE_PLATFORM && $entry->scope !== NavigationEntry::SCOPE_ACCOUNT) {
                continue;
            }

            $this->get('/app')->assertInertia(fn ($page) => $page->where('navigation', self::nav(shown: [$entry->key])));
            $this->assertRendersInertiaPage(route($entry->routeName, absolute: false), $entry->key);
        }
    }

    #[Test]
    public function the_public_home_page_exists_and_the_development_status_page_is_retired(): void
    {
        $pages = collect(File::allFiles(resource_path('js/Pages')))->map(fn ($f) => $f->getRelativePathname())->all();

        $this->assertContains('Home.vue', $pages);
        $this->assertNotContains('SystemStatus.vue', $pages, 'the development-era status page is retired');
    }
}
