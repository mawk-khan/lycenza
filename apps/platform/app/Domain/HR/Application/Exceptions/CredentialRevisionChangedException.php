<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.R1-C29 (C14-N5, C29-A): a Verify or Reject named a revision of the qualification or certification that is
 * no longer its current one. The record changed after the decision-maker opened it; nothing was decided.
 */
class CredentialRevisionChangedException extends HrException
{
    public function __construct()
    {
        parent::__construct(409, 'HR_CREDENTIAL_CHANGED', 'This record was changed after you opened it. Reload the page and check it again before you decide.');
    }
}
