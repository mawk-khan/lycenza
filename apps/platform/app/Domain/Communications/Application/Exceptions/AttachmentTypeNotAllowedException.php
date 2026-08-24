<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.6 §14/§15 -- thrown when server-side content inspection
 * (never the client-supplied MIME type or file extension alone)
 * determines the uploaded file's real type is not on the conservative
 * allowlist, or when the sniffed content type does not match the
 * claimed extension. `failureCode` is the stable, machine-readable
 * reason (brief §52).
 */
class AttachmentTypeNotAllowedException extends CommunicationException
{
    public function __construct(public readonly string $failureCode = 'attachment_type_not_allowed')
    {
        parent::__construct('This file type is not permitted as a Communication Hub attachment.');
    }
}
