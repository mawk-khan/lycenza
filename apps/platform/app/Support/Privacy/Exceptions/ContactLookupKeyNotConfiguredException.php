<?php

namespace App\Support\Privacy\Exceptions;

use RuntimeException;

/**
 * Thrown by ContactLookupHasher when config('privacy.contact_lookup.hmac_key')
 * is empty -- fail closed, never fall back to hashing with an empty/
 * predictable key, which would make the "keyed" part of the digest
 * meaningless and reduce it to a plain unsalted hash.
 */
class ContactLookupKeyNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'CONTACT_LOOKUP_HMAC_KEY is not configured. Refusing to compute an '.
            'exact-match contact lookup digest with a missing/empty key.'
        );
    }
}
