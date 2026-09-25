<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
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
    private const CANARY_SIGNING_KEY = 'canary-production-signing-key-4b8e1f0a6c2d9e7b';

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
            'AI_GATEWAY_SERVICE_TOKEN' => '',
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

        $unsafe = new Process(['php', '-d', 'display_errors=stderr', $probe], base_path(), $this->productionEnv(['APP_DEBUG' => 'true']));
        $unsafe->setTimeout(120);
        $unsafe->run();
        $output = $unsafe->getOutput().$unsafe->getErrorOutput();

        // A plain 500 is all that is served: never the page, never a debug
        // error page (source, trace, request), never a configured value --
        // even though APP_DEBUG=true is the very violation being refused.
        $this->assertStringContainsString('SERVED:500', $output);
        $this->assertStringNotContainsString('csrf', strtolower($output));
        foreach (['ProductionConfigurationGuard', 'AppServiceProvider', 'vendor/laravel', '<pre', 'Stack trace', self::CANARY_SIGNING_KEY] as $leak) {
            $this->assertStringNotContainsString($leak, $output);
        }

        // The same request with a safe configuration is served (the probe is not vacuous).
        $safe = new Process(['php', $probe], base_path(), $this->productionEnv());
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
    public function local_boot_is_unchanged_by_the_production_guard(): void
    {
        $process = $this->runArtisan(['about', '--only=environment'], [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'SESSION_SECURE_COOKIE' => 'false',
            'AI_GATEWAY_SERVICE_TOKEN' => 'dev-local-only-token',
            'AI_GATEWAY_CONTEXT_SIGNING_KEY' => 'dev-local-only-context-signing-key-change-me',
            'APP_CONFIG_CACHE' => $this->dir.'/config.php',
            'APP_ROUTES_CACHE' => $this->dir.'/routes.php',
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
