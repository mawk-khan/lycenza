<?php

namespace App\Support\Audit;

/**
 * One School audit event as the read contract exposes it: the approved
 * v1 envelope fields ONLY (Phase 0L.4, ADR 0042 §5, docs/modules/COMPLIANCE.md).
 *
 * Deliberately absent: `metadata` (the v1 metadata allowlist is empty --
 * never selected, never returned), `school_id` (server-derived, never
 * exposed), `created_at` (the insert time; `occurred_at` is the event
 * time) and any resolved actor/subject name (not a ledger column; the
 * actor may be a Guardian or Student whose identity the envelope does not
 * disclose). `subjectType` is the class basename of the stored type, never
 * the fully-qualified class name.
 */
final readonly class SchoolAuditEventEntry
{
    /** The closed v1 field list, in display order. Tests pin it. */
    public const FIELDS = ['id', 'occurredAt', 'eventType', 'actorUserId', 'subjectType', 'subjectId', 'requestId'];

    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $eventType,
        public ?string $actorUserId,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $requestId,
    ) {}

    /** @return array{id: string, occurredAt: string, eventType: string, actorUserId: string|null, subjectType: string|null, subjectId: string|null, requestId: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'occurredAt' => $this->occurredAt,
            'eventType' => $this->eventType,
            'actorUserId' => $this->actorUserId,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'requestId' => $this->requestId,
        ];
    }
}
