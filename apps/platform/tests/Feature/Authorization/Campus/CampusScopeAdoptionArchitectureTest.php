<?php

namespace Tests\Feature\Authorization\Campus;

use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11.3, §33; CLAUDE.md rule 101): Campus adoption is
 * declared, centralized and fail-closed.
 *
 * - Every adopted capability is a real School capability of a declared
 *   domain with its NULL-Campus / inactive-Campus / lookup policy, and is
 *   never ORG-C2 whole-School authority.
 * - An adopted capability is never checked with the whole-School Gate in
 *   application code (that would silently ignore Campus grants) -- only
 *   through authorizeCampusScope / `,campus`. A whole-School `capability:`
 *   ROUTE floor on an adopted key is allowed only on a declared
 *   whole-School-only operation (ORG.7D-0, ADR 0076 §36): the registered
 *   route list is checked by CoverageGuard::adoptedWholeSchool().
 * - The adopted domains' controllers never query their Campus-bound models
 *   directly: the predicate lives in the domain access class.
 * - Campus scope is computed only by CampusScopeResolver, and
 *   TenantContext::campus() is never consulted for authority.
 */
class CampusScopeAdoptionArchitectureTest extends TestCase
{
    /** Domain => [access class file, controllers that must go through it, models they must not query]. */
    private const DOMAIN_PATHS = [
        'visitor' => [
            'access' => 'app/Domain/Visitor/Application/VisitorVisitAccess.php',
            'controllers' => ['app/Domain/Visitor/Http/Controllers/VisitorVisitController.php', 'app/Http/Controllers/App/VisitorVisitController.php'],
            'models' => ['VisitorVisit::query(', 'Campus::query('],
        ],
        'inventory' => [
            'access' => 'app/Domain/Inventory/Application/InventoryStockAccess.php',
            'controllers' => ['app/Domain/Inventory/Http/Controllers/InventoryStockController.php', 'app/Http/Controllers/App/InventoryStockController.php'],
            'models' => ['InventoryStockBalance::query(', 'StockMovement::query(', 'InventoryLocation::query('],
        ],
    ];

    #[Test]
    public function every_adopted_capability_is_a_declared_non_whole_school_school_capability(): void
    {
        $this->assertSame(array_keys(self::DOMAIN_PATHS), array_keys(CampusScopeCatalogue::DOMAINS));

        foreach (CampusScopeCatalogue::ADOPTED as $capability => $domain) {
            $this->assertArrayHasKey($domain, CampusScopeCatalogue::DOMAINS, "{$capability} names unknown domain {$domain}");
            $this->assertSame('school', DB::table('capabilities')->where('key', $capability)->value('namespace'), "{$capability} must be an existing School capability");
            foreach (['school.', 'integrations.', 'academics.years.', 'students.', 'enrollments.', 'guardians.', 'portal.'] as $forbidden) {
                $this->assertStringStartsNotWith($forbidden, $capability, "{$capability} is whole-School authority or an unadopted SIS family");
            }
        }
        foreach (CampusScopeCatalogue::DOMAINS as $domain => $policy) {
            foreach (['label', 'nullCampus', 'inactiveCampus', 'sharedLookups'] as $field) {
                $this->assertNotSame('', trim($policy[$field] ?? ''), "{$domain} lacks its {$field} policy");
            }
            $this->assertContains($domain, CampusScopeCatalogue::ADOPTED, "{$domain} adopts no capability");
        }
    }

    #[Test]
    public function an_adopted_capability_is_never_checked_with_a_whole_school_gate(): void
    {
        $offenders = [];
        foreach (Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php') as $file) {
            $source = $file->getContents();
            // Route floors are checked on the registered routes instead
            // (declared whole-School-only operations, ORG.7D-0).
            $routeFile = str_starts_with($file->getRealPath(), base_path('routes'));
            foreach (CampusScopeCatalogue::capabilities() as $capability) {
                $quoted = preg_quote($capability, '/');
                if (preg_match("/authorizeCapability(For)?\\(\\s*(\\\$[a-z]+,\\s*)?'{$quoted}'/", $source)
                    || (! $routeFile && preg_match("/'capability:{$quoted}'/", $source))
                    || preg_match("/->canInSchool\\([^)]*'{$quoted}'/", $source)) {
                    $offenders[] = $file->getRelativePathname()." checks {$capability} School-wide only";
                }
            }
        }

        $this->assertSame([], $offenders, 'Use authorizeCampusScope() / `capability:x,campus` for an adopted capability.');
    }

    #[Test]
    public function the_adopted_domains_query_only_through_their_access_class(): void
    {
        foreach (self::DOMAIN_PATHS as $domain => $paths) {
            $this->assertFileExists(base_path($paths['access']));
            $this->assertStringContainsString('->restrict(', (string) file_get_contents(base_path($paths['access'])), "{$domain} access class applies the CampusScope");
            foreach ($paths['controllers'] as $controller) {
                $source = (string) file_get_contents(base_path($controller));
                $this->assertStringContainsString('authorizeCampusScope(', $source, "{$controller} resolves the CampusScope");
                foreach ($paths['models'] as $direct) {
                    $this->assertStringNotContainsString($direct, $source, "{$controller} queries {$direct} directly instead of through its access class");
                }
            }
        }
    }

    #[Test]
    public function campus_scope_comes_only_from_the_resolver_and_never_from_context(): void
    {
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $path = $file->getRelativePathname();
            $source = $file->getContents();
            if (! str_ends_with($path, 'Campus/CampusScopeResolver.php') && ! str_ends_with($path, 'Campus/CampusScope.php')) {
                $this->assertDoesNotMatchRegularExpression('/CampusScope::(campuses|wholeSchool)\(/', $source, "{$path} builds a CampusScope itself");
            }
        }
        foreach (self::DOMAIN_PATHS as $paths) {
            foreach ([$paths['access'], ...$paths['controllers']] as $file) {
                $this->assertDoesNotMatchRegularExpression('/->campus\(\)|campusId\(\)/', (string) file_get_contents(base_path($file)), "{$file} reads a context Campus");
            }
        }
    }
}
