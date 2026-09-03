<?php

namespace App\Support\Privacy\Exceptions;

use RuntimeException;

class StatutoryIdentifierLookupKeyNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('STATUTORY_IDENTIFIER_LOOKUP_HMAC_KEY is not configured -- refusing to hash a statutory identifier with a missing/predictable key.');
    }
}
