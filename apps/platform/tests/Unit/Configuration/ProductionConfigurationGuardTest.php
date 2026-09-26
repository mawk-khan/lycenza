<?php

namespace Tests\Unit\Configuration;

use App\Support\Configuration\ProductionConfigurationException;
use App\Support\Configuration\ProductionConfigurationGuard;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0O.1: the production configuration guard, against an isolated
 * config repository (never the real .env or the running application).
 * The boot-level wiring is proven in
 * Tests\Feature\Configuration\ProductionBootSmokeTest.
 */
class ProductionConfigurationGuardTest extends TestCase
{
    private const CANARY_SIGNING_KEY = 'canary-signing-key-7f3a9c1e5b2d4f6a8c0e';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): Repository
    {
        $config = new Repository([
            'app' => ['url' => 'https://erp.example.org', 'debug' => false, 'key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'maintenance' => ['driver' => 'cache', 'store' => 'database']],
            'session' => ['secure' => true],
            'services' => ['ai_gateway' => ['context_signing_key' => self::CANARY_SIGNING_KEY, 'service_token' => null]],
            // Phase 0O.4A infrastructure baseline (ADR 0050 sections 2, 7, 8, 15).
            'trustedproxy' => ['proxies' => ['10.0.0.0/8']],
            // Phase 0O.5A (ADR 0051 §5, §9).
            'observability' => ['logging' => ['format' => 'json'], 'metrics' => ['scrape_token' => 'scrape-canary-2f6b9d1e4a7c0b3d5e8f1a2c4b6d8e0f']],
            'database' => [
                'default' => 'pgsql',
                'testing_database' => 'school_os_test',
                'connections' => ['pgsql' => ['sslmode' => 'require', 'database' => 'lycenza'], 'pgsql_admin' => ['sslmode' => 'verify-full', 'database' => 'lycenza']],
                'redis' => ['default' => ['password' => 'redis-canary-5d1e'], 'cache' => ['password' => 'redis-canary-5d1e']],
            ],
            'documents' => ['disk' => 's3'],
            'communications' => ['attachments' => ['disk' => 's3']],
            'filesystems' => ['disks' => ['s3' => ['bucket' => 'lycenza-production-objects', 'endpoint' => 'https://objects.example.net', 'key' => null, 'secret' => null]]],
        ]);

        foreach ($overrides as $key => $value) {
            $config->set($key, $value);
        }

        return $config;
    }

    #[Test]
    public function a_safe_production_configuration_passes(): void
    {
        $guard = new ProductionConfigurationGuard($this->config());
        $this->assertSame([], $guard->violations());
        $guard->assertSafe();

        // A real (non-development) service token is fine; so is none (the Gateway is optional).
        $this->assertSame([], (new ProductionConfigurationGuard($this->config(['services.ai_gateway.service_token' => 'a-real-token-value'])))->violations());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unsafe(): array
    {
        return [
            'debug on' => [['app.debug' => true], 'app_debug_enabled'],
            'debug truthy string' => [['app.debug' => '1'], 'app_debug_enabled'],
            'app key missing' => [['app.key' => null], 'app_key_missing'],
            'app key blank' => [['app.key' => ''], 'app_key_missing'],
            'app key wrong length' => [['app.key' => 'base64:'.base64_encode('short')], 'app_key_invalid'],
            'app key not base64' => [['app.key' => 'base64:!!!not-base64!!!'], 'app_key_invalid'],
            'app key raw wrong length' => [['app.key' => 'too-short'], 'app_key_invalid'],
            'session cookie not secure' => [['session.secure' => false], 'session_cookie_not_secure'],
            'session cookie unset' => [['session.secure' => null], 'session_cookie_not_secure'],
            'session cookie string true' => [['session.secure' => 'true'], 'session_cookie_not_secure'],
            'signing key missing' => [['services.ai_gateway.context_signing_key' => null], 'ai_context_signing_key_missing'],
            'signing key blank' => [['services.ai_gateway.context_signing_key' => '  '], 'ai_context_signing_key_missing'],
            'signing key dev placeholder' => [['services.ai_gateway.context_signing_key' => 'dev-local-only-context-signing-key-change-me'], 'ai_context_signing_key_placeholder'],
            'signing key test placeholder' => [['services.ai_gateway.context_signing_key' => 'test-only-context-signing-key'], 'ai_context_signing_key_placeholder'],
            'dev service token' => [['services.ai_gateway.service_token' => 'dev-local-only-token'], 'ai_service_token_development_value'],
            // Phase 0O.4A (ADR 0050 section 15).
            'proxies trust all (star)' => [['trustedproxy.proxies' => ['*']], 'trusted_proxies_unsafe'],
            'proxies trust all (ipv4 any)' => [['trustedproxy.proxies' => ['10.0.0.0/8', '0.0.0.0/0']], 'trusted_proxies_unsafe'],
            'proxies trust all (ipv6 any)' => [['trustedproxy.proxies' => ['::/0']], 'trusted_proxies_unsafe'],
            'proxies not a list' => [['trustedproxy.proxies' => null], 'trusted_proxies_unsafe'],
            'app url localhost' => [['app.url' => 'http://localhost'], 'environment_not_separated'],
            'app url missing' => [['app.url' => null], 'environment_not_separated'],
            'app url ddev' => [['app.url' => 'https://lycenza.ddev.site'], 'environment_not_separated'],
            'app url dot test' => [['app.url' => 'https://erp.test'], 'environment_not_separated'],
            'test database (runtime)' => [['database.connections.pgsql.database' => 'school_os_test'], 'environment_not_separated'],
            'test database (admin)' => [['database.connections.pgsql_admin.database' => 'school_os_test'], 'environment_not_separated'],
            'line log format' => [['observability.logging.format' => 'line'], 'log_format_not_structured'],
            'scrape token missing' => [['observability.metrics.scrape_token' => null], 'metrics_scrape_token_invalid'],
            'scrape token too short' => [['observability.metrics.scrape_token' => 'short-token'], 'metrics_scrape_token_invalid'],
            'scrape token placeholder' => [['observability.metrics.scrape_token' => 'dev-local-only-metrics-token-change-me-0000'], 'metrics_scrape_token_invalid'],
            'maintenance file driver' => [['app.maintenance.driver' => 'file'], 'maintenance_mode_not_shared'],
            'default connection sqlite' => [['database.default' => 'sqlite'], 'database_connection_not_pgsql'],
            'runtime db tls prefer' => [['database.connections.pgsql.sslmode' => 'prefer'], 'database_tls_not_required'],
            'runtime db tls disabled' => [['database.connections.pgsql.sslmode' => 'disable'], 'database_tls_not_required'],
            'admin db tls allow' => [['database.connections.pgsql_admin.sslmode' => 'allow'], 'database_tls_not_required'],
            'admin db tls unset' => [['database.connections.pgsql_admin.sslmode' => null], 'database_tls_not_required'],
            'redis password missing' => [['database.redis.default.password' => null], 'redis_password_missing'],
            'redis password literal null' => [['database.redis.default.password' => 'null'], 'redis_password_missing'],
            'redis cache password blank' => [['database.redis.cache.password' => ''], 'redis_password_missing'],
            'documents on local disk' => [['documents.disk' => 'local'], 'storage_disk_not_s3'],
            'attachments on local disk' => [['communications.attachments.disk' => 'local'], 'storage_disk_not_s3'],
            'bucket missing' => [['filesystems.disks.s3.bucket' => null], 'storage_bucket_not_production'],
            'local bucket' => [['filesystems.disks.s3.bucket' => 'school-os-local'], 'storage_bucket_not_production'],
            'test bucket' => [['filesystems.disks.s3.bucket' => 'school-os-test'], 'storage_bucket_not_production'],
            'plain http endpoint' => [['filesystems.disks.s3.endpoint' => 'http://minio:9000'], 'storage_endpoint_not_https'],
            'key without secret' => [['filesystems.disks.s3.key' => 'AKIAEXAMPLE'], 'storage_credentials_invalid'],
            'compose minio key' => [['filesystems.disks.s3.key' => 'school_os', 'filesystems.disks.s3.secret' => 'x-real-looking'], 'storage_credentials_invalid'],
            'compose minio secret' => [['filesystems.disks.s3.key' => 'AKIAEXAMPLE', 'filesystems.disks.s3.secret' => 'school_os_secret'], 'storage_credentials_invalid'],
            'minio default credentials' => [['filesystems.disks.s3.key' => 'minioadmin', 'filesystems.disks.s3.secret' => 'minioadmin'], 'storage_credentials_invalid'],
            'public visibility' => [['filesystems.disks.s3.visibility' => 'public'], 'storage_public_visibility'],
        ];
    }

    #[Test]
    public function infrastructure_values_that_are_safe_pass(): void
    {
        foreach ([
            ['trustedproxy.proxies' => []],
            ['trustedproxy.proxies' => ['10.0.0.0/8', '2001:db8::/32']],
            ['database.connections.pgsql.sslmode' => 'verify-ca'],
            ['filesystems.disks.s3.endpoint' => null],
            ['filesystems.disks.s3.endpoint' => 'HTTPS://objects.example.net'],
            ['filesystems.disks.s3.key' => 'AKIAEXAMPLE', 'filesystems.disks.s3.secret' => 'a-real-secret-value'],
            ['filesystems.disks.s3.visibility' => 'private'],
        ] as $overrides) {
            $this->assertSame([], (new ProductionConfigurationGuard($this->config($overrides)))->violations(), json_encode($overrides));
        }
    }

    #[Test]
    public function no_configuration_flag_can_claim_provider_side_storage_protection(): void
    {
        // Encryption at rest, versioning and public-access blocks are
        // provider evidence (platform:verify-storage, runbooks) -- never an
        // environment flag that could simply lie (ADR 0050 section 8).
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Support/Configuration/ProductionConfigurationGuard.php');
        foreach (['encrypt', 'versioning', 'public_access_block'] as $flag) {
            $this->assertDoesNotMatchRegularExpression("/config->get\\('[^']*{$flag}/i", $source);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('unsafe')]
    public function each_unsafe_value_is_refused_with_its_code(array $overrides, string $code): void
    {
        $this->assertSame([$code], (new ProductionConfigurationGuard($this->config($overrides)))->violations());

        $this->expectException(ProductionConfigurationException::class);
        (new ProductionConfigurationGuard($this->config($overrides)))->assertSafe();
    }

    #[Test]
    public function the_refusal_names_codes_only_and_never_a_value(): void
    {
        $appKeyCanary = 'canary-app-key-value-3c5e7a9b';
        $tokenCanary = 'dev-local-only-token';

        try {
            (new ProductionConfigurationGuard($this->config([
                'app.debug' => true,
                'app.key' => $appKeyCanary,
                'session.secure' => false,
                'services.ai_gateway.service_token' => $tokenCanary,
            ])))->assertSafe();
            $this->fail('An unsafe configuration must be refused.');
        } catch (ProductionConfigurationException $e) {
            $this->assertSame(['app_debug_enabled', 'app_key_invalid', 'session_cookie_not_secure', 'ai_service_token_development_value'], $e->violations);
            foreach ([$appKeyCanary, $tokenCanary, self::CANARY_SIGNING_KEY, 'redis-canary-5d1e', 'scrape-canary-2f6b9d1e4a7c0b3d5e8f1a2c4b6d8e0f'] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }
}
