<?php

namespace App\Support\Privacy;

use App\Support\Privacy\Exceptions\ContactLookupKeyNotConfiguredException;

/**
 * Computes the keyed, tenant-separated exact-match digest stored in
 * `guardian_contacts.lookup_hash` -- never a plain/unsalted hash, and
 * never reversible. A keyed HMAC (not a plain SHA-256) is the point:
 * without the key, an attacker who obtains the database cannot build a
 * rainbow table of common emails/phone numbers to reverse the digest,
 * the same property a plain hash of a low-entropy value (an email
 * address) would NOT have.
 *
 * School separation is structural, not incidental: `school_id`
 * participates in the hashed domain string, so the identical
 * normalized contact value in two different Schools produces two
 * different digests -- School A can never learn, from the digest
 * alone, that School B has the "same" email/phone on file.
 *
 * `guardian-contact` is a fixed literal domain-separation prefix
 * (deliberately not parameterized) -- this hasher exists for exactly
 * one purpose today (GuardianContact); if a second, unrelated
 * exact-match-lookup need arises later (e.g. a future
 * StudentIdentifier), that is the point to decide whether to
 * generalize this class or give the new need its own, since a
 * premature generic "namespace" parameter would be speculative
 * abstraction for a caller that doesn't exist yet.
 */
class ContactLookupHasher
{
    public function __construct(
        private readonly ?string $hmacKey,
        private readonly int $keyVersion,
    ) {}

    public function hash(string $schoolId, string $contactType, string $normalizedValue): string
    {
        if ($this->hmacKey === null || $this->hmacKey === '') {
            throw new ContactLookupKeyNotConfiguredException;
        }

        $domain = "guardian-contact|{$schoolId}|{$contactType}|{$normalizedValue}";

        return hash_hmac('sha256', $domain, $this->hmacKey);
    }

    /**
     * The key version active for hashes computed by THIS instance --
     * stored per-row (`lookup_key_version`) so a future key rotation
     * can identify which rows still need re-hashing under a new key
     * without guessing from row age. No rotation workflow is
     * implemented in this checkpoint; this is only what makes that
     * future work possible without a schema change.
     */
    public function keyVersion(): int
    {
        return $this->keyVersion;
    }
}
