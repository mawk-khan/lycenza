<?php

namespace App\Support\Privacy;

use App\Support\Privacy\Exceptions\StatutoryIdentifierLookupKeyNotConfiguredException;

/**
 * Checkpoint 9.6C (ADR 0035, ADR 0028's pattern) -- computes the
 * keyed, tenant-separated exact-match digest stored in
 * `employee_statutory_identifiers.lookup_hash`. A genuinely new,
 * dedicated class rather than reusing `ContactLookupHasher` --
 * ADR 0028's own docblock explicitly anticipates exactly this: "if a
 * second, unrelated exact-match-lookup need arises later... that is
 * the point to decide whether to generalize this class or give the
 * new need its own." Statutory identifiers (PAN/UAN/PF Member ID/
 * ESIC IP Number) are a genuinely different domain from Guardian
 * contact info, with their own dedicated HMAC key
 * (`config('privacy.statutory_identifier_lookup')`), so a compromise
 * of one key never implies the other.
 */
class StatutoryIdentifierLookupHasher
{
    public function __construct(
        private readonly ?string $hmacKey,
        private readonly int $keyVersion,
    ) {}

    public function hash(string $schoolId, string $identifierType, string $normalizedValue): string
    {
        if ($this->hmacKey === null || $this->hmacKey === '') {
            throw new StatutoryIdentifierLookupKeyNotConfiguredException;
        }

        $domain = "statutory-identifier|{$schoolId}|{$identifierType}|{$normalizedValue}";

        return hash_hmac('sha256', $domain, $this->hmacKey);
    }

    public function keyVersion(): int
    {
        return $this->keyVersion;
    }
}
