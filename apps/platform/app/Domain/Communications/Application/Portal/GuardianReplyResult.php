<?php

namespace App\Domain\Communications\Application\Portal;

/** POR.4: the reply that exists for a form key -- newly written, or the original on a retry. */
final readonly class GuardianReplyResult
{
    public function __construct(
        public string $messageId,
        public bool $replayed,
    ) {}
}
