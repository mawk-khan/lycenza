<?php

namespace Tests\Feature\Identity\Access;

use App\Domain\Identity\Application\Access\AccessCatalogueReader;
use App\Domain\Identity\Application\Access\EffectiveAccessReader;
use App\Domain\Identity\Application\Access\SensitiveDataCatalogue;
use App\Domain\Platform\Application\Groups\GroupGovernanceAccess;
use App\Domain\Platform\Application\Groups\NetworkAccessService;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.12E (ADR 0080 §10) structural guards: Network authority stays on the
 * Group layer, School capability resolution is untouched by it, the Access
 * Center only reads, and the sensitive-data catalogue is explanation, never
 * an authority source or a hidden switch.
 */
class AccessArchitectureTest extends TestCase
{
    private function code(string $class): string
    {
        return $this->strip((string) file_get_contents((string) (new ReflectionClass($class))->getFileName()));
    }

    private function strip(string $source): string
    {
        return implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all($source),
        ));
    }

    #[Test]
    public function the_group_resolver_never_reaches_school_authority_or_tenant_data(): void
    {
        foreach ([GroupGovernanceAccess::class, NetworkAccessService::class] as $class) {
            $source = $this->code($class);
            foreach (['TenantContext', 'MembershipRoleAssignment', 'SchoolMembership', 'schoolCapabilities', 'campusCapabilities', 'withSchool', 'Cache::'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$class}: {$forbidden}");
            }
        }
        // School capability resolution is not contaminated by Network scope.
        $resolver = $this->code(CapabilityResolver::class);
        foreach (['region_id', 'zone_id', 'GroupGovernanceAccess', 'group.network'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $resolver, $forbidden);
        }
    }

    #[Test]
    public function only_group_layer_code_uses_the_network_resolver(): void
    {
        $allowed = ['app/Domain/Platform/Application/Groups/', 'app/Http/Controllers/App/Groups/', 'app/Http/Controllers/App/Platform/SchoolGroupAdminController.php'];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
            $source = $this->strip($file->getContents());
            if (! str_contains($source, 'GroupGovernanceAccess') && ! str_contains($source, 'NetworkAccessService')) {
                continue;
            }
            $this->assertTrue(collect($allowed)->contains(fn (string $prefix) => str_starts_with($relative, $prefix)), "{$relative} must not use Network governance authority");
        }
    }

    #[Test]
    public function the_access_center_readers_never_write(): void
    {
        foreach ([AccessCatalogueReader::class, EffectiveAccessReader::class, SensitiveDataCatalogue::class] as $class) {
            $source = $this->code($class);
            $this->assertDoesNotMatchRegularExpression('/->(create|insert|update|delete|forceFill|save|sync|attach|detach|upsert)\(/', $source, $class);
        }
    }

    #[Test]
    public function the_sensitive_catalogue_names_real_capabilities_offers_no_switch_and_grants_nothing(): void
    {
        $known = DB::table('capabilities')->pluck('key')->all();
        foreach (SensitiveDataCatalogue::FAMILIES as $key => $family) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $key);
            $this->assertNotEmpty($family['fields'], $key);
            foreach ($family['baseline'] as $capability) {
                $this->assertContains($capability, $known, "{$key}: {$capability} is not a capability");
            }
            $this->assertFalse($family['configurable'], "{$key}: no family is configurable until a central projection exists (ADR 0080 §10.8)");
            $this->assertNotEmpty($family['reason'], $key);
        }

        // Never read by authorization, navigation or middleware: explanation only.
        foreach ((new Finder)->files()->in([app_path('Support/Authorization'), app_path('Support/Navigation'), app_path('Http/Middleware')])->name('*.php') as $file) {
            $this->assertStringNotContainsString('SensitiveDataCatalogue', $file->getContents(), $file->getRelativePathname());
        }
        // No School sensitive-data policy store exists (deny-only policy is deferred).
        $this->assertSame([], DB::table('information_schema.tables')->whereRaw('table_schema = current_schema()')
            ->where('table_name', 'like', '%sensitive%')->pluck('table_name')->all());
    }
}
