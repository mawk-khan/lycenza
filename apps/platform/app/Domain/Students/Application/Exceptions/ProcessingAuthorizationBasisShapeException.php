<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * A `guardian_consent` record requires exactly a Guardian relationship
 * reference; `adult_student_consent`/`statutory_school_purpose` must
 * not carry one. This is the application-layer pre-check for the same
 * invariant `spa_basis_shape_check` enforces at the database level --
 * the database CHECK is the real authority; this exception exists so
 * a caller gets a clean, actionable error before ever reaching a raw
 * QueryException.
 */
class ProcessingAuthorizationBasisShapeException extends StudentException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'PROCESSING_AUTHORIZATION_BASIS_SHAPE_INVALID', $reason);
    }
}
