<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.6 §22 -- thrown when the underlying storage write itself
 * fails (disk/object-store unavailable). CommunicationAttachmentService::upload()
 * never creates a `communication_attachments` row in this case -- see
 * its docblock for the exact compensating-action ordering that keeps
 * this true.
 */
class AttachmentStorageException extends CommunicationException
{
    public readonly string $failureCode;

    public function __construct()
    {
        $this->failureCode = 'attachment_storage_unavailable';

        parent::__construct('The attachment could not be stored. Please try again.');
    }
}
