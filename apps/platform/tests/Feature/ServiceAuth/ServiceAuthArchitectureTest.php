<?php

namespace Tests\Feature\ServiceAuth;

use App\Models\Capability;
use App\Support\ServiceAuth\ServiceAuthContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ADR 0053 (Phase 0O.7A) structural guards: the closed route catalog is the
 * one source of truth for internal routes; service scopes can never become
 * human capabilities; the retired shared-token machinery is gone; and the
 * Laravel and Gateway implementations agree on every frozen constant.
 */
class ServiceAuthArchitectureTest extends TestCase
{
    private function gatewaySource(): string
    {
        $path = dirname(base_path(), 2).'/services/ai/app/core/service_auth.py';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    #[Test]
    public function every_internal_ai_route_is_service_authenticated_and_in_the_closed_catalog(): void
    {
        $internal = [];
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $uri = $route->uri();
            $this->assertNotContains('ai-service', array_map(fn ($m) => explode(':', (string) $m)[0], $middleware), "{$uri} uses the retired shared-token middleware");

            if (str_starts_with($uri, 'api/internal/ai/')) {
                $this->assertContains('service-auth', $middleware, "{$uri} must require a service assertion");
                $internal[] = (string) $route->getName();
            } else {
                $this->assertNotContains('service-auth', $middleware, "{$uri}: service assertions authenticate only the internal AI surface (never /api/v1)");
            }
        }

        sort($internal);
        $catalog = array_keys(ServiceAuthContract::ROUTE_SCOPES);
        sort($catalog);
        $this->assertSame($catalog, $internal, 'every internal route is cataloged, and every catalog entry is a real route');
        $this->assertSame([
            'api.internal.ai.tools.school-echo' => 'ai.tools.invoke',
            'api.internal.ai.completions.authorize' => 'ai.completions.authorize',
            'api.internal.ai.audit.store' => 'ai.audit.write',
        ], ServiceAuthContract::ROUTE_SCOPES);
        $this->assertSame(['ai-gateway' => ['ai.tools.invoke', 'ai.completions.authorize', 'ai.audit.write']], ServiceAuthContract::SERVICE_SCOPES);
        foreach ([...array_values(ServiceAuthContract::ROUTE_SCOPES), ...array_merge(...array_values(ServiceAuthContract::SERVICE_SCOPES))] as $scope) {
            $this->assertContains($scope, ServiceAuthContract::ALL_SERVICE_SCOPES);
            $this->assertStringNotContainsString('*', $scope);
        }
    }

    #[Test]
    public function service_scopes_can_never_be_human_capabilities(): void
    {
        $this->assertSame(0, Capability::query()->whereIn('key', ServiceAuthContract::ALL_SERVICE_SCOPES)->count());
        $this->assertSame(0, Capability::query()->where('key', 'like', 'platform.service_identities.%')->count());

        // Database-enforced (CHECK capabilities_not_service_scope): so no role,
        // membership or User can ever be granted one -- even by a direct write.
        foreach (ServiceAuthContract::ALL_SERVICE_SCOPES as $scope) {
            try {
                DB::connection('pgsql_admin')->table('capabilities')->insert(['key' => $scope, 'label' => 'x', 'namespace' => 'platform', 'created_at' => now(), 'updated_at' => now()]);
                DB::connection('pgsql_admin')->table('capabilities')->where('key', $scope)->delete();
                $this->fail("{$scope} was accepted as a human capability");
            } catch (QueryException $e) {
                $this->assertStringContainsString('capabilities_not_service_scope', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_retired_shared_token_machinery_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('service_identities'));
        $this->assertFalse(Schema::hasTable('service_identity_capabilities'));
        $this->assertArrayNotHasKey('service_token', config('services.ai_gateway'));
        $this->assertArrayNotHasKey('platform:service-identity-issue', \Artisan::all());
        $this->assertArrayNotHasKey('platform:service-identity-disable', \Artisan::all());

        foreach ((new Finder)->files()->in([app_path(), config_path(), base_path('routes')])->name('*.php') as $file) {
            $source = $file->getContents();
            foreach (['X-Service-Token', 'ServiceIdentityAuthenticator', 'credential_hash', "'ai-service"] as $retired) {
                $this->assertStringNotContainsString($retired, $source, "{$file->getRelativePathname()} still uses {$retired}");
            }
        }
        $this->assertStringNotContainsString('SERVICE_TOKEN', $this->gatewaySource());
    }

    #[Test]
    public function laravel_and_the_gateway_agree_on_every_frozen_constant(): void
    {
        $py = $this->gatewaySource();
        $constant = function (string $name) use ($py): string {
            $this->assertMatchesRegularExpression("/^{$name} = (.+)$/m", $py, $name);
            preg_match("/^{$name} = (.+)$/m", $py, $m);

            return trim($m[1]);
        };

        $this->assertSame('"'.ServiceAuthContract::ALG.'"', $constant('ALG'));
        $this->assertSame('"'.ServiceAuthContract::TYP.'"', $constant('TYP'));
        $this->assertSame('"'.ServiceAuthContract::SCHEME.'"', $constant('SCHEME'));
        foreach (['VERSION', 'DEFAULT_LIFETIME_SECONDS', 'MAX_LIFETIME_SECONDS', 'CLOCK_SKEW_SECONDS', 'MAX_ASSERTION_BYTES', 'MAX_KEY_AGE_DAYS', 'KEY_AGE_WARNING_DAYS'] as $name) {
            $this->assertSame((string) constant(ServiceAuthContract::class.'::'.$name), $constant($name), $name);
        }
        $this->assertSame('24 * 3600', $constant('MAX_TRANSITION_SECONDS'));
        $this->assertSame(24 * 3600, ServiceAuthContract::MAX_TRANSITION_SECONDS);
        $this->assertSame('"'.ServiceAuthContract::PLATFORM.'"', $constant('PLATFORM'));
        $this->assertSame('"'.ServiceAuthContract::AI_GATEWAY.'"', $constant('AI_GATEWAY'));
        $this->assertSame('"'.ServiceAuthContract::AUDIENCE_AI_GATEWAY.'"', $constant('AUDIENCE_AI_GATEWAY'));
        $this->assertSame('"'.ServiceAuthContract::AUDIENCE_PLATFORM_INTERNAL_AI.'"', $constant('AUDIENCE_PLATFORM_INTERNAL_AI'));
        $this->assertSame('"'.ServiceAuthContract::DEVELOPMENT_KID_PREFIX.'"', $constant('DEVELOPMENT_KID_PREFIX'));
        foreach (ServiceAuthContract::DEVELOPMENT_PUBLIC_KEYS as $public) {
            $this->assertStringContainsString("\"{$public}\"", $py);
        }

        // The Gateway's own closed catalog: its two scopes, held by `platform` only.
        $this->assertStringContainsString('("POST", "/v1/tools/invoke"): "gateway.tools.invoke"', $py);
        $this->assertStringContainsString('("POST", "/v1/complete"): "gateway.complete"', $py);
        $this->assertStringContainsString('PLATFORM: frozenset({"gateway.tools.invoke", "gateway.complete"})', $py);
    }

    #[Test]
    public function the_committed_development_keys_are_the_ones_every_guard_refuses(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));
        preg_match("/^AI_GATEWAY_SERVICE_SIGNING_KEY='(.+)'$/m", $example, $signing);
        preg_match("/^AI_GATEWAY_INBOUND_VERIFICATION_KEYS='(.+)'$/m", $example, $ring);
        $signing = json_decode($signing[1] ?? 'null', true);
        $ring = json_decode($ring[1] ?? 'null', true);

        $this->assertSame('dev-local-only-platform-1', $signing['kid'] ?? null);
        $this->assertTrue(ServiceAuthContract::isDevelopmentKey('renamed', $signing['x']));
        $this->assertSame('dev-local-only-ai-gateway-1', $ring[0]['kid'] ?? null);
        $this->assertTrue(ServiceAuthContract::isDevelopmentKey('renamed', $ring[0]['x']));
        $this->assertArrayNotHasKey('d', $ring[0], 'the ring holds public keys only');
        $this->assertStringNotContainsString('AI_GATEWAY_SERVICE_TOKEN', $example);
    }
}
