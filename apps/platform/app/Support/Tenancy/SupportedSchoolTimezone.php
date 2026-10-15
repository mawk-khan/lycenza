<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * X-02 (OD-V11-7, ADR 0075 section 12): a School timezone must be one both
 * of its consumers accept (SchoolTimezone::isSupported()) -- never free
 * text, a misspelling or a deprecated alias.
 */
final class SupportedSchoolTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! SchoolTimezone::isSupported($value)) {
            $fail('Choose a timezone from the list (for example Asia/Kolkata).');
        }
    }
}
