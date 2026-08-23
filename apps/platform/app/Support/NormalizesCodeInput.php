<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Controller-side counterpart to App\Support\NormalizesCode (section
 * 65): normalizes an inbound `code` field to uppercase BEFORE both
 * validation's uniqueness check and persistence, so "MATH"/"math"
 * collide with a clean 422 rather than a raw database exception. The
 * model-level mutator alone is not enough -- a `Rule::unique()` check
 * must compare the SAME normalized form the database actually stores.
 */
trait NormalizesCodeInput
{
    private function normalizeCodeInput(Request $request, string $field = 'code'): void
    {
        if ($request->has($field)) {
            $request->merge([$field => strtoupper(trim((string) $request->input($field)))]);
        }
    }
}
