<?php

namespace App\Support\Idempotency;

use App\Support\Idempotency\Exceptions\IdempotencyKeyInvalidException;
use App\Support\Idempotency\Exceptions\IdempotencyKeyRequiredException;

/**
 * Section 26: the key is an opaque client value -- NOT required to be
 * a UUID -- but bounded in length and character set so it can never be
 * unsafely interpolated (it never is; every call site uses parameter
 * binding) and cannot be used to smuggle an oversized value into the
 * database or logs.
 */
class IdempotencyKeyValidator
{
    public function validate(?string $key): string
    {
        if ($key === null || $key === '') {
            throw new IdempotencyKeyRequiredException;
        }

        $min = (int) config('idempotency.key_min_length');
        $max = (int) config('idempotency.key_max_length');

        if (strlen($key) < $min) {
            throw new IdempotencyKeyInvalidException("must be at least {$min} characters");
        }

        if (strlen($key) > $max) {
            throw new IdempotencyKeyInvalidException("must be at most {$max} characters");
        }

        if (! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            throw new IdempotencyKeyInvalidException('must contain only letters, digits, and the characters . _ : -');
        }

        return $key;
    }
}
