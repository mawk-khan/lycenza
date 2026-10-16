<?php

namespace Tests\Concerns;

use Symfony\Component\Process\Process;

/**
 * Phase 0O.1 / PPR.1 (ADR 0074 section 26): the fake, safe baseline for a
 * hardened-environment boot (`production` or `staging`) in a real
 * `php artisan` subprocess. Every cache file goes to the using test's private
 * `$this->dir`, so the shared tree's bootstrap/cache and the real .env are
 * never written. Shared by ProductionBootSmokeTest and
 * HardenedEnvironmentBootTest so both prove exactly the same baseline.
 */
trait BootsHardenedEnvironment
{
    protected const CANARY_SIGNING_KEY = 'canary-production-signing-key-4b8e1f0a6c2d9e7b';

    protected const CANARY_REDIS_PASSWORD = 'canary-redis-password-91c3e5a7';

    protected const CANARY_SCRAPE_TOKEN = 'canary-scrape-token-6d2e8f0a1b3c5d7e9f2a4b6c8d0e';

    protected const CANARY_CONTACT_LOOKUP_KEY = 'canary-contact-lookup-key-5f1b9d3a7c2e';

    protected const CANARY_STATUTORY_LOOKUP_KEY = 'canary-statutory-lookup-key-0e6c2a8d4b1f';

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function hardenedEnv(string $appEnv, array $overrides = []): array
    {
        return [
            'APP_ENV' => $appEnv,
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'SESSION_SECURE_COOKIE' => 'true',
            'AI_GATEWAY_CONTEXT_SIGNING_KEY' => self::CANARY_SIGNING_KEY,
            // ADR 0053: no AI Gateway configured (an empty value also masks a
            // developer .env); the retired shared token is never set.
            'AI_GATEWAY_BASE_URL' => '',
            'AI_GATEWAY_SERVICE_TOKEN' => '',
            // Phase 0O.4A infrastructure baseline (ADR 0050 section 15).
            'APP_URL' => 'https://erp.example.org',
            'LOG_FORMAT' => 'json',
            'METRICS_SCRAPE_TOKEN' => self::CANARY_SCRAPE_TOKEN,
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'lycenza_production',
            'APP_MAINTENANCE_DRIVER' => 'cache',
            'APP_MAINTENANCE_STORE' => 'database',
            'DB_SSLMODE' => 'require',
            'REDIS_PASSWORD' => self::CANARY_REDIS_PASSWORD,
            // PPR F-D1-2: hardened Redis is verified TLS (credentials never in the URL).
            'REDIS_CLIENT' => 'predis',
            'REDIS_URL' => 'tls://redis.example.internal:6379',
            // PPR P2-F2: both dedicated lookup HMAC keys (phpunit.xml's committed
            // test values, inherited by these subprocesses, are refused here).
            'CONTACT_LOOKUP_HMAC_KEY' => self::CANARY_CONTACT_LOOKUP_KEY,
            'CONTACT_LOOKUP_HMAC_KEY_VERSION' => '1',
            'STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY' => self::CANARY_STATUTORY_LOOKUP_KEY,
            'STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY_VERSION' => '1',
            // The canary password reaches no real Redis: nothing in these
            // subprocesses may depend on one.
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'DOCUMENTS_DISK' => 's3',
            'COMMUNICATION_ATTACHMENTS_DISK' => 's3',
            'AWS_BUCKET' => 'lycenza-production-objects',
            'AWS_ENDPOINT' => 'https://objects.example.net',
            'AWS_ACCESS_KEY_ID' => '',
            'AWS_SECRET_ACCESS_KEY' => '',
            'TRUSTED_PROXIES' => '10.0.0.0/8',
            // ADR 0054 (Phase 0O.8A): custom domains off (a complete, safe
            // mode), no development host or fake -- phpunit.xml turns those
            // on for the suite -- and a Redis handoff store by configuration
            // only (nothing here connects to it).
            'CUSTOM_DOMAINS_ENABLED' => 'false',
            'DOMAIN_FAKES_ENABLED' => 'false',
            'DOMAIN_ALLOW_DEVELOPMENT_HOSTS' => 'false',
            'SESSION_HANDOFF_STORE' => 'redis',
            // E33 / TCH-L1 (ADR 0063 section 44): the development-only teacher
            // Attendance bearer API is off -- phpunit.xml turns it on for the suite.
            'TEACHER_ATTENDANCE_API_DEVELOPMENT_ENABLED' => 'false',
            // ADR 0055 (Phase 0O.9A): email explicitly disabled (a complete,
            // safe mode) -- phpunit.xml turns the fake provider and fake
            // event feed on for the suite.
            'MAIL_PROVIDER' => 'none',
            'MAIL_PROVIDER_EVENTS' => 'none',
            'PROBE_HOST' => 'erp.example.org',
            'APP_CONFIG_CACHE' => $this->dir.'/config.php',
            'APP_ROUTES_CACHE' => $this->dir.'/routes.php',
            ...$overrides,
        ];
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    protected function runArtisan(array $arguments, array $env): Process
    {
        $process = new Process(['php', 'artisan', ...$arguments, '--no-interaction'], base_path(), $env);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
