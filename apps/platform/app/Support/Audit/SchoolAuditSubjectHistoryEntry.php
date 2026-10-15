<?php

namespace App\Support\Audit;

/**
 * One entry of SchoolAuditSubjectHistoryReader::history(): the envelope
 * (never the actor) plus only the allow-listed metadata values.
 */
final readonly class SchoolAuditSubjectHistoryEntry
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $details
     */
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $eventType,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $details,
    ) {}
}
