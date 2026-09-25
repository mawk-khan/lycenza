<?php

namespace App\Support\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR 0049 section 14: an authenticated credential whose scope does not
 * cover this operation -- 403 with the stable code in the `/api` envelope.
 * Says nothing about which scopes the credential does hold.
 */
class ApiScopeInsufficientException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, "This credential's scope does not allow this operation.");
    }

    public function errorCode(): string
    {
        return 'API_SCOPE_INSUFFICIENT';
    }
}
