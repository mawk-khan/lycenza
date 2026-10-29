<?php

namespace Tests\Feature\Organization;

use App\Domain\Platform\Application\Groups\NetworkBrandService;
use App\Domain\Schools\Application\SchoolPresentationService;
use App\Support\Navigation\NavigationPresentation;
use App\Support\Navigation\NavigationPresentationCatalogue as Catalogue;
use App\Support\Navigation\StaffNavigation;
use App\Support\Presentation\BrandResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.12F (ADR 0080 §11) structural guards: presentation is an OUTPUT of
 * security, never an input to it.
 */
class PresentationArchitectureTest extends TestCase
{
    private function code(string $path): string
    {
        return implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all((string) file_get_contents($path)),
        ));
    }

    #[Test]
    public function authorization_never_reads_presentation(): void
    {
        $paths = [app_path('Support/Authorization'), app_path('Http/Middleware'), app_path('Providers')];
        foreach ((new Finder)->files()->in($paths)->name('*.php') as $file) {
            if ($file->getFilename() === 'HandleInertiaRequests.php') {
                continue; // shares the effective brand with the shell (presentation only)
            }
            $source = $this->code($file->getRealPath());
            foreach (['school_navigation_preferences', 'school_brand_profiles', 'school_group_brand_profiles', 'NavigationPresentation', 'BrandResolver'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$file->getRelativePathname()} reads presentation");
            }
        }
        // The security filter in NavigationBuilder::visibleEntries() is unchanged by presentation:
        // presentation is applied only in forRequest(), after it.
        $builder = $this->code(app_path('Support/Navigation/NavigationBuilder.php'));
        $visible = substr($builder, strpos($builder, 'public function visibleEntries'), strpos($builder, 'private function campusAware') - strpos($builder, 'public function visibleEntries'));
        $this->assertStringNotContainsString('NavigationPresentation', $visible);
    }

    #[Test]
    public function presentation_only_drops_or_relabels_entries_it_was_given(): void
    {
        $source = $this->code((new ReflectionClass(NavigationPresentation::class))->getFileName());
        $this->assertStringNotContainsString('new NavigationEntry', $source);
        $this->assertStringNotContainsString('new E(', $source);
        $this->assertStringNotContainsString('StaffNavigation::entries', $source, 'it never pulls in entries security did not pass');
        foreach (['routeName', 'capabilities()', '->all', '->any', 'scope'] as $authority) {
            $this->assertStringNotContainsString("\$override->{$authority}", $source);
        }
    }

    #[Test]
    public function keys_are_code_owned_and_audiences_are_only_implemented_surfaces(): void
    {
        $this->assertSame(['staff_web', 'guardian_portal'], Catalogue::AUDIENCES, 'no Student portal or mobile audience');
        $check = (string) DB::selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'school_navigation_preferences_audience_check'")->d;
        preg_match_all("/'([a-z_]+)'::/", $check, $m);
        $this->assertSame(Catalogue::AUDIENCES, $m[1]);

        foreach (StaffNavigation::entries() as $entry) {
            $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{0,63}$/', $entry->key, 'every key fits the stored key format');
        }
        foreach (array_keys(Catalogue::REQUIRED) as $key) {
            $this->assertTrue(Catalogue::isKnown(Catalogue::STAFF_WEB, $key) || Catalogue::isKnown(Catalogue::GUARDIAN_PORTAL, $key), "required key {$key} exists");
        }
    }

    #[Test]
    public function preferences_are_per_school_and_audience_never_per_role_user_or_campus(): void
    {
        $columns = Schema::getColumnListing('school_navigation_preferences');
        foreach (['role_id', 'role_key', 'user_id', 'campus_id', 'membership_id', 'capability', 'route', 'module'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
        foreach (['school_brand_profiles', 'school_group_brand_profiles'] as $table) {
            foreach (['css', 'html', 'script', 'url', 'logo_path', 'campus_id'] as $forbidden) {
                $this->assertNotContains($forbidden, Schema::getColumnListing($table), "{$table}.{$forbidden}");
            }
        }
    }

    #[Test]
    public function branding_is_explicit_and_never_decided_by_relationship_or_written_by_a_network(): void
    {
        foreach ([BrandResolver::class, SchoolPresentationService::class, NetworkBrandService::class] as $class) {
            $this->assertStringNotContainsString('relationship_type', $this->code((new ReflectionClass($class))->getFileName()), $class);
        }
        $network = $this->code((new ReflectionClass(NetworkBrandService::class))->getFileName());
        foreach (['school_brand_profiles', 'school_navigation_preferences', "table('schools')"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $network, 'a Network never writes a School row');
        }
    }

    #[Test]
    public function no_markup_is_rendered_from_presentation_values(): void
    {
        foreach (['Layouts/AccountLayout.vue', 'Pages/App/SchoolSetup/Presentation.vue', 'Pages/App/Groups/Show.vue', 'Components/Navigation/NavigationGroupList.vue'] as $file) {
            $this->assertStringNotContainsString('v-html', (string) file_get_contents(resource_path("js/{$file}")), $file);
        }
    }
}
