<?php

namespace Tests\Concerns;

use App\Support\ServiceAuth\Base64Url;
use Carbon\CarbonImmutable;

/**
 * ADR 0053 test support: Ed25519 service keys generated at RUNTIME through
 * libsodium (no committed or production-capable private key is involved in
 * any test) and their RFC 8037 JWK rendering.
 *
 * A key is ['kid' => ..., 'created' => 'YYYY-MM-DD', 'x' => ..., 'd' => ...].
 */
trait GeneratesServiceKeys
{
    /** @return array{kid: string, created: string, x: string, d: string} */
    protected function serviceKey(string $kid, ?string $created = null): array
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = sodium_crypto_sign_seed_keypair($seed);

        return [
            'kid' => $kid,
            'created' => $created ?? CarbonImmutable::now('UTC')->toDateString(),
            'x' => Base64Url::encode(sodium_crypto_sign_publickey($pair)),
            'd' => Base64Url::encode($seed),
        ];
    }

    /** @param  array<string, string>  $key */
    protected function privateJwk(array $key, array $overrides = []): string
    {
        return (string) json_encode([...['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $key['kid'], 'x' => $key['x'], 'd' => $key['d'], 'created' => $key['created']], ...$overrides]);
    }

    /**
     * @param  array<string, string>  $key
     * @return array<string, string>
     */
    protected function publicJwk(array $key, array $overrides = []): array
    {
        return [...['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $key['kid'], 'x' => $key['x'], 'created' => $key['created']], ...$overrides];
    }

    /** @param  list<array<string, string>>  $entries */
    protected function ring(array $entries): string
    {
        return (string) json_encode($entries);
    }
}
