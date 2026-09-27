<?php

namespace Tests\Concerns;

use App\Support\ServiceAuth\ServiceAssertionSigner;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\ServiceAuth\ServiceAuthKeys;
use App\Support\ServiceAuth\ServiceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * ADR 0053 test support (Laravel feature tests): configure Laravel's service
 * keys, and send internal calls whose exact body bytes are signed and sent
 * unchanged. Keys come from GeneratesServiceKeys (runtime-generated only).
 */
trait SignsServiceAssertions
{
    use GeneratesServiceKeys;

    /**
     * Configure Laravel's service keys for this test and drop the cached,
     * per-process parse (in production a change is a restart).
     *
     * @param  list<array<string, string>>|null  $ring  public JWKs of the `ai-gateway` keys Laravel accepts
     * @param  array<string, string>|null  $platformKey  Laravel's own `platform` signing key
     */
    protected function useServiceKeys(?array $ring = null, ?array $platformKey = null, ?string $baseUrl = null): void
    {
        config([
            'services.ai_gateway.inbound_verification_keys' => $ring === null ? null : $this->ring($ring),
            'services.ai_gateway.service_signing_key' => $platformKey === null ? null : $this->privateJwk($platformKey),
            'services.ai_gateway.base_url' => $baseUrl,
        ]);
        $this->app->forgetInstance(ServiceAuthKeys::class);
    }

    /** @param  array<string, string>  $key */
    protected function gatewaySigner(array $key, string $issuer = ServiceAuthContract::AI_GATEWAY, string $audience = ServiceAuthContract::AUDIENCE_PLATFORM_INTERNAL_AI): ServiceAssertionSigner
    {
        return new ServiceAssertionSigner(ServiceSigningKey::fromJwk($this->privateJwk($key), CarbonImmutable::now('UTC')), $issuer, $audience, false);
    }

    private ?ServiceAssertionSigner $signingAs = null;

    /**
     * Subsequent requests (e.g. postJson) carry a fresh assertion from
     * $signer over the exact bytes they send -- unless the request already
     * names its own Authorization value.
     */
    protected function asService(?ServiceAssertionSigner $signer): static
    {
        $this->signingAs = $signer;

        return $this;
    }

    /** @param  array<string, mixed>  $server */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->signingAs !== null && ! isset($server['HTTP_AUTHORIZATION'])) {
            $path = (string) parse_url($uri, PHP_URL_PATH);
            $server['HTTP_AUTHORIZATION'] = $this->signingAs->authorization($method, 'http://localhost'.$path, (string) $content);
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * POST the exact body bytes to an internal route, with the given
     * Authorization value (or a fresh assertion from $signer).
     *
     * @param  array<string, mixed>|string  $payload
     */
    protected function internalPost(string $path, array|string $payload, ServiceAssertionSigner|string|null $auth, array $headers = []): TestResponse
    {
        $body = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $authorization = $auth instanceof ServiceAssertionSigner ? $auth->authorization('POST', 'http://localhost'.$path, $body) : $auth;

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $path, [], [], [], $server, $body);
    }
}
