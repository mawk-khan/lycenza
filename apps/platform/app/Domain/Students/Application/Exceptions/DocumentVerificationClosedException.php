<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS 11.5: this document is not awaiting a verification decision (already
 * verified or rejected, or a category that is never verified). Nothing was
 * written; a rejected document is fixed by uploading a replacement.
 */
class DocumentVerificationClosedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'DOCUMENT_VERIFICATION_CLOSED', 'This document is not waiting for verification. Nothing was saved. Reload the page to see its current state.');
    }
}
