<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * OPF.2 (ADR 0067 §15): a Hostel tier may bill only through an ACTIVE fee
 * head of its own School (checked through FEE's source seam; the composite
 * foreign key is the database backstop).
 */
class HostelFeeHeadNotSelectableException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_FEE_HEAD_NOT_SELECTABLE', 'The fee head must be an active fee head of this School.');
    }
}
