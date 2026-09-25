<?php

namespace Tests\Feature\Console;

use App\Models\ServiceIdentity;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ServiceIdentitySeeder;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 0O.1: the development AI Gateway identity (built from the public
 * development token) can never be seeded into a real deployment.
 */
class ServiceIdentitySeederEnvironmentTest extends TestCase
{
    /**
     * Runs the seeder class directly: `db:seed` itself asks for
     * production confirmation, which would skip the seeder and prove
     * nothing about the seeder's own guard.
     */
    private function runSeeder(string $class): void
    {
        $this->app->make($class)->setContainer($this->app)->__invoke();
    }

    private function asEnvironment(string $environment, callable $callback): void
    {
        $original = $this->app['env'];
        $this->app['env'] = $environment;

        try {
            $callback();
        } finally {
            $this->app['env'] = $original;
        }
    }

    #[Test]
    public function the_seeder_refuses_outside_local_and_testing(): void
    {
        config(['services.ai_gateway.service_token' => 'dev-local-only-token']);
        $before = ServiceIdentity::query()->where('slug', 'ai-gateway')->value('credential_hash');

        foreach (['production', 'staging'] as $environment) {
            $this->asEnvironment($environment, function (): void {
                try {
                    $this->runSeeder(ServiceIdentitySeeder::class);
                    $this->fail('ServiceIdentitySeeder must refuse outside local/testing.');
                } catch (AssertionFailedError $e) {
                    throw $e;
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('local/testing only', $e->getMessage());
                    $this->assertStringNotContainsString('dev-local-only-token', $e->getMessage());
                }
            });
        }

        $this->assertSame($before, ServiceIdentity::query()->where('slug', 'ai-gateway')->value('credential_hash'));
    }

    #[Test]
    public function production_database_seeding_never_touches_service_identities(): void
    {
        config(['services.ai_gateway.service_token' => 'a-changed-token-that-must-not-be-installed']);
        $before = ServiceIdentity::query()->orderBy('slug')->get(['slug', 'credential_hash', 'enabled'])->toArray();

        $this->asEnvironment('production', fn () => $this->runSeeder(DatabaseSeeder::class));

        $this->assertSame($before, ServiceIdentity::query()->orderBy('slug')->get(['slug', 'credential_hash', 'enabled'])->toArray());
    }

    #[Test]
    public function local_and_testing_seeding_still_installs_the_development_identity(): void
    {
        config(['services.ai_gateway.service_token' => 'dev-local-only-token']);

        $this->asEnvironment('local', fn () => $this->runSeeder(ServiceIdentitySeeder::class));

        $this->assertSame(hash('sha256', 'dev-local-only-token'), ServiceIdentity::query()->where('slug', 'ai-gateway')->value('credential_hash'));
    }
}
