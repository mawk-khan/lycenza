<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\GeneratesServiceKeys;
use Tests\TestCase;

/**
 * Phase 0O.1: production-mode boot, proven in real `php artisan`
 * subprocesses with APP_ENV=production and FAKE values only. Every cache
 * file goes to a private temporary path (APP_CONFIG_CACHE /
 * APP_ROUTES_CACHE), so the shared tree's bootstrap/cache and the real
 * .env are never written.
 *
 * - An unsafe configuration refuses to boot any command (the web kernel,
 *   queue workers and the scheduler share the same provider boot), and the
 *   refusal names codes only -- never a value.
 * - A safe configuration builds the config and route caches, and the
 *   cached route table contains no local/testing-only route.
 * - The guard reads resolved (cached) configuration, not env().
 */
class ProductionBootSmokeTest extends TestCase
{
    use GeneratesServiceKeys;

    private const CANARY_SIGNING_KEY = 'canary-production-signing-key-4b8e1f0a6c2d9e7b';

    private const CANARY_REDIS_PASSWORD = 'canary-redis-password-91c3e5a7';

    private const CANARY_SCRAPE_TOKEN = 'canary-scrape-token-6d2e8f0a1b3c5d7e9f2a4b6c8d0e';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/prod_boot_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function productionEnv(array $overrides = []): array
    {
        return [
            'APP_ENV' => 'production',
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
    private function runArtisan(array $arguments, array $env): Process
    {
        $process = new Process(['php', 'artisan', ...$arguments, '--no-interaction'], base_path(), $env);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    #[Test]
    public function an_unsafe_production_configuration_refuses_to_boot_and_never_prints_a_value(): void
    {
        $appKeyCanary = 'canary-app-key-6e2a0c8f';

        foreach ([
            ['APP_DEBUG' => 'true'],
            ['APP_KEY' => $appKeyCanary],
            ['SESSION_SECURE_COOKIE' => 'false'],
            ['AI_GATEWAY_CONTEXT_SIGNING_KEY' => ''],
            ['AI_GATEWAY_CONTEXT_SIGNING_KEY' => 'dev-local-only-context-signing-key-change-me'],
            ['AI_GATEWAY_SERVICE_TOKEN' => 'dev-local-only-token'],
            ['AI_GATEWAY_BASE_URL' => 'http://gateway.internal:8100'],
        ] as $unsafe) {
            foreach (['about', 'route:list', 'schedule:list'] as $command) {
                $process = $this->runArtisan([$command], $this->productionEnv($unsafe));
                $output = $process->getOutput().$process->getErrorOutput();

                $this->assertNotSame(0, $process->getExitCode(), json_encode($unsafe)." {$command} must refuse to boot:\n{$output}");
                $this->assertStringContainsString('unsafe production configuration', $output);
                foreach ([$appKeyCanary, self::CANARY_SIGNING_KEY, 'dev-local-only-token', 'dev-local-only-context-signing-key-change-me'] as $value) {
                    $this->assertStringNotContainsString($value, $output, 'A refusal must never print a configured value.');
                }
            }
        }

        $this->assertFileDoesNotExist($this->dir.'/config.php');
    }

    #[Test]
    public function an_unsafe_production_configuration_serves_no_web_page(): void
    {
        $probe = base_path('tests/Support/http-boot-probe.php');

        foreach (['/login', '/api/health/live'] as $uri) {
            $unsafe = new Process(['php', '-d', 'display_errors=stderr', $probe], base_path(), $this->productionEnv(['APP_DEBUG' => 'true', 'PROBE_URI' => $uri]));
            $unsafe->setTimeout(120);
            $unsafe->run();
            $output = $unsafe->getOutput().$unsafe->getErrorOutput();

            // A plain 500 is all that is served: never the page, never a debug
            // error page (source, trace, request), never a configured value --
            // even though APP_DEBUG=true is the very violation being refused.
            $this->assertStringContainsString('SERVED:500', $output, $uri);
            $this->assertStringNotContainsString('csrf', strtolower($output));
            foreach (['ProductionConfigurationGuard', 'AppServiceProvider', 'vendor/laravel', '<pre', 'Stack trace', self::CANARY_SIGNING_KEY, self::CANARY_REDIS_PASSWORD] as $leak) {
                $this->assertStringNotContainsString($leak, $output);
            }
        }

        // The same kind of request with a safe configuration is served (the
        // probe is not vacuous). Liveness needs no database: the production
        // baseline requires TLS to PostgreSQL, which the test database
        // server does not offer.
        $safe = new Process(['php', $probe], base_path(), $this->productionEnv(['PROBE_URI' => '/api/health/live']));
        $safe->setTimeout(120);
        $safe->run();
        $this->assertStringContainsString('SERVED:200', $safe->getOutput(), $safe->getErrorOutput());
    }

    #[Test]
    public function a_safe_production_configuration_caches_and_registers_no_local_route(): void
    {
        $env = $this->productionEnv();

        $cached = $this->runArtisan(['config:cache'], $env);
        $this->assertSame(0, $cached->getExitCode(), $cached->getOutput().$cached->getErrorOutput());
        $routes = $this->runArtisan(['route:cache'], $env);
        $this->assertSame(0, $routes->getExitCode(), $routes->getOutput().$routes->getErrorOutput());
        $this->assertFileExists($this->dir.'/config.php');
        $this->assertFileExists($this->dir.'/routes.php');

        $list = $this->runArtisan(['route:list', '--json'], $env);
        $this->assertSame(0, $list->getExitCode(), $list->getErrorOutput());
        $table = json_decode($list->getOutput(), true);
        $this->assertIsArray($table);
        $this->assertGreaterThan(100, count($table));

        $uris = array_column($table, 'uri');
        $names = array_filter(array_column($table, 'name'));
        foreach ($uris as $uri) {
            foreach (['mfa-demo', 'idempotency-demo', 'webhook-test-events', 'demo-reset', '_ignition', 'telescope'] as $local) {
                $this->assertStringNotContainsString($local, (string) $uri);
            }
        }
        foreach ($names as $name) {
            $this->assertStringNotContainsString('demo', (string) $name);
        }

        // Phase 0O.3 (ADR 0049): no partner route at all (the probe is
        // local/testing only and no production partner scope exists), and
        // every /api/v1 route carries a throttle.
        $this->assertSame([], array_values(array_filter($uris, fn ($uri) => str_starts_with((string) $uri, 'api/v1/partner'))));
        $verbose = json_decode($this->runArtisan(['route:list', '--json', '-v', '--path=api/v1'], $env)->getOutput(), true);
        $this->assertIsArray($verbose);
        $this->assertGreaterThan(300, count($verbose));
        foreach ($verbose as $route) {
            $throttled = collect($route['middleware'])->contains(fn ($m) => str_contains($m, 'ThrottleRequests'));
            $this->assertTrue($throttled, $route['method'].' '.$route['uri'].' is unthrottled');
        }

        // The cached production configuration keeps CORS closed.
        $config = require $this->dir.'/config.php';
        $this->assertSame([], $config['cors']['allowed_origins']);
        $this->assertFalse($config['cors']['supports_credentials']);
        $this->assertSame(60 * 24 * 90, $config['sanctum']['expiration']);

        // The same route table built locally does contain them (the check above is not vacuous).
        $localUris = implode(' ', array_map(fn ($route) => $route->uri(), app('router')->getRoutes()->getRoutes()));
        foreach (['internal/mfa-demo/ping', 'idempotency-demo', 'webhook-test-events'] as $local) {
            $this->assertStringContainsString($local, $localUris);
        }
    }

    #[Test]
    public function a_wildcard_or_malformed_cors_origin_refuses_to_boot(): void
    {
        foreach (['*', 'https://*.example.com', 'http://partner.example.com'] as $origin) {
            $process = $this->runArtisan(['about'], $this->productionEnv(['CORS_ALLOWED_ORIGINS' => $origin]));
            $this->assertNotSame(0, $process->getExitCode(), "CORS_ALLOWED_ORIGINS={$origin} must refuse to boot");
        }

        $ok = $this->runArtisan(['config:cache'], $this->productionEnv(['CORS_ALLOWED_ORIGINS' => 'https://partner.example.com']));
        $this->assertSame(0, $ok->getExitCode(), $ok->getErrorOutput());
        $this->assertSame(['https://partner.example.com'], (require $this->dir.'/config.php')['cors']['allowed_origins']);
    }

    #[Test]
    public function unsafe_infrastructure_configuration_refuses_to_boot_and_never_prints_a_value(): void
    {
        $storageCanary = 'canary-storage-secret-0d2f4b6e';

        foreach ([
            [['DB_CONNECTION' => 'sqlite'], 'database_connection_not_pgsql'],
            [['APP_MAINTENANCE_DRIVER' => 'file'], 'maintenance_mode_not_shared'],
            [['APP_URL' => 'https://lycenza.ddev.site'], 'environment_not_separated'],
            [['LOG_FORMAT' => 'line'], 'log_format_not_structured'],
            [['METRICS_SCRAPE_TOKEN' => ''], 'metrics_scrape_token_invalid'],
            [['DB_DATABASE' => 'school_os_test'], 'environment_not_separated'],
            [['DB_SSLMODE' => 'prefer'], 'database_tls_not_required'],
            [['REDIS_PASSWORD' => ''], 'redis_password_missing'],
            [['DOCUMENTS_DISK' => 'local'], 'storage_disk_not_s3'],
            [['AWS_BUCKET' => 'school-os-local'], 'storage_bucket_not_production'],
            [['AWS_ENDPOINT' => 'http://minio:9000'], 'storage_endpoint_not_https'],
            [['AWS_ACCESS_KEY_ID' => 'school_os', 'AWS_SECRET_ACCESS_KEY' => $storageCanary], 'storage_credentials_invalid'],
        ] as [$unsafe, $code]) {
            $process = $this->runArtisan(['about'], $this->productionEnv($unsafe));
            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertNotSame(0, $process->getExitCode(), json_encode($unsafe).' must refuse to boot');
            $this->assertStringContainsString($code, $output);
            foreach ([self::CANARY_REDIS_PASSWORD, self::CANARY_SIGNING_KEY, self::CANARY_SCRAPE_TOKEN, $storageCanary] as $secret) {
                $this->assertStringNotContainsString($secret, $output);
            }
        }
    }

    #[Test]
    public function a_trust_all_or_malformed_proxy_list_refuses_to_boot_without_echoing_it(): void
    {
        foreach (['*', '0.0.0.0/0', '::/0', 'proxy.internal', '10.0.0.0/4', '10.0.0.1,,10.0.0.2'] as $proxies) {
            $process = $this->runArtisan(['about'], $this->productionEnv(['TRUSTED_PROXIES' => $proxies]));
            $output = $process->getOutput().$process->getErrorOutput();

            $this->assertNotSame(0, $process->getExitCode(), "TRUSTED_PROXIES={$proxies} must refuse to boot");
            $this->assertStringContainsString('TRUSTED_PROXIES', $output);
            if (strlen($proxies) > 3) {
                $this->assertStringNotContainsString($proxies, $output);
            }
        }

        $ok = $this->runArtisan(['config:cache'], $this->productionEnv(['TRUSTED_PROXIES' => '10.1.0.0/16, 2001:db8::/32']));
        $this->assertSame(0, $ok->getExitCode(), $ok->getErrorOutput());
        $this->assertSame(['10.1.0.0/16', '2001:db8::/32'], (require $this->dir.'/config.php')['trustedproxy']['proxies']);
    }

    #[Test]
    public function the_guard_reads_the_cached_configuration_not_the_environment(): void
    {
        $env = $this->productionEnv();
        $this->assertSame(0, $this->runArtisan(['config:cache'], $env)->getExitCode());

        // Tamper with the CACHED configuration only; the process environment stays safe.
        $config = require $this->dir.'/config.php';
        $config['app']['debug'] = true;
        file_put_contents($this->dir.'/config.php', '<?php return '.var_export($config, true).';'.PHP_EOL);

        $process = $this->runArtisan(['about'], $env);
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('app_debug_enabled', $process->getOutput().$process->getErrorOutput());
    }

    #[Test]
    public function a_configured_ai_gateway_boots_and_caches_with_sound_service_keys_and_never_prints_them(): void
    {
        // ADR 0053: runtime-generated keys, config:cache (as the release does),
        // then a boot from the cache. Key material stays out of every output.
        $platform = $this->serviceKey('platform-20260901-1');
        $gateway = $this->serviceKey('ai-gateway-20260901-1');
        $env = $this->productionEnv([
            'AI_GATEWAY_BASE_URL' => 'https://gateway.internal',
            'AI_GATEWAY_SERVICE_SIGNING_KEY' => $this->privateJwk($platform),
            'AI_GATEWAY_INBOUND_VERIFICATION_KEYS' => $this->ring([$this->publicJwk($gateway)]),
            'AI_GATEWAY_REPLAY_STORE' => 'redis',
            'INTERNAL_HOSTS' => 'platform-internal.lycenza.example',
        ]);

        $outputs = '';
        foreach ([['config:cache'], ['route:cache'], ['route:list', '--path=api/internal/ai']] as $command) {
            $process = $this->runArtisan($command, $env);
            $outputs .= $process->getOutput().$process->getErrorOutput();
            $this->assertSame(0, $process->getExitCode(), implode(' ', $command).":\n".$process->getOutput().$process->getErrorOutput());
        }
        foreach ([$platform['d'], $platform['x'], $gateway['x'], self::CANARY_SIGNING_KEY] as $secret) {
            $this->assertStringNotContainsString($secret, $outputs);
        }

        // The same boot with the plaintext URL or the replay store off Redis is refused.
        foreach ([['AI_GATEWAY_BASE_URL' => 'http://gateway.internal:8100'], ['AI_GATEWAY_REPLAY_STORE' => 'array']] as $unsafe) {
            $refused = $this->runArtisan(['route:list'], [...$env, ...$unsafe, 'APP_CONFIG_CACHE' => $this->dir.'/unsafe-config.php']);
            $this->assertNotSame(0, $refused->getExitCode());
            $this->assertStringNotContainsString($platform['d'], $refused->getOutput().$refused->getErrorOutput());
        }
    }

    #[Test]
    public function local_boot_is_unchanged_by_the_production_guard(): void
    {
        $process = $this->runArtisan(['about', '--only=environment'], [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'SESSION_SECURE_COOKIE' => 'false',
            'AI_GATEWAY_CONTEXT_SIGNING_KEY' => 'dev-local-only-context-signing-key-change-me',
            'APP_CONFIG_CACHE' => $this->dir.'/config.php',
            'APP_ROUTES_CACHE' => $this->dir.'/routes.php',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
