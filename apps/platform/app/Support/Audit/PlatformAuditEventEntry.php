<?php

namespace App\Support\Audit;

/**
 * One platform audit ledger row as the platform audit review may show it
 * (Phase 0N.7, ADR 0046 section 7): the seven approved envelope fields --
 * the School review's set -- and nothing else. Never `metadata`,
 * `ip_address` or `user_agent`; `subjectType` is the class basename only.
 */
final readonly class PlatformAuditEventEntry
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
