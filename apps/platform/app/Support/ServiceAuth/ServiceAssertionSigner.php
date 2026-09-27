<?php

namespace App\Support\ServiceAuth;

use Carbon\CarbonImmutable;

/**
 * Mints ONE fresh assertion per request (never cached or reused): Ed25519
 * over the JWS signing input, bound to the method, the exact path the
 * receiver sees and the SHA-256 of the exact body bytes (ADR 0053 section 4).
 */
final class ServiceAssertionSigner
{
    public function __construct(
        private readonly ServiceSigningKey $key,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly bool $enforceKeyAge = true,
    ) {
        if (! in_array($issuer, ServiceAuthContract::SERVICES, true)) {
            throw new ServiceKeyConfigException('unknown_service');
        }
    }

    public function kid(): string
    {
        return $this->key->kid;
    }

    public function authorization(string $method, string $url, string $body, ?string $requestId = null): string
    {
        return 'Lycenza-Service '.$this->assertion($method, $url, $body, $requestId);
    }

    public function assertion(string $method, string $url, string $body, ?string $requestId = null): string
    {
        $now = CarbonImmutable::now('UTC');
        if ($this->enforceKeyAge && $this->key->ageDays($now) > ServiceAuthContract::MAX_KEY_AGE_DAYS) {
            throw new ServiceKeyConfigException('signing_key_expired');
        }

        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment']) || ! ServiceAuthContract::isCanonicalPath($path)) {
            throw new ServiceKeyConfigException('request_target_invalid');
        }

        $iat = $now->getTimestamp();
        $claims = [
            'ver' => ServiceAuthContract::VERSION,
            'iss' => $this->issuer,
            'sub' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $iat,
            'nbf' => $iat,
            'exp' => $iat + ServiceAuthContract::DEFAULT_LIFETIME_SECONDS,
            'jti' => Base64Url::encode(random_bytes(16)),
            'htm' => strtoupper($method),
            'htp' => $path,
            'bsh' => Base64Url::encode(hash('sha256', $body, true)),
        ];
        if ($requestId !== null && preg_match(ServiceAuthContract::RID_PATTERN, $requestId) === 1) {
            $claims['rid'] = $requestId;
        }

        $input = Base64Url::encode((string) json_encode(['alg' => ServiceAuthContract::ALG, 'typ' => ServiceAuthContract::TYP, 'kid' => $this->key->kid], JSON_UNESCAPED_SLASHES))
            .'.'.Base64Url::encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $input.'.'.Base64Url::encode($this->key->sign($input));
    }
}
