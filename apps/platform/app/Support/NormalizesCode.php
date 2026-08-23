<?php

namespace App\Support;

/**
 * Phase 0D section 65: predictable code uniqueness. "MATH"/"math"/
 * "Math" must collide -- rather than adding the `citext` Postgres
 * extension or relying on UI-side normalization alone, every model
 * with a `code` column normalizes it to uppercase, trimmed, on
 * assignment, so the existing plain-string unique index already
 * enforces case-insensitive uniqueness for free.
 */
trait NormalizesCode
{
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value !== null ? strtoupper(trim($value)) : null;
    }
}
