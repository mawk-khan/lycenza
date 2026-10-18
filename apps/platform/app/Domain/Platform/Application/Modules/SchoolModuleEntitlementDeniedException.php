<?php

namespace App\Domain\Platform\Application\Modules;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ORG.5: a refused module entitlement change, already audited as
 * `platform.school_entitlement.denied`. `field` names the form input the
 * message belongs to; `outcome` is the audited outcome code.
 */
class SchoolModuleEntitlementDeniedException extends HttpException
{
    public function __construct(string $message, int $status, public readonly string $field, public readonly string $outcome)
    {
        parent::__construct($status, $message);
    }
}
