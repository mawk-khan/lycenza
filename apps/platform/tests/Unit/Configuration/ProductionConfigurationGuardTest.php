<?php

namespace Tests\Unit\Configuration;

use App\Support\Configuration\ProductionConfigurationException;
use App\Support\Configuration\ProductionConfigurationGuard;
use App\Support\ServiceAuth\ServiceAuthContract;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\GeneratesServiceKeys;

/**
 * Phase 0O.1: the production configuration guard, against an isolated
 * config repository (never the real .env or the running application).
 * The boot-level wiring is proven in
 * Tests\Feature\Configuration\ProductionBootSmokeTest.
 */
class ProductionConfigurationGuardTest extends TestCase
{
    use GeneratesServiceKeys;

    private const CANARY_SIGNING_KEY = 'canary-signing-key-7f3a9c1e5b2d4f6a8c0e';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): Repository
    {
        $config = new Repository([
            'app' => ['url' => 'https://erp.example.org', 'debug' => false, 'key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'maintenance' => ['driver' => 'cache', 'store' => 'database']],
            'session' => ['secure' => true],
            'services' => ['ai_gateway' => ['context_signing_key' => self::CANARY_SIGNING_KEY, 'legacy_service_token_configured' => false, 'base_url' => null]],
            'cache' => ['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis'], 'array' => ['driver' => 'array']]],
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

        // ADR 0053: a configured Gateway with sound service keys is fine too.
        $this->assertSame([], (new ProductionConfigurationGuard($this->config($this->gatewayConfigured())))->violations());
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
            'legacy shared service token set (ADR 0053)' => [['services.ai_gateway.legacy_service_token_configured' => true], 'ai_legacy_service_token_configured'],
            // Phase 0O.4A (ADR 0050 section 15).
            'proxies trust all (star)' => [['trustedproxy.proxies' => ['*']], 'trusted_proxies_unsafe'],
            'proxies trust all (ipv4 any)' => [['trustedproxy.proxies' => ['10.0.0.0/8', '0.0.0.0/0']], 'trusted_proxies_unsafe'],
            'proxies trust all (ipv6 any)' => [['trustedproxy.proxies' => ['::/0']], 'trusted_proxies_unsafe'],
            'proxies not a list' => [['trustedproxy.proxies' => null], 'trusted_proxies_unsafe'],
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
            // E21-RH.2 (ADR 0066 §5): a configured retention credential is its own login, over TLS.
            'retention login = runtime login' => [['database.connections.pgsql.username' => 'school_os_app', 'database.connections.pgsql_retention.username' => 'school_os_app', 'database.connections.pgsql_retention.sslmode' => 'require'], 'retention_identity_not_distinct'],
            'retention login = migration login' => [['database.connections.pgsql_admin.username' => 'lycenza_owner', 'database.connections.pgsql_retention.username' => 'lycenza_owner', 'database.connections.pgsql_retention.sslmode' => 'require'], 'retention_identity_not_distinct'],
            'retention db tls prefer' => [['database.connections.pgsql_retention.username' => 'school_os_retention', 'database.connections.pgsql_retention.sslmode' => 'prefer'], 'database_tls_not_required'],
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
            // E21-RH.2: a distinct retention login over TLS (an unset one is simply absent and fails safe at run time).
            ['database.connections.pgsql_retention.username' => 'school_os_retention', 'database.connections.pgsql_retention.sslmode' => 'require'],
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
     * Phase 0O.8A (ADR 0054): the platform origin, host-only cookies and the
     * Host boundary's own switches.
     */
    #[Test]
    public function the_host_boundary_and_cookie_scope_are_enforced(): void
    {
        $violations = fn (array $overrides) => (new ProductionConfigurationGuard($this->config($overrides)))->violations();

        $this->assertSame(['environment_not_separated', 'platform_url_invalid'], $violations(['app.url' => 'http://localhost']));
        $this->assertSame(['environment_not_separated', 'platform_url_invalid'], $violations(['app.url' => null]));
        $this->assertSame(['platform_url_invalid'], $violations(['app.url' => 'http://erp.example.org']), 'the platform origin is https');
        $this->assertSame(['platform_url_invalid'], $violations(['app.url' => 'https://10.0.0.5']), 'the platform origin is a hostname');
        $this->assertSame(['session_domain_shared'], $violations(['session.domain' => '.example.org']), 'no parent-domain cookie');
        $this->assertSame([], $violations(['session.domain' => null]));
        $this->assertSame(['domain_development_hosts_enabled'], $violations(['domains.allow_development_hosts' => true]));
        $this->assertSame(['domain_fakes_enabled'], $violations(['domains.fakes' => true]));
        $this->assertSame(['domain_host_list_invalid'], $violations(['domains.platform_aliases' => ['*.example.org']]));
        $this->assertSame(['domain_host_list_invalid'], $violations(['domains.internal_hosts' => ['web']]), 'single-label names are local-only');
        $this->assertSame(['domain_host_list_invalid'], $violations(['domains.reserved_suffixes' => ['Example.ORG']]), 'lists are written canonically');
        $this->assertSame(['session_handoff_store_not_redis'], $violations(['domains.handoff_store' => 'array']));
        $this->assertSame([], $violations(['domains.platform_aliases' => ['www.example.org'], 'domains.internal_hosts' => ['platform-internal.svc.example']]));
    }

    /**
     * Enabled custom domains need the edge, a real probe key and public
     * resolvers; disabled is a complete mode that needs none of them.
     */
    #[Test]
    public function enabled_custom_domains_need_edge_probe_key_and_public_resolvers(): void
    {
        $violations = fn (array $overrides) => (new ProductionConfigurationGuard($this->config(['domains.enabled' => true, ...$overrides])))->violations();
        $sound = [
            'domains.edge.cname_target' => 'edge.lycenza-cdn.example',
            'domains.edge.addresses' => ['198.41.0.4'],
            'domains.probe_key' => str_repeat('probe-canary-', 4),
            'domains.dns.resolvers' => ['9.9.9.9', '2620:fe::fe'],
        ];

        $this->assertSame([], $violations($sound));
        $this->assertSame([], (new ProductionConfigurationGuard($this->config(['domains.enabled' => false])))->violations(), 'disabled needs nothing');
        $this->assertSame(['domain_edge_target_missing'], $violations([...$sound, 'domains.edge.cname_target' => null, 'domains.edge.addresses' => []]));
        $this->assertSame(['domain_edge_target_invalid'], $violations([...$sound, 'domains.edge.cname_target' => 'https://edge.example']));
        $this->assertSame(['domain_edge_addresses_invalid'], $violations([...$sound, 'domains.edge.addresses' => ['10.1.2.3']]), 'never a private edge');
        $this->assertSame(['domain_probe_key_invalid'], $violations([...$sound, 'domains.probe_key' => null]));
        $this->assertSame(['domain_probe_key_invalid'], $violations([...$sound, 'domains.probe_key' => 'short']));
        $this->assertSame(['domain_probe_key_invalid'], $violations([...$sound, 'domains.probe_key' => 'dev-local-only-domain-probe-key-change-me-0000']));
        $this->assertSame(['domain_dns_resolvers_missing'], $violations([...$sound, 'domains.dns.resolvers' => []]));
        $this->assertSame(['domain_dns_resolvers_invalid'], $violations([...$sound, 'domains.dns.resolvers' => ['10.0.0.2']]), 'never a split-horizon resolver');
        $this->assertSame(['domain_dns_resolvers_invalid'], $violations([...$sound, 'domains.dns.resolvers' => ['resolver.example']]));

        try {
            $canary = 'dev-'.str_repeat('probe-canary-', 3);
            (new ProductionConfigurationGuard($this->config(['domains.enabled' => true, ...$sound, 'domains.probe_key' => $canary])))->assertSafe();
            $this->fail('refused');
        } catch (ProductionConfigurationException $e) {
            $this->assertStringNotContainsString('probe-canary', $e->getMessage());
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
        $key = $this->serviceKey('platform-canary-1');

        try {
            (new ProductionConfigurationGuard($this->config([
                'app.debug' => true,
                'app.key' => $appKeyCanary,
                'session.secure' => false,
                'services.ai_gateway.legacy_service_token_configured' => true,
                'services.ai_gateway.base_url' => 'http://gateway.internal:8100',
                'services.ai_gateway.service_signing_key' => $this->privateJwk($key, ['created' => '2020-01-01']),
            ])))->assertSafe();
            $this->fail('An unsafe configuration must be refused.');
        } catch (ProductionConfigurationException $e) {
            $this->assertSame(['app_debug_enabled', 'app_key_invalid', 'session_cookie_not_secure', 'ai_legacy_service_token_configured', 'ai_gateway_url_not_https', 'ai_service_signing_key_expired', 'ai_service_verification_keys_missing', 'internal_hosts_missing'], $e->violations);
            foreach ([$appKeyCanary, $tokenCanary, self::CANARY_SIGNING_KEY, 'redis-canary-5d1e', 'scrape-canary-2f6b9d1e4a7c0b3d5e8f1a2c4b6d8e0f', $key['d'], $key['x']] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    /**
     * ADR 0053 section 7: a configured AI Gateway with runtime-generated keys.
     *
     * @return array<string, mixed>
     */
    /** A throwaway canary assembled at run time (no secret-shaped literal in the source). */
    private static function emailCanary(string $prefix): string
    {
        return $prefix.'-canary-'.substr(hash('sha256', $prefix), 0, 24);
    }

    /**
     * Phase 0O.9A (ADR 0055 section 20): a sound production SMTP email setup.
     *
     * @return array<string, mixed>
     */
    private function emailEnabled(array $overrides = []): array
    {
        return [
            'email.provider' => 'smtp',
            'email.sending_domain' => 'notify.lycenza.example',
            'mail.default' => 'smtp',
            'mail.mailers' => ['smtp' => ['transport' => 'smtp'], 'log' => ['transport' => 'log'], 'failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]],
            'mail.from.address' => 'notifications@notify.lycenza.example',
            'email.suppression' => ['key' => self::emailCanary('prod-suppression'), 'key_id' => 'k-2026-10', 'previous_key' => null, 'previous_key_id' => null],
            'email.smtp' => ['host' => 'smtp.provider.example', 'port' => 587, 'username' => 'lycenza', 'password' => self::emailCanary('smtp'), 'tls' => 'required', 'timeout_seconds' => 5],
            'email.events' => ['adapter' => 'none', 'secrets' => []],
            'email.tracking' => ['opens' => false, 'clicks' => false],
            'email.debug' => false,
            ...$overrides,
        ];
    }

    #[Test]
    public function disabled_email_is_a_complete_safe_mode_and_a_sound_smtp_setup_passes(): void
    {
        $this->assertSame([], (new ProductionConfigurationGuard($this->config(['email.provider' => 'none', 'mail.default' => 'log'])))->violations());
        $this->assertSame([], (new ProductionConfigurationGuard($this->config($this->emailEnabled())))->violations());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function unsafeEmail(): array
    {
        return [
            'fake provider' => [['email.provider' => 'fake'], 'mail_provider_fake'],
            'unknown provider' => [['email.provider' => 'ses'], 'mail_provider_invalid'],
            'failover default mailer' => [['mail.default' => 'failover'], 'mail_unsafe_mailer_default'],
            'log default while enabled' => [['mail.default' => 'log'], 'mail_laravel_default_is_sink'],
            'array default while enabled' => [['mail.default' => 'array'], 'mail_laravel_default_is_sink'],
            'fake event adapter' => [['email.events' => ['adapter' => 'fake', 'secrets' => [self::emailCanary('event-secret')]]], 'mail_events_adapter_fake'],
            'provider debug' => [['email.debug' => true], 'mail_provider_debug_enabled'],
            'open tracking' => [['email.tracking' => ['opens' => true, 'clicks' => false]], 'mail_tracking_enabled'],
            'click tracking' => [['email.tracking' => ['opens' => false, 'clicks' => true]], 'mail_tracking_enabled'],
            'missing sending domain' => [['email.sending_domain' => ''], 'mail_sending_domain_missing'],
            'local sending domain' => [['email.sending_domain' => 'notify.lycenza.test', 'mail.from.address' => 'notifications@notify.lycenza.test'], 'mail_sending_domain_invalid'],
            'placeholder from' => [['mail.from.address' => 'hello@example.com'], 'mail_from_not_catalog'],
            'from outside the catalog' => [['mail.from.address' => 'ceo@notify.lycenza.example'], 'mail_from_not_catalog'],
            'no suppression key' => [['email.suppression' => ['key' => null, 'key_id' => null]], 'mail_suppression_keys_invalid'],
            'placeholder suppression key' => [['email.suppression' => ['key' => 'dev-local-only-mail-suppression-hmac-key-change-me', 'key_id' => 'dev-1']], 'mail_suppression_keys_invalid'],
            'mailpit host' => [['email.smtp' => ['host' => 'mailpit', 'port' => 1025, 'username' => 'u', 'password' => 'p', 'tls' => 'required', 'timeout_seconds' => 5]], 'mail_smtp_host_local'],
            'loopback host' => [['email.smtp' => ['host' => '127.0.0.1', 'port' => 1025, 'username' => 'u', 'password' => 'p', 'tls' => 'required', 'timeout_seconds' => 5]], 'mail_smtp_host_local'],
            'plaintext smtp' => [['email.smtp' => ['host' => 'smtp.provider.example', 'port' => 25, 'username' => 'u', 'password' => 'p', 'tls' => 'none', 'timeout_seconds' => 5]], 'mail_smtp_tls_not_required'],
            'no credentials' => [['email.smtp' => ['host' => 'smtp.provider.example', 'port' => 587, 'username' => null, 'password' => null, 'tls' => 'required', 'timeout_seconds' => 5]], 'mail_smtp_credentials_missing'],
            'unbounded timeout' => [['email.smtp' => ['host' => 'smtp.provider.example', 'port' => 587, 'username' => 'u', 'password' => 'p', 'tls' => 'required', 'timeout_seconds' => 0]], 'mail_smtp_timeout_unbounded'],
            'slow timeout' => [['email.smtp' => ['host' => 'smtp.provider.example', 'port' => 587, 'username' => 'u', 'password' => 'p', 'tls' => 'required', 'timeout_seconds' => 30]], 'mail_smtp_timeout_unbounded'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeEmail')]
    public function each_unsafe_email_value_is_refused_with_its_code(array $overrides, string $code): void
    {
        $this->assertContains($code, (new ProductionConfigurationGuard($this->config($this->emailEnabled($overrides))))->violations());
    }

    #[Test]
    public function an_event_adapter_needs_a_bounded_secret_ring(): void
    {
        // The only adapter today is the fake (refused); the ring rule is still
        // enforced for whichever adapter a vendor selection adds.
        foreach ([[], ['short'], [self::emailCanary('event-a'), self::emailCanary('event-b'), self::emailCanary('event-c')]] as $ring) {
            $violations = (new ProductionConfigurationGuard($this->config($this->emailEnabled(['email.events' => ['adapter' => 'fake', 'secrets' => $ring]]))))->violations();
            $this->assertContains('mail_event_secrets_invalid', $violations, json_encode(count($ring)));
        }
    }

    #[Test]
    public function email_refusals_name_codes_only_never_a_value(): void
    {
        try {
            (new ProductionConfigurationGuard($this->config($this->emailEnabled(['email.smtp' => ['host' => 'mailpit', 'port' => 1025, 'username' => 'u', 'password' => self::emailCanary('smtp'), 'tls' => 'none', 'timeout_seconds' => 5]]))))->assertSafe();
            $this->fail('unsafe email configuration must be refused');
        } catch (ProductionConfigurationException $e) {
            $this->assertStringNotContainsString('smtp-canary', $e->getMessage());
            $this->assertStringNotContainsString('mailpit', $e->getMessage());
            $this->assertStringNotContainsString('prod-suppression-canary', $e->getMessage());
        }
    }

    private function gatewayConfigured(array $overrides = []): array
    {
        $this->platformKey ??= $this->serviceKey('platform-20260901-1', CarbonImmutable::now('UTC')->subDays(30)->toDateString());
        $this->gatewayKey ??= $this->serviceKey('ai-gateway-20260901-1', CarbonImmutable::now('UTC')->subDays(30)->toDateString());

        return [
            'services.ai_gateway.base_url' => 'https://gateway.internal',
            'services.ai_gateway.service_signing_key' => $this->privateJwk($this->platformKey),
            'services.ai_gateway.inbound_verification_keys' => $this->ring([$this->publicJwk($this->gatewayKey)]),
            'services.ai_gateway.replay_store' => null,
            // ADR 0054 section 8.3: the Gateway calls the internal routes on a
            // private internal host, which the Host boundary must know.
            'domains.internal_hosts' => ['platform-internal.lycenza.example'],
            ...$overrides,
        ];
    }

    /** @var array{kid: string, created: string, x: string, d: string}|null */
    private ?array $platformKey = null;

    /** @var array{kid: string, created: string, x: string, d: string}|null */
    private ?array $gatewayKey = null;

    #[Test]
    public function a_configured_ai_gateway_needs_sound_service_authentication(): void
    {
        $old = $this->serviceKey('platform-old-1', CarbonImmutable::now('UTC')->subDays(91)->toDateString());
        $dev = $this->serviceKey('dev-local-only-platform-9');
        $devRing = $this->serviceKey('dev-local-only-ai-gateway-9');
        $a = $this->serviceKey('ai-gateway-a');
        $b = $this->serviceKey('ai-gateway-b');
        $soon = CarbonImmutable::now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z');
        $tooFar = CarbonImmutable::now('UTC')->addHours(25)->format('Y-m-d\TH:i:s\Z');

        $cases = [
            'ai_gateway_url_not_https' => ['services.ai_gateway.base_url' => 'http://gateway.internal:8100'],
            'ai_service_signing_key_missing' => ['services.ai_gateway.service_signing_key' => null],
            'ai_service_signing_key_invalid' => ['services.ai_gateway.service_signing_key' => '{"kty":"OKP"}'],
            'ai_service_signing_key_development' => ['services.ai_gateway.service_signing_key' => $this->privateJwk($dev)],
            'ai_service_signing_key_expired' => ['services.ai_gateway.service_signing_key' => $this->privateJwk($old)],
            'ai_service_verification_keys_missing' => ['services.ai_gateway.inbound_verification_keys' => ''],
            'ai_service_verification_keys_development' => ['services.ai_gateway.inbound_verification_keys' => $this->ring([$this->publicJwk($devRing)])],
            'ai_service_verification_keys_too_many' => ['services.ai_gateway.inbound_verification_keys' => $this->ring([$this->publicJwk($a), $this->publicJwk($b, ['not_after' => $soon]), $this->publicJwk($this->serviceKey('ai-gateway-c'), ['not_after' => $soon])])],
            'ai_service_verification_keys_duplicate_kid' => ['services.ai_gateway.inbound_verification_keys' => $this->ring([$this->publicJwk($a), $this->publicJwk($a, ['not_after' => $soon])])],
            'ai_service_verification_key_transition_too_long' => ['services.ai_gateway.inbound_verification_keys' => $this->ring([$this->publicJwk($a), $this->publicJwk($b, ['not_after' => $tooFar])])],
            'ai_service_verification_keys_private_material' => ['services.ai_gateway.inbound_verification_keys' => $this->ring([[...$this->publicJwk($a), 'd' => $a['d']]])],
            'ai_service_replay_store_not_redis' => ['services.ai_gateway.replay_store' => 'array'],
        ];

        foreach ($cases as $code => $overrides) {
            $this->assertSame([$code], (new ProductionConfigurationGuard($this->config($this->gatewayConfigured($overrides))))->violations(), $code);
        }

        // The committed development PUBLIC keys are refused under any kid.
        $committed = $this->ring([['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'renamed-1', 'x' => ServiceAuthContract::DEVELOPMENT_PUBLIC_KEYS[1], 'created' => '2026-09-27']]);
        $this->assertSame(['ai_service_verification_keys_development'], (new ProductionConfigurationGuard($this->config($this->gatewayConfigured(['services.ai_gateway.inbound_verification_keys' => $committed]))))->violations());
    }
}
