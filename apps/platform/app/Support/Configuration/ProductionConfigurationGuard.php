<?php

namespace App\Support\Configuration;

use App\Support\Domains\HostnameNormalizer;
use App\Support\Domains\PublicAddress;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\ServiceAuth\ServiceKeyConfigException;
use App\Support\ServiceAuth\ServiceKeyRing;
use App\Support\ServiceAuth\ServiceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Encryption\Encrypter;

/**
 * Phase 0O.1 (docs/architecture/PHASE-0O-READINESS.md §10): the fail-closed
 * production configuration check. App\Providers\AppServiceProvider::register()
 * runs it on EVERY boot when the application environment is `production`
 * (the framework's own signal -- no second notion of "production"), so an
 * unsafe configuration cannot serve web traffic or start a queue worker,
 * the scheduler or any artisan command. It reads resolved config (never
 * env()), so it behaves the same with `config:cache`.
 *
 * Refuses: debug on; a missing or unusable application key; a session
 * cookie that is not Secure; a missing, blank or committed placeholder AI
 * context signing key; the retired shared AI Gateway service token (ADR
 * 0053: any value, so a leftover can never become a fallback). When the
 * optional AI Gateway is configured (`services.ai_gateway.base_url`, CLAUDE.md
 * rule 56), its ADR 0053 service authentication must be sound: an https URL,
 * a valid `platform` signing key and `ai-gateway` verification ring (neither
 * a committed development key, the signing key at most 90 days old, the ring
 * within its 1-2 key / 24 h transition rules) and a Redis replay store.
 *
 * Phase 0O.8A (ADR 0054): a shared SESSION_DOMAIN, a non-https or malformed
 * APP_URL, the development Host/fake switches, malformed host lists, a
 * non-Redis handoff store, an AI Gateway without INTERNAL_HOSTS and -- when
 * custom domains are enabled -- a missing/invalid edge target, probe key
 * or public DNS resolver set.
 *
 * The exception names violation codes only, never a value.
 */
final class ProductionConfigurationGuard
{
    /** The committed development / test values (`.env.example`, `.ddev`, phpunit.xml). */
    public const PLACEHOLDER_SIGNING_KEYS = [
        'dev-local-only-context-signing-key-change-me',
        'test-only-context-signing-key',
    ];

    /** Phase 0O.4A (ADR 0050): PostgreSQL sslmodes that encrypt the connection. */
    public const SECURE_DB_SSLMODES = ['require', 'verify-ca', 'verify-full'];

    /** The local/DDEV/test buckets a production deployment must never use. */
    public const NON_PRODUCTION_BUCKETS = ['school-os-local', 'school-os-test'];

    /** Committed local object-storage credentials (docker-compose MinIO, `.env.example`). */
    public const PLACEHOLDER_STORAGE_CREDENTIALS = ['school_os', 'school_os_secret', 'minioadmin'];

    /** Host suffixes that only ever name a developer machine or DDEV (ADR 0050 section 16). */
    public const LOCAL_URL_HOST_SUFFIXES = ['.ddev.site', '.test', '.local', '.localhost'];

    /** Trust-all proxy values (TrustedProxyList already refuses them; checked again here). */
    public const TRUST_ALL_PROXIES = ['*', '**', '0.0.0.0/0', '::/0'];

    public function __construct(private readonly Repository $config) {}

    /**
     * @return list<string> violation codes; empty when the configuration is safe
     */
    public function violations(): array
    {
        $violations = [];

        if ((bool) $this->config->get('app.debug') !== false) {
            $violations[] = 'app_debug_enabled';
        }

        $violations = [...$violations, ...$this->appKeyViolations()];

        if ($this->config->get('session.secure') !== true) {
            $violations[] = 'session_cookie_not_secure';
        }

        $signingKey = $this->config->get('services.ai_gateway.context_signing_key');
        if (! is_string($signingKey) || trim($signingKey) === '') {
            $violations[] = 'ai_context_signing_key_missing';
        } elseif (in_array($signingKey, self::PLACEHOLDER_SIGNING_KEYS, true)) {
            $violations[] = 'ai_context_signing_key_placeholder';
        }

        if ($this->config->get('services.ai_gateway.legacy_service_token_configured') === true) {
            $violations[] = 'ai_legacy_service_token_configured';
        }

        return [...$violations, ...$this->serviceAuthViolations(), ...$this->infrastructureViolations(), ...$this->hostViolations()];
    }

    public function assertSafe(): void
    {
        $violations = $this->violations();

        if ($violations !== []) {
            throw new ProductionConfigurationException($violations);
        }
    }

    /**
     * ADR 0053 section 7: only when the AI Gateway is configured. Codes only.
     *
     * @return list<string>
     */
    private function serviceAuthViolations(): array
    {
        $baseUrl = trim((string) $this->config->get('services.ai_gateway.base_url'));
        if ($baseUrl === '') {
            return [];
        }

        $violations = [];
        $now = CarbonImmutable::now('UTC');

        if (! str_starts_with($baseUrl, 'https://')) {
            $violations[] = 'ai_gateway_url_not_https';
        }

        $signingKey = trim((string) $this->config->get('services.ai_gateway.service_signing_key'));
        if ($signingKey === '') {
            $violations[] = 'ai_service_signing_key_missing';
        } else {
            try {
                $key = ServiceSigningKey::fromJwk($signingKey, $now);
                if (ServiceAuthContract::isDevelopmentKey($key->kid, $key->publicKey)) {
                    $violations[] = 'ai_service_signing_key_development';
                } elseif ($key->ageDays($now) > ServiceAuthContract::MAX_KEY_AGE_DAYS) {
                    $violations[] = 'ai_service_signing_key_expired';
                }
            } catch (ServiceKeyConfigException $e) {
                $violations[] = 'ai_service_'.$e->violation;
            }
        }

        $ring = trim((string) $this->config->get('services.ai_gateway.inbound_verification_keys'));
        if ($ring === '') {
            $violations[] = 'ai_service_verification_keys_missing';
        } else {
            try {
                foreach (ServiceKeyRing::fromJson($ring, $now)->keys() as $key) {
                    if (ServiceAuthContract::isDevelopmentKey($key->kid, $key->publicKey)) {
                        $violations[] = 'ai_service_verification_keys_development';
                        break;
                    }
                }
            } catch (ServiceKeyConfigException $e) {
                $violations[] = 'ai_service_'.$e->violation;
            }
        }

        $store = $this->config->get('services.ai_gateway.replay_store') ?? $this->config->get('cache.default');
        if ($this->config->get("cache.stores.{$store}.driver") !== 'redis') {
            $violations[] = 'ai_service_replay_store_not_redis';
        }

        return $violations;
    }

    /**
     * Phase 0O.4A (ADR 0050 sections 2, 7, 8, 15): what the hosting contract
     * requires and configuration can actually prove. Provider-side
     * encryption, versioning and public-access blocks cannot be proved from
     * configuration -- they are operator evidence (`platform:verify-storage`
     * and the runbooks), never an environment flag that could lie.
     *
     * @return list<string>
     */
    private function infrastructureViolations(): array
    {
        $violations = [];

        $proxies = $this->config->get('trustedproxy.proxies');
        if (! is_array($proxies) || array_intersect($proxies, self::TRUST_ALL_PROXIES) !== []) {
            $violations[] = 'trusted_proxies_unsafe';
        }

        // Row-level security, the runtime/admin role split and TLS below are
        // PostgreSQL guarantees: any other default connection has none.
        if ($this->config->get('database.default') !== 'pgsql') {
            $violations[] = 'database_connection_not_pgsql';
        }

        // ADR 0050 section 16: production never uses the test database or a
        // developer/DDEV address.
        $host = strtolower((string) parse_url((string) $this->config->get('app.url'), PHP_URL_HOST));
        $testDatabase = $this->config->get('database.testing_database');
        if (in_array($host, ['', 'localhost', '127.0.0.1', '::1', '[::1]'], true)
            || array_filter(self::LOCAL_URL_HOST_SUFFIXES, fn (string $suffix) => str_ends_with($host, $suffix)) !== []
            || (is_string($testDatabase) && $testDatabase !== '' && in_array($testDatabase, [
                $this->config->get('database.connections.pgsql.database'),
                $this->config->get('database.connections.pgsql_admin.database'),
            ], true))) {
            $violations[] = 'environment_not_separated';
        }

        // Phase 0O.5A (ADR 0051 §5, §9): production logs are structured JSON,
        // and the private metrics listener needs a real scrape token (an O4
        // secret, at least 32 characters, never a placeholder).
        if ($this->config->get('observability.logging.format') !== 'json') {
            $violations[] = 'log_format_not_structured';
        }

        $scrapeToken = $this->config->get('observability.metrics.scrape_token');
        if (! is_string($scrapeToken) || strlen(trim($scrapeToken)) < 32 || preg_match('/^(dev|test|local|changeme|example)/i', $scrapeToken) === 1) {
            $violations[] = 'metrics_scrape_token_invalid';
        }

        // Every web/worker/scheduler container must see the same maintenance
        // state: a per-container file would leave the others serving and
        // working during a migration window (ADR 0050 section 13).
        if ($this->config->get('app.maintenance.driver') !== 'cache') {
            $violations[] = 'maintenance_mode_not_shared';
        }

        foreach (['pgsql', 'pgsql_admin'] as $connection) {
            $mode = $this->config->get("database.connections.{$connection}.sslmode");
            if (! in_array($mode, self::SECURE_DB_SSLMODES, true)) {
                $violations[] = 'database_tls_not_required';
                break;
            }
        }

        foreach (['default', 'cache'] as $redis) {
            $password = $this->config->get("database.redis.{$redis}.password");
            if (! is_string($password) || trim($password) === '' || strtolower($password) === 'null') {
                $violations[] = 'redis_password_missing';
                break;
            }
        }

        if ($this->config->get('documents.disk') !== 's3' || $this->config->get('communications.attachments.disk') !== 's3') {
            $violations[] = 'storage_disk_not_s3';
        }

        $bucket = $this->config->get('filesystems.disks.s3.bucket');
        if (! is_string($bucket) || trim($bucket) === '' || in_array($bucket, self::NON_PRODUCTION_BUCKETS, true)) {
            $violations[] = 'storage_bucket_not_production';
        }

        $endpoint = $this->config->get('filesystems.disks.s3.endpoint');
        if (is_string($endpoint) && trim($endpoint) !== '' && ! str_starts_with(strtolower(trim($endpoint)), 'https://')) {
            $violations[] = 'storage_endpoint_not_https';
        }

        // Explicit keys, or none at all (the SDK's runtime credential chain).
        $key = $this->config->get('filesystems.disks.s3.key');
        $secret = $this->config->get('filesystems.disks.s3.secret');
        $keySet = is_string($key) && trim($key) !== '';
        $secretSet = is_string($secret) && trim($secret) !== '';
        if ($keySet !== $secretSet || in_array($key, self::PLACEHOLDER_STORAGE_CREDENTIALS, true) || in_array($secret, self::PLACEHOLDER_STORAGE_CREDENTIALS, true)) {
            $violations[] = 'storage_credentials_invalid';
        }

        if ($this->config->get('filesystems.disks.s3.visibility') === 'public') {
            $violations[] = 'storage_public_visibility';
        }

        return $violations;
    }

    /**
     * Phase 0O.8A (ADR 0054 sections 3.4, 5.2, 6.2, 8, 9): the Host boundary,
     * host-only cookies and -- only when CUSTOM_DOMAINS_ENABLED -- the custom
     * domain settings. Disabled custom domains are a complete, safe mode: the
     * edge, probe key and resolvers are then not required. Codes only.
     *
     * @return list<string>
     */
    private function hostViolations(): array
    {
        $violations = [];
        $names = new HostnameNormalizer;

        // A shared parent-domain cookie would carry one School's session to
        // another School's host (ADR 0054 section 9).
        $sessionDomain = $this->config->get('session.domain');
        if ($sessionDomain !== null && trim((string) $sessionDomain) !== '') {
            $violations[] = 'session_domain_shared';
        }

        $url = (string) $this->config->get('app.url');
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $names->canonicalRequestHost((string) parse_url($url, PHP_URL_HOST)) === null) {
            $violations[] = 'platform_url_invalid';
        }

        if ((bool) $this->config->get('domains.allow_development_hosts')) {
            $violations[] = 'domain_development_hosts_enabled';
        }

        if ((bool) $this->config->get('domains.fakes')) {
            $violations[] = 'domain_fakes_enabled';
        }

        foreach (['domains.platform_aliases', 'domains.internal_hosts', 'domains.reserved_hosts', 'domains.reserved_suffixes'] as $key) {
            foreach ((array) $this->config->get($key, []) as $host) {
                if (! is_string($host) || $names->canonicalRequestHost($host) === null || $names->fold($host) !== $host) {
                    $violations[] = 'domain_host_list_invalid';
                    break 2;
                }
            }
        }

        $handoffStore = $this->config->get('domains.handoff_store') ?? $this->config->get('cache.default');
        if ($this->config->get("cache.stores.{$handoffStore}.driver") !== 'redis') {
            $violations[] = 'session_handoff_store_not_redis';
        }

        // ADR 0053 section 10.1: the Gateway reaches the internal AI routes on
        // a private internal host, which the Host boundary must know.
        if (trim((string) $this->config->get('services.ai_gateway.base_url')) !== '' && (array) $this->config->get('domains.internal_hosts', []) === []) {
            $violations[] = 'internal_hosts_missing';
        }

        if (! (bool) $this->config->get('domains.enabled')) {
            return $violations;
        }

        $target = trim((string) $this->config->get('domains.edge.cname_target'));
        $addresses = (array) $this->config->get('domains.edge.addresses', []);
        if ($target === '' && $addresses === []) {
            $violations[] = 'domain_edge_target_missing';
        }
        if ($target !== '' && $names->canonicalRequestHost($target) === null) {
            $violations[] = 'domain_edge_target_invalid';
        }
        foreach ($addresses as $address) {
            $canonical = is_string($address) ? PublicAddress::canonical($address) : null;
            if ($canonical === null || ! PublicAddress::isPublic($canonical)) {
                $violations[] = 'domain_edge_addresses_invalid';
                break;
            }
        }

        $probeKey = $this->config->get('domains.probe_key');
        if (! is_string($probeKey) || strlen(trim($probeKey)) < 32 || preg_match('/^(dev|test|local|changeme|example)/i', $probeKey) === 1) {
            $violations[] = 'domain_probe_key_invalid';
        }

        $resolvers = (array) $this->config->get('domains.dns.resolvers', []);
        if ($resolvers === []) {
            $violations[] = 'domain_dns_resolvers_missing';
        }
        foreach ($resolvers as $resolver) {
            $canonical = is_string($resolver) ? PublicAddress::canonical($resolver) : null;
            if ($canonical === null || ! PublicAddress::isPublic($canonical)) {
                $violations[] = 'domain_dns_resolvers_invalid';
                break;
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function appKeyViolations(): array
    {
        $key = $this->config->get('app.key');

        if (! is_string($key) || trim($key) === '') {
            return ['app_key_missing'];
        }

        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($raw) && Encrypter::supported($raw, (string) $this->config->get('app.cipher'))
            ? []
            : ['app_key_invalid'];
    }
}
