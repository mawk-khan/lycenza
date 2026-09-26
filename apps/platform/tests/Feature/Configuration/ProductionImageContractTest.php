<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.4A (ADR 0050 section 12): static invariants of the production
 * image definitions -- what goes in, how it runs. The built images
 * themselves are verified by infrastructure/docker/production/verify-images.sh
 * (image contents, non-root runtime, nginx + PHP-FPM serving, role start-up,
 * Gateway refusal), which needs Docker and is run as a separate gate.
 */
class ProductionImageContractTest extends TestCase
{
    /**
     * apps/platform files through base_path(); repository files through
     * the repo-relative location (in the Compose test container they are
     * mounted read-only there, like packages/contracts).
     */
    private function repo(string $path): string
    {
        $file = str_starts_with($path, 'apps/platform/')
            ? base_path(substr($path, strlen('apps/platform/')))
            : dirname(base_path(), 2).'/'.$path;

        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /** @return list<string> */
    private function ignoreRules(string $path): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->repo($path))), fn ($l) => $l !== '' && ! str_starts_with($l, '#')));
    }

    #[Test]
    public function the_app_build_context_excludes_secrets_tests_and_demo_data(): void
    {
        $rules = $this->ignoreRules('apps/platform/.dockerignore');

        foreach (['.env', '.env.*', '**/.env', '**/*.pem', '**/*.key', 'tests', 'phpunit.xml', 'node_modules', 'vendor', 'storage', 'bin', '.git',
            'database/seeders/Demo/*', 'app/Console/Commands/ResetTestDatabase.php'] as $rule) {
            $this->assertContains($rule, $rules, "apps/platform/.dockerignore must exclude {$rule}");
        }

        // The only demo file kept is the guard other code calls to refuse demo behaviour.
        $this->assertContains('!database/seeders/Demo/DemoEnvironmentGuard.php', $rules);
        $this->assertSame(['!database/seeders/Demo/DemoEnvironmentGuard.php'], array_values(array_filter($rules, fn ($r) => str_starts_with($r, '!'))));
    }

    #[Test]
    public function the_gateway_build_context_excludes_secrets_and_tests(): void
    {
        $rules = $this->ignoreRules('services/ai/.dockerignore');

        foreach (['.env', '.venv', 'tests', 'requirements-dev.txt'] as $rule) {
            $this->assertTrue(in_array($rule, $rules, true) || in_array($rule.'*', $rules, true), "services/ai/.dockerignore must exclude {$rule}");
        }
    }

    #[Test]
    public function the_app_image_runs_as_non_root_with_production_defaults_and_never_artisan_serve(): void
    {
        $dockerfile = $this->repo('infrastructure/docker/production/app.Dockerfile');

        $this->assertMatchesRegularExpression('/^USER www-data$/m', $dockerfile);
        $this->assertStringContainsString('APP_ENV=production', $dockerfile);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $dockerfile);
        $this->assertStringContainsString('APP_MAINTENANCE_DRIVER=cache', $dockerfile);
        $this->assertStringContainsString('APP_MAINTENANCE_STORE=database', $dockerfile);
        $this->assertStringContainsString('LOG_FORMAT=json', $dockerfile);
        $this->assertStringContainsString('LOG_LEVEL=info', $dockerfile);
        // An unreachable database fails in seconds (verify-images.sh times it).
        $this->assertSame(5, config('database.connections.pgsql.options')[\PDO::ATTR_TIMEOUT]);
        $this->assertSame(5, config('database.connections.pgsql_admin.options')[\PDO::ATTR_TIMEOUT]);
        $this->assertStringContainsString('composer install --no-dev', $dockerfile);
        $this->assertStringContainsString('npm run build', $dockerfile);
        $this->assertStringNotContainsString('artisan serve', $dockerfile.$this->repo('apps/platform/deploy/entrypoint.sh'));
        $this->assertDoesNotMatchRegularExpression('/COPY[^\n]*\.env/', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/^(ENV|ARG)[^\n]*(PASSWORD|SECRET|APP_KEY|TOKEN)/m', $dockerfile);
        // No cached configuration is baked in: it is built from the injected environment at start.
        $this->assertStringContainsString('rm -f bootstrap/cache/config.php', $dockerfile);
    }

    #[Test]
    public function the_gateway_image_runs_as_non_root_in_production_mode(): void
    {
        $dockerfile = $this->repo('infrastructure/docker/production/ai.Dockerfile');

        $this->assertMatchesRegularExpression('/^USER gateway$/m', $dockerfile);
        $this->assertStringContainsString('ENVIRONMENT=production', $dockerfile);
        $this->assertStringContainsString('requirements.txt', $dockerfile);
        $this->assertStringNotContainsString('requirements-dev.txt', $dockerfile);
        $this->assertStringNotContainsString('--reload', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/^(ENV|ARG)[^\n]*(SERVICE_TOKEN|SECRET|PASSWORD)/m', $dockerfile);
    }

    #[Test]
    public function php_runtime_settings_are_production_safe(): void
    {
        $ini = $this->repo('apps/platform/deploy/php/production.ini');

        foreach (['expose_php = Off', 'display_errors = Off', 'opcache.enable = 1', 'opcache.validate_timestamps = 0', 'session.use_strict_mode = 1', 'zend.exception_ignore_args = On'] as $line) {
            $this->assertStringContainsString($line, $ini);
        }

        $pool = $this->repo('apps/platform/deploy/php/fpm-pool.conf');
        $this->assertStringContainsString('listen = 127.0.0.1:9000', $pool, 'PHP-FPM is reachable only from nginx in the same container');
    }

    #[Test]
    public function nginx_serves_only_the_front_controller_and_built_assets(): void
    {
        $nginx = $this->repo('apps/platform/deploy/nginx/nginx.conf');

        $this->assertStringContainsString('server_tokens off;', $nginx);
        $this->assertStringContainsString('listen 8080', $nginx);
        $this->assertStringContainsString('location = /index.php', $nginx);
        // Exactly two PHP entry points: the public front controller, and the
        // metrics script on the private listener (ADR 0051 §9).
        $this->assertSame(2, substr_count($nginx, 'fastcgi_pass'));
        [$public, $private] = explode('listen 9102;', $nginx, 2);
        $this->assertSame(1, substr_count($public, 'fastcgi_pass'), 'the public listener executes only public/index.php');
        $publicServer = substr($public, (int) strpos($public, 'listen 8080'), (int) strpos($public, '# Phase 0O.5A') - (int) strpos($public, 'listen 8080'));
        $this->assertStringContainsString('location = /index.php', $publicServer);
        $this->assertStringNotContainsString('metrics', strtolower($publicServer));
        $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME /var/www/app/metrics/index.php;', $private);
        $this->assertStringContainsString('fastcgi_param LYCENZA_METRICS_LISTENER 1;', $private);
        $this->assertMatchesRegularExpression('/location \/ \{\s*return 404;/', $private);
        $this->assertFileExists(base_path('metrics/index.php'));
        $this->assertStringNotContainsString('metrics', (string) file_get_contents(base_path('public/index.php')));
        $this->assertMatchesRegularExpression('/location ~ \\\\\.php\$ \{\s*return 404;/', $nginx);
        $this->assertMatchesRegularExpression('/location ~ \/\\\\\. \{\s*(deny all|return 404);/', $nginx);
        $this->assertStringNotContainsString('autoindex on', $nginx);
    }

    #[Test]
    public function deployment_artefacts_carry_no_secret_value(): void
    {
        $files = [
            'infrastructure/docker/production/app.Dockerfile', 'infrastructure/docker/production/ai.Dockerfile',
            'infrastructure/postgres/production-bootstrap.sql', 'apps/platform/deploy/processes.json',
            'apps/platform/deploy/entrypoint.sh', 'apps/platform/deploy/nginx/nginx.conf', 'apps/platform/deploy/php/production.ini',
            'apps/platform/deploy/php/fpm-pool.conf',
        ];

        foreach ($files as $file) {
            $content = $this->repo($file);
            $this->assertNotSame('', $content, $file);
            foreach (['Demo1234!', 'school_os_app_local_only_password', 'school_os_secret', 'dev-local-only-token', 'dev-local-only-context-signing-key', 'minioadmin', 'BEGIN PRIVATE KEY', 'base64:'] as $canary) {
                $this->assertStringNotContainsString($canary, $content, "{$file} contains {$canary}");
            }
        }

        // The verification scripts name canaries in order to search for them
        // and generate throwaway credentials at run time; they hold no key material.
        foreach (['infrastructure/docker/production/verify-images.sh', 'infrastructure/postgres/verify-production-bootstrap.sh'] as $script) {
            $this->assertStringNotContainsString('PRIVATE KEY', $this->repo($script));
            $this->assertStringContainsString('/dev/urandom', $this->repo($script));
        }

        // The production bootstrap never sets a password: that is an out-of-band operator step.
        $this->assertDoesNotMatchRegularExpression('/\bPASSWORD\s+\'/i', $this->repo('infrastructure/postgres/production-bootstrap.sql'));

        // The manifest names secrets; it never holds a value.
        $manifest = json_decode($this->repo('apps/platform/deploy/processes.json'), true);
        foreach ($manifest['secret_groups'] as $names) {
            foreach ($names as $name) {
                $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/', $name);
            }
        }
    }
}
