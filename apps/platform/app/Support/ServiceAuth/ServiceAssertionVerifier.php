<?php

namespace App\Support\ServiceAuth;

use Carbon\CarbonImmutable;

/**
 * Verifies an inbound assertion for ONE receiver audience against its ring,
 * in the ADR 0053 section 5.4 order: parse, key, signature, claims, request
 * binding. Replay (one-time jti) and route authorization follow in
 * App\Http\Middleware\AuthenticateServiceAssertion. Establishes nothing about
 * School, actor or capability.
 */
final class ServiceAssertionVerifier
{
    private const HEADER_MEMBERS = ['alg', 'kid', 'typ'];

    private const REQUIRED_CLAIMS = ['aud', 'bsh', 'exp', 'htm', 'htp', 'iat', 'iss', 'jti', 'nbf', 'sub', 'ver'];

    public function __construct(
        private readonly ServiceKeyRing $ring,
        private readonly string $caller,
        private readonly string $audience,
        private readonly bool $enforceKeyAge = true,
    ) {}

    public function verify(?string $authorization, string $method, string $rawPath, string $query, string $body): VerifiedService
    {
        $now = CarbonImmutable::now('UTC');
        $token = self::token($authorization);

        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new ServiceAuthenticationException('malformed');
        }
        [$headerSegment, $payloadSegment, $signatureSegment] = $segments;
        $headerJson = Base64Url::decode($headerSegment);
        $signature = Base64Url::decode($signatureSegment);
        $header = $headerJson === null ? null : StrictJson::flatObject($headerJson);
        if ($header === null || $signature === null) {
            throw new ServiceAuthenticationException('malformed');
        }
        $names = array_keys($header);
        sort($names);
        if ($names !== self::HEADER_MEMBERS || $header['alg'] !== ServiceAuthContract::ALG || $header['typ'] !== ServiceAuthContract::TYP
            || ! is_string($header['kid']) || preg_match(ServiceAuthContract::KID_PATTERN, $header['kid']) !== 1) {
            throw new ServiceAuthenticationException('malformed');
        }
        $kid = $header['kid'];

        $key = $this->ring->find($kid);
        if ($key === null) {
            throw new ServiceAuthenticationException('unknown_kid');
        }
        if (! $key->usableAt($now, $this->enforceKeyAge)) {
            throw new ServiceAuthenticationException('key_expired', $kid);
        }
        if (! $key->verifies($signature, $headerSegment.'.'.$payloadSegment)) {
            throw new ServiceAuthenticationException('bad_signature', $kid);
        }

        $payloadJson = Base64Url::decode($payloadSegment);
        $claims = $payloadJson === null ? null : StrictJson::flatObject($payloadJson);
        if ($claims === null) {
            throw new ServiceAuthenticationException('malformed', $kid);
        }
        $service = $this->claims($claims, $kid, $now);
        $this->binding($claims, $kid, $method, $rawPath, $query, $body);

        return new VerifiedService($service, $kid, (string) $claims['jti'], (int) $claims['exp']);
    }

    private static function token(?string $authorization): string
    {
        if ($authorization === null || $authorization === '') {
            throw new ServiceAuthenticationException('missing');
        }
        $space = strpos($authorization, ' ');
        $scheme = $space === false ? $authorization : substr($authorization, 0, $space);
        if (strtolower($scheme) !== ServiceAuthContract::SCHEME || $space === false) {
            throw new ServiceAuthenticationException('missing');
        }
        $token = substr($authorization, $space + 1);
        if ($token === '' || str_contains($token, ' ') || strlen($token) > ServiceAuthContract::MAX_ASSERTION_BYTES) {
            throw new ServiceAuthenticationException('malformed');
        }

        return $token;
    }

    /** @param  array<string, scalar>  $claims */
    private function claims(array $claims, string $kid, CarbonImmutable $now): string
    {
        $names = array_keys($claims);
        $optional = array_diff($names, self::REQUIRED_CLAIMS);
        if (array_diff(self::REQUIRED_CLAIMS, $names) !== [] || array_diff($optional, ['rid']) !== []) {
            throw new ServiceAuthenticationException('malformed', $kid);
        }
        foreach (['ver', 'iat', 'nbf', 'exp'] as $name) {
            if (! is_int($claims[$name])) {
                throw new ServiceAuthenticationException('malformed', $kid);
            }
        }
        foreach (['iss', 'sub', 'aud', 'jti', 'htm', 'htp', 'bsh'] as $name) {
            if (! is_string($claims[$name])) {
                throw new ServiceAuthenticationException('malformed', $kid);
            }
        }
        if ((isset($claims['rid']) && (! is_string($claims['rid']) || preg_match(ServiceAuthContract::RID_PATTERN, $claims['rid']) !== 1))
            || $claims['ver'] !== ServiceAuthContract::VERSION || preg_match(ServiceAuthContract::JTI_PATTERN, (string) $claims['jti']) !== 1) {
            throw new ServiceAuthenticationException('malformed', $kid);
        }

        if (! in_array($claims['iss'], ServiceAuthContract::SERVICES, true) || $claims['sub'] !== $claims['iss'] || $claims['iss'] !== $this->caller) {
            throw new ServiceAuthenticationException('unknown_service', $kid);
        }
        if ($claims['aud'] !== $this->audience) {
            throw new ServiceAuthenticationException('wrong_audience', $kid, $this->caller);
        }

        [$iat, $nbf, $exp, $current] = [$claims['iat'], $claims['nbf'], $claims['exp'], $now->getTimestamp()];
        if (! ($iat <= $nbf && $nbf < $exp)) {
            throw new ServiceAuthenticationException('malformed', $kid, $this->caller);
        }
        if ($exp - $iat > ServiceAuthContract::MAX_LIFETIME_SECONDS) {
            throw new ServiceAuthenticationException('lifetime_exceeded', $kid, $this->caller);
        }
        if ($iat - ServiceAuthContract::CLOCK_SKEW_SECONDS > $current || $nbf - ServiceAuthContract::CLOCK_SKEW_SECONDS > $current) {
            throw new ServiceAuthenticationException('not_yet_valid', $kid, $this->caller);
        }
        if ($current >= $exp + ServiceAuthContract::CLOCK_SKEW_SECONDS) {
            throw new ServiceAuthenticationException('expired', $kid, $this->caller);
        }

        return $this->caller;
    }

    /** @param  array<string, scalar>  $claims */
    private function binding(array $claims, string $kid, string $method, string $rawPath, string $query, string $body): void
    {
        if ($query !== '' || ! ServiceAuthContract::isCanonicalPath($rawPath)
            || ! hash_equals((string) $claims['htm'], $method) || ! hash_equals((string) $claims['htp'], $rawPath)) {
            throw new ServiceAuthenticationException('request_mismatch', $kid, $this->caller);
        }
        if (! hash_equals((string) $claims['bsh'], Base64Url::encode(hash('sha256', $body, true)))) {
            throw new ServiceAuthenticationException('body_digest_mismatch', $kid, $this->caller);
        }
    }
}
