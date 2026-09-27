<?php

namespace App\Support\ServiceAuth;

use Carbon\CarbonImmutable;

/** One public ring entry: {kty, crv, kid, x, created[, not_after]} -- never `d`. */
final class ServiceVerificationKey
{
    public function __construct(
        public readonly string $kid,
        public readonly CarbonImmutable $created,
        public readonly string $publicKey,
        private readonly string $rawPublicKey,
        public readonly ?CarbonImmutable $notAfter,
    ) {}

    public function ageDays(CarbonImmutable $now): int
    {
        return (int) $this->created->diffInDays($now->startOfDay(), false);
    }

    /** A transitional key stops verifying at not_after even while still configured. */
    public function usableAt(CarbonImmutable $now, bool $enforceKeyAge): bool
    {
        if ($this->notAfter !== null && $now->greaterThanOrEqualTo($this->notAfter)) {
            return false;
        }

        return ! $enforceKeyAge || $this->ageDays($now) <= ServiceAuthContract::MAX_KEY_AGE_DAYS;
    }

    public function verifies(string $signature, string $message): bool
    {
        return strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($signature, $message, $this->rawPublicKey);
    }
}
