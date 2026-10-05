<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * OPF.1 (ADR 0067 §14): a route may bill only through an ACTIVE fee head of
 * its own School (checked through FEE's source seam; the composite foreign
 * key is the database backstop).
 */
class TransportFeeHeadNotSelectableException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_FEE_HEAD_NOT_SELECTABLE', 'The fee head must be an active fee head of this School.');
    }
}
