<?php

namespace App\Support\Privacy;

use InvalidArgumentException;

/**
 * Deterministic, exact-match phone normalization. This codebase has no
 * phone-parsing library dependency (none was found in composer.json/
 * composer.lock when this was built) and deliberately does not add a
 * large international phone-parsing library or a homemade one just for
 * this identity-foundation checkpoint -- the canonical stored/search
 * representation is E.164, and the normalization BOUNDARY requires
 * callers to already supply E.164 (`+<countrycode><subscriber number>`,
 * digits only after the leading `+`).
 *
 * This means `9876543210` is REJECTED here, not silently assumed to be
 * an Indian number and rewritten to `+919876543210` -- that
 * country-aware local-number conversion is a future UI/import-workflow
 * concern that knows the School's country context; guessing it here
 * would risk silently mis-normalizing a genuinely different country's
 * number.
 *
 * Punctuation-formatted values ("98765 43210", "(212) 555-1212") are
 * rejected outright, never treated as lookup-equivalent to their
 * digit-only form.
 */
class PhoneNormalizer
{
    /**
     * E.164: a leading `+`, a non-zero first digit, 7-14 further
     * digits (8-15 digits total after `+`, matching E.164's maximum
     * length of 15 digits).
     */
    private const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function normalize(string $rawValue): string
    {
        $trimmed = trim($rawValue);

        if (preg_match(self::E164_PATTERN, $trimmed) !== 1) {
            throw new InvalidArgumentException("Invalid E.164 phone number: {$rawValue}");
        }

        return $trimmed;
    }
}
