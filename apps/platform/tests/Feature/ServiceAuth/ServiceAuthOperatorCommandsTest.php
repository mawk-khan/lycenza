<?php

namespace Tests\Feature\ServiceAuth;

use App\Support\Observability\Metrics\MetricsExporter;
use App\Support\ServiceAuth\ServiceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsServiceAssertions;
use Tests\TestCase;

/**
 * ADR 0053 (Phase 0O.7A) operator tooling: key generation writes the private
 * JWK only to a 0600 file outside the application; the read-only verifier
 * reports bounded metadata (kids, ages, transitions) and never key material.
 */
class ServiceAuthOperatorCommandsTest extends TestCase
{
    use SignsServiceAssertions;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/service-keys-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    #[Test]
    public function key_generation_writes_the_private_key_only_to_a_0600_file(): void
    {
        $this->assertSame(0, Artisan::call('platform:service-key-generate', ['service' => 'platform', '--output' => $this->dir, '--kid' => 'platform-20261001-1', '--no-interaction' => true]));
        $output = Artisan::output();

        $private = file_get_contents($this->dir.'/platform-20261001-1.private.jwk');
        $public = json_decode((string) file_get_contents($this->dir.'/platform-20261001-1.public.jwk'), true);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir.'/platform-20261001-1.private.jwk')), -4));
        $key = ServiceSigningKey::fromJwk(trim((string) $private), CarbonImmutable::now('UTC'));
        $this->assertSame(['platform-20261001-1', $public['x']], [$key->kid, $key->publicKey]);
        $this->assertArrayNotHasKey('d', $public);
        $this->assertStringNotContainsString(json_decode((string) $private, true)['d'], $output, 'the private key is never printed');
        $this->assertStringContainsString($public['x'], $output);

        // Never overwritten; never inside the application; only catalog services.
        $this->assertSame(1, Artisan::call('platform:service-key-generate', ['service' => 'platform', '--output' => $this->dir, '--kid' => 'platform-20261001-1', '--no-interaction' => true]));
        $this->assertSame(1, Artisan::call('platform:service-key-generate', ['service' => 'platform', '--output' => storage_path(), '--no-interaction' => true]));
        $this->assertSame(1, Artisan::call('platform:service-key-generate', ['service' => 'billing', '--output' => $this->dir, '--no-interaction' => true]));
    }

    #[Test]
    public function a_non_production_key_carries_the_prefix_every_production_guard_refuses(): void
    {
        Artisan::call('platform:service-key-generate', ['service' => 'ai-gateway', '--output' => $this->dir, '--kid' => 'gw-1', '--non-production' => true, '--no-interaction' => true]);
        $this->assertFileExists($this->dir.'/dev-local-only-gw-1.private.jwk');
    }

    #[Test]
    public function the_verifier_reports_bounded_metadata_and_never_key_material(): void
    {
        $this->assertSame(0, Artisan::call('platform:verify-service-auth'));
        $this->assertStringContainsString('not configured', Artisan::output());

        $platform = $this->serviceKey('platform-20260901-1', CarbonImmutable::now('UTC')->subDays(80)->toDateString());
        $gateway = $this->serviceKey('ai-gateway-20260901-1');
        $next = $this->serviceKey('ai-gateway-20261001-1');
        $this->useServiceKeys([$this->publicJwk($gateway), $this->publicJwk($next, ['not_after' => CarbonImmutable::now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z')])], $platform, 'https://gateway.internal');

        $status = Artisan::call('platform:verify-service-auth');
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        foreach (['kid=platform-20260901-1', 'age_days=80', 'ROTATE', 'ai-gateway-20260901-1(age_days=0,steady)', 'ai-gateway-20261001-1(age_days=0,transitional_until=', 'OPERATOR_EVIDENCE_REQUIRED'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        foreach ([$platform['d'], $platform['x'], $gateway['x'], $next['x']] as $material) {
            $this->assertStringNotContainsString($material, $output);
        }

        config(['services.ai_gateway.legacy_service_token_configured' => true]);
        $this->assertSame(1, Artisan::call('platform:verify-service-auth'));
    }

    #[Test]
    public function key_ages_are_exported_as_bounded_gauges(): void
    {
        Http::fake(['*/health/ready' => Http::response(['status' => 'ok'])]);
        $platform = $this->serviceKey('platform-20260901-1', CarbonImmutable::now('UTC')->subDays(40)->toDateString());
        $gateway = $this->serviceKey('ai-gateway-20260901-1', CarbonImmutable::now('UTC')->subDays(12)->toDateString());
        $this->useServiceKeys([$this->publicJwk($gateway)], $platform, 'https://gateway.internal');

        $exposition = app(MetricsExporter::class)->render();
        $this->assertStringContainsString('lycenza_service_signing_key_age_days{service="platform"} 40', $exposition);
        $this->assertStringContainsString('lycenza_service_verification_key_max_age_days{service="ai-gateway"} 12', $exposition);
        $this->assertStringNotContainsString('platform-20260901-1', $exposition, 'kid is never a metric label');
    }
}
