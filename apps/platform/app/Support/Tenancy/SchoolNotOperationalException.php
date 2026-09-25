<?php

namespace App\Support\Tenancy;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 0N.9 (ADR 0047 section 8): a School business effect was about to
 * run for a School that is not `active` (provisioning, suspended or
 * archived). If it ever surfaces in a request -- a request admitted just
 * before a suspension committed -- it renders as a plain, non-disclosing
 * 404, the same answer the School routes give a non-active School.
 */
class SchoolNotOperationalException extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'Not found.');
    }
}
