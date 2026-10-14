<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * SWC.3 (ADR 0073 section 19.4, X5): a publish based on a policy version
 * that is no longer the current one -- a repeated submission of the same
 * form, or a form loaded before someone else published -- is refused
 * instead of silently adding another version on top.
 */
class LibraryFinePolicyChangedException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'LIBRARY_FINE_POLICY_CHANGED',
            'The fine policy changed since this form was opened. Review the current policy and publish again if needed.',
        );
    }
}
