<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when code calls TenantContext::requireSchool() with no School
 * context set. This is deliberate fail-closed behaviour (root
 * CLAUDE.md rule 5, docs/architecture/TENANCY.md) -- a background job
 * or code path that forgot to establish tenant context must error
 * loudly, never silently operate against "no tenant" or, worse, every
 * tenant.
 */
class TenantContextRequiredException extends RuntimeException
{
    public function __construct(string $message = 'No School tenant context is set for this operation.')
    {
        parent::__construct($message);
    }
}
