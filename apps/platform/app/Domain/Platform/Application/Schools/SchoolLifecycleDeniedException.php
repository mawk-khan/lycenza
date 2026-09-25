<?php

namespace App\Domain\Platform\Application\Schools;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A refused School lifecycle or bootstrap operation, already audited as
 * `platform.school.lifecycle_denied`. `field` names the form input the
 * message belongs to.
 */
class SchoolLifecycleDeniedException extends HttpException
{
    public function __construct(
        public readonly string $outcome,
        int $status,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($status, $message);
    }
}
