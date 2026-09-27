<?php

namespace App\Support\ServiceAuth;

use Carbon\CarbonImmutable;

/**
 * A receiver's verification ring (ADR 0053 section 5.2): 1-2 public keys of
 * the ONE calling service it accepts; exactly one steady key (no
 * `not_after`); at most one transitional key whose `not_after` is in the
 * future and at most 24 h away. Read at process start only -- changing it
 * is a restart (section 8.6).
 */
final class ServiceKeyRing
{
    /** @param  array<string, ServiceVerificationKey>  $keys */
    private function __construct(private readonly array $keys) {}

    public static function fromJson(string $json, CarbonImmutable $now): self
    {
        $objects = json_decode($json, false, 3);
        if (! is_array($objects)) {
            throw new ServiceKeyConfigException('verification_keys_invalid');
        }
        if ($objects === []) {
            throw new ServiceKeyConfigException('verification_keys_missing');
        }
        if (count($objects) > 2) {
            throw new ServiceKeyConfigException('verification_keys_too_many');
        }

        // A list of flat objects: every `"...":` in the text is a member name,
        // so a duplicate member (which json_decode silently drops) shows up
        // as more names than decoded members.
        $members = 0;
        $keys = [];
        foreach ($objects as $entry) {
            $jwk = $entry instanceof \stdClass ? get_object_vars($entry) : null;
            if ($jwk === null || array_filter($jwk, fn ($value) => ! is_scalar($value)) !== []) {
                throw new ServiceKeyConfigException('verification_keys_invalid');
            }
            $members += count($jwk);
            if (array_key_exists('d', $jwk)) {
                throw new ServiceKeyConfigException('verification_keys_private_material');
            }
            $key = self::entry($jwk, $now);
            if (isset($keys[$key->kid])) {
                throw new ServiceKeyConfigException('verification_keys_duplicate_kid');
            }
            $keys[$key->kid] = $key;
        }
        if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*"\s*:/', $json) !== $members) {
            throw new ServiceKeyConfigException('verification_keys_invalid');
        }
        if (count(array_filter($keys, fn (ServiceVerificationKey $k) => $k->notAfter === null)) !== 1) {
            throw new ServiceKeyConfigException('verification_keys_steady_key');
        }

        return new self($keys);
    }

    public function find(string $kid): ?ServiceVerificationKey
    {
        return $this->keys[$kid] ?? null;
    }

    /** @return list<ServiceVerificationKey> */
    public function keys(): array
    {
        return array_values($this->keys);
    }

    /** @param  array<string, scalar>  $jwk */
    private static function entry(array $jwk, CarbonImmutable $now): ServiceVerificationKey
    {
        $allowed = ['kty', 'crv', 'kid', 'x', 'created', 'not_after'];
        if (array_diff(['kty', 'crv', 'kid', 'x', 'created'], array_keys($jwk)) !== [] || array_diff(array_keys($jwk), $allowed) !== []
            || $jwk['kty'] !== 'OKP' || $jwk['crv'] !== 'Ed25519'
            || ! is_string($jwk['kid']) || preg_match(ServiceAuthContract::KID_PATTERN, $jwk['kid']) !== 1) {
            throw new ServiceKeyConfigException('verification_keys_invalid');
        }
        $raw = Base64Url::decode($jwk['x']);
        $created = ServiceKeyDates::date($jwk['created']);
        $notAfter = array_key_exists('not_after', $jwk) ? ServiceKeyDates::instant($jwk['not_after']) : null;
        if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $created === null
            || $created->greaterThan($now->startOfDay()) || (array_key_exists('not_after', $jwk) && $notAfter === null)) {
            throw new ServiceKeyConfigException('verification_keys_invalid');
        }
        if ($notAfter !== null && $notAfter->lessThanOrEqualTo($now)) {
            throw new ServiceKeyConfigException('verification_key_transition_expired');
        }
        if ($notAfter !== null && $notAfter->greaterThan($now->addSeconds(ServiceAuthContract::MAX_TRANSITION_SECONDS))) {
            throw new ServiceKeyConfigException('verification_key_transition_too_long');
        }

        return new ServiceVerificationKey($jwk['kid'], $created, (string) $jwk['x'], $raw, $notAfter);
    }
}
