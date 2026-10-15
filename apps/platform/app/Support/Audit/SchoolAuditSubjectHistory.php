<?php

namespace App\Support\Audit;

/**
 * The result of SchoolAuditSubjectHistoryReader::history(): newest first;
 * `truncated` is true when older entries exist beyond the limit.
 */
final readonly class SchoolAuditSubjectHistory
{
    /**
     * @param  list<SchoolAuditSubjectHistoryEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public bool $truncated,
    ) {}
}
