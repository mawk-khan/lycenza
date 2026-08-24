<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.6 §17 -- thrown when adding one more attachment would
 * exceed `config('communications.attachments.max_per_message')`, or
 * when the message's cumulative attachment bytes would exceed
 * `config('communications.attachments.max_total_size_mb')`.
 */
class TooManyAttachmentsException extends CommunicationException
{
    public readonly string $failureCode;

    public function __construct(string $reason)
    {
        $this->failureCode = $reason;

        parent::__construct(match ($reason) {
            'attachment_count_exceeded' => 'This announcement already has the maximum number of attachments allowed.',
            'attachment_total_size_exceeded' => 'This announcement has reached its maximum total attachment size.',
            default => 'This attachment cannot be added.',
        });
    }
}
