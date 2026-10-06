<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * OPF.3 (ADR 0067 §16): the Admission fee may bill only through an ACTIVE fee
 * head of the School (checked through FEE's source seam; the composite
 * foreign key is the database backstop).
 */
class AdmissionFeeHeadNotSelectableException extends AdmissionsException
{
    public function __construct()
    {
        parent::__construct(422, 'ADMISSION_FEE_HEAD_NOT_SELECTABLE', 'The fee head must be an active fee head of this School.');
    }
}
