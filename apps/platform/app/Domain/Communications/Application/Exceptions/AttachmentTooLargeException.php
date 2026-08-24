<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.6 §16 -- thrown when a single uploaded file exceeds
 * `config('communications.attachments.max_file_size_mb')`.
 */
class AttachmentTooLargeException extends CommunicationException
{
    public readonly string $failureCode;

    public function __construct(public readonly int $maxSizeMb)
    {
        $this->failureCode = 'attachment_too_large';

        parent::__construct("This file exceeds the maximum attachment size of {$maxSizeMb}MB.");
    }
}
