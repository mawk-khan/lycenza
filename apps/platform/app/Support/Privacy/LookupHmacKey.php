<?php

namespace App\Support\Privacy;

/**
 * PPR P2-F2: the one definition of a usable lookup HMAC key, shared by
 * ContactLookupHasher, StatutoryIdentifierLookupHasher and
 * ProductionConfigurationGuard, so the boot check and the runtime hashers
 * can never disagree about what "configured" means.
 *
 * A key is an opaque secret (ADR 0041, ADR 0036): any non-empty string. No
 * length or encoding is imposed here because none has ever been part of the
 * contract; a hardened environment additionally refuses blank and committed
 * development values (ProductionConfigurationGuard).
 */
final class LookupHmacKey
{
    /**
     * @phpstan-assert-if-true non-empty-string $key
     */
    public static function isUsable(?string $key): bool
    {
        return $key !== null && $key !== '';
    }
}
