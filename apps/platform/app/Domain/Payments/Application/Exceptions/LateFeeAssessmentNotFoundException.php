<?php

namespace App\Domain\Payments\Application\Exceptions;

class LateFeeAssessmentNotFoundException extends PaymentsException
{
    public function __construct(string $id)
    {
        parent::__construct(404, 'LATE_FEE_ASSESSMENT_NOT_FOUND', "Late-fee assessment '{$id}' was not found.");
    }
}
