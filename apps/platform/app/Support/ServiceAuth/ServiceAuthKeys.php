<?php

namespace App\Support\ServiceAuth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Laravel's ADR 0053 key material, read from resolved configuration (never
 * env()) and parsed once per process -- keys change only with a restart
 * (section 8.6). The AI Gateway integration is enabled only when
 * `services.ai_gateway.base_url` is set; an unused Gateway needs no keys.
 *
 *  - signer():   Laravel's own `platform` private key -> assertions for
 *                Laravel -> Gateway (audience lycenza-ai-gateway);
 *  - verifier(): the ring of `ai-gateway` PUBLIC keys -> Gateway -> Laravel
 *                (audience lycenza-platform-internal-ai).
 *
 * The 90-day key-age rule is enforced outside local/testing (the committed
 * development keys are refused there anyway).
 */
final class ServiceAuthKeys
{
    private ?ServiceAssertionSigner $signer = null;

    private ?ServiceAssertionVerifier $verifier = null;

    public function __construct(private readonly Repository $config, private readonly Application $app) {}

    public function enabled(): bool
    {
        return trim((string) $this->config->get('services.ai_gateway.base_url')) !== '';
    }

    public function enforceKeyAge(): bool
    {
        return ! $this->app->environment(['local', 'testing']);
    }

    public function signingKey(): ServiceSigningKey
    {
        $json = (string) $this->config->get('services.ai_gateway.service_signing_key');
        if (trim($json) === '') {
            throw new ServiceKeyConfigException('signing_key_missing');
        }

        return ServiceSigningKey::fromJwk($json, CarbonImmutable::now('UTC'));
    }

    public function ring(): ServiceKeyRing
    {
        $json = (string) $this->config->get('services.ai_gateway.inbound_verification_keys');
        if (trim($json) === '') {
            throw new ServiceKeyConfigException('verification_keys_missing');
        }

        return ServiceKeyRing::fromJson($json, CarbonImmutable::now('UTC'));
    }

    public function signer(): ServiceAssertionSigner
    {
        return $this->signer ??= new ServiceAssertionSigner(
            $this->signingKey(), ServiceAuthContract::PLATFORM, ServiceAuthContract::AUDIENCE_AI_GATEWAY, $this->enforceKeyAge(),
        );
    }

    public function verifier(): ServiceAssertionVerifier
    {
        return $this->verifier ??= new ServiceAssertionVerifier(
            $this->ring(), ServiceAuthContract::AI_GATEWAY, ServiceAuthContract::AUDIENCE_PLATFORM_INTERNAL_AI, $this->enforceKeyAge(),
        );
    }
}
