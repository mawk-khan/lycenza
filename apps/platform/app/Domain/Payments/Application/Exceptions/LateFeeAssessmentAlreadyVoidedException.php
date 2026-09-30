<?php

namespace App\Domain\Payments\Application\Exceptions;

class LateFeeAssessmentAlreadyVoidedException extends PaymentsException
{
    public function __construct(string $id)
    {
        parent::__construct(409, 'LATE_FEE_ASSESSMENT_ALREADY_VOIDED', "Late-fee assessment '{$id}' is already voided.");
    }
}
