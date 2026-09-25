<?php

namespace Tests\Feature\Api\Hardening;

use App\Support\Api\ApiScope;
use App\Support\Api\PartnerScopeRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 section 18): structural guards that fail on the
 * shape of a change that would quietly widen the external surface.
 */
class ApiHardeningArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    #[Test]
    public function scope_catalogs_are_closed_and_have_no_wildcard(): void
    {
        $this->assertSame(['api.read', 'api.write'], ApiScope::HUMAN);
        $this->assertSame([], PartnerScopeRegistry::PRODUCTION, 'No production partner scope is approved (O15).');
        $this->assertSame(['local', 'testing'], PartnerScopeRegistry::PROBE_ENVIRONMENTS);

        foreach ([...ApiScope::HUMAN, PartnerScopeRegistry::PROBE] as $scope) {
            $this->assertDoesNotMatchRegularExpression('/\*|^all$|admin/', $scope);
        }

        foreach ($this->phpFiles(app_path()) as $file) {
            $this->assertStringNotContainsString("'academic_structure.read'", (string) file_get_contents($file), "{$file}: academic_structure.read is not approved");
        }
    }

    #[Test]
    public function human_tokens_are_minted_only_through_the_safe_create_token(): void
    {
        foreach ($this->phpFiles(app_path()) as $file) {
            $code = (string) file_get_contents($file);
            if (str_contains($code, 'tokens()->create(')) {
                $this->assertStringEndsWith('Models/User.php', $file);
            }
            if (str_contains($code, '->createToken(')) {
                $this->assertStringEndsWith('Support/Api/HumanApiTokenService.php', $file, 'Only HumanApiTokenService mints human tokens.');
            }
        }

        $this->assertSame(60 * 24 * 90, config('sanctum.expiration'));
    }

    #[Test]
    public function no_blanket_proxy_or_host_trust_is_configured(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        // Phase 0O.4A (ADR 0050 section 2): the framework middleware is
        // replaced by one trusting exactly TRUSTED_PROXIES -- never `*`.
        $this->assertStringNotContainsString('trustProxies', $bootstrap, 'Proxies come only from TRUSTED_PROXIES (TrustedProxyList), never `*`.');
        $this->assertStringContainsString('replace(TrustProxies::class, TrustConfiguredProxies::class)', $bootstrap);
        $this->assertStringNotContainsString('trustHosts', $bootstrap, 'Trusted hosts wait for O9.');
    }

    #[Test]
    public function partner_credentials_are_never_stored_in_clear(): void
    {
        $migration = (string) file_get_contents(database_path('migrations/2026_10_20_090000_create_api_clients_tables.php'));
        $this->assertStringContainsString("secret_hash ~ '^[0-9a-f]{64}$'", $migration);
        $this->assertDoesNotMatchRegularExpression("/->(string|text|char)\\('secret'/", $migration);

        $service = (string) file_get_contents(app_path('Support/ApiClients/ApiClientService.php'));
        $this->assertStringContainsString("'secret_hash' => PartnerCredentialFormat::hash(", $service);
    }
}
