<?php

namespace App\Support\Audit;

/**
 * One newest-first page of a School's audit events. `nextCursor` is an
 * opaque keyset position (see SchoolAuditEventReader), null on the last
 * page.
 */
final readonly class SchoolAuditEventPage
{
    /** @param list<SchoolAuditEventEntry> $entries */
    public function __construct(
        public array $entries,
        public ?string $nextCursor,
    ) {}
}
