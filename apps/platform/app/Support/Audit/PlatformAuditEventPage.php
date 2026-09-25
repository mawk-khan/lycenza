<?php

namespace App\Support\Audit;

final readonly class PlatformAuditEventPage
{
    /** @param list<PlatformAuditEventEntry> $entries */
    public function __construct(
        public array $entries,
        public ?string $nextCursor,
    ) {}
}
