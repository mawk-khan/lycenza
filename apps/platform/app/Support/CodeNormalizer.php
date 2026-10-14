<?php

namespace App\Support;

/**
 * Phase 0D section 65: the ONE code normalization (uppercase, trimmed) --
 * used by the model mutator (NormalizesCode), the controller helper
 * (NormalizesCodeInput) and, since SWC.2 (ADR 0073 section 18), the shared
 * Academic Structure administration services, so a uniqueness check always
 * compares the form the database actually stores.
 */
final class CodeNormalizer
{
    public static function normalize(?string $value): ?string
    {
        return $value !== null ? strtoupper(trim($value)) : null;
    }
}
