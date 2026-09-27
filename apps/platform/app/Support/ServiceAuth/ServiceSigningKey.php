<?php

namespace App\Support\ServiceAuth;

use Carbon\CarbonImmutable;
use SodiumException;

/**
 * One RFC 8037 OKP private JWK -- exactly {kty, crv, kid, x, d, created} --
 * held by the caller only (ADR 0053 section 3.4). libsodium derives the
 * Ed25519 keypair from the 32-byte seed `d`; `x` must be its public key.
 * Key material never appears in a dump, log or exception.
 */
final class ServiceSigningKey
{
    private function __construct(
        public readonly string $kid,
        public readonly CarbonImmutable $created,
        public readonly string $publicKey,
        private readonly string $secretKey,
    ) {}

    public static function fromJwk(string $json, CarbonImmutable $now): self
    {
        $jwk = StrictJson::flatObject($json);
        $members = ['kty', 'crv', 'kid', 'x', 'd', 'created'];
        if ($jwk === null || count($jwk) !== count($members) || array_diff($members, array_keys($jwk)) !== []
            || $jwk['kty'] !== 'OKP' || $jwk['crv'] !== 'Ed25519'
            || ! is_string($jwk['kid']) || preg_match(ServiceAuthContract::KID_PATTERN, $jwk['kid']) !== 1) {
            throw new ServiceKeyConfigException('signing_key_invalid');
        }

        $seed = Base64Url::decode($jwk['d']);
        $public = Base64Url::decode($jwk['x']);
        $created = ServiceKeyDates::date($jwk['created']);
        if ($seed === null || $public === null || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES
            || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $created === null || $created->greaterThan($now->startOfDay())) {
            throw new ServiceKeyConfigException('signing_key_invalid');
        }

        try {
            $pair = sodium_crypto_sign_seed_keypair($seed);
        } catch (SodiumException) {
            throw new ServiceKeyConfigException('signing_key_invalid');
        }
        if (! hash_equals(sodium_crypto_sign_publickey($pair), $public)) {
            throw new ServiceKeyConfigException('signing_key_invalid');
        }

        return new self($jwk['kid'], $created, (string) $jwk['x'], sodium_crypto_sign_secretkey($pair));
    }

    public function ageDays(CarbonImmutable $now): int
    {
        return (int) $this->created->diffInDays($now->startOfDay(), false);
    }

    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['kid' => $this->kid, 'created' => $this->created->toDateString()];
    }
}
