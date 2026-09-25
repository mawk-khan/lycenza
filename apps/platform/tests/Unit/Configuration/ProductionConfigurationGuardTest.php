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
            'app' => ['debug' => false, 'key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC'],
            'session' => ['secure' => true],
            'services' => ['ai_gateway' => ['context_signing_key' => self::CANARY_SIGNING_KEY, 'service_token' => null]],
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
        ];
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
            foreach ([$appKeyCanary, $tokenCanary, self::CANARY_SIGNING_KEY] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }
}
