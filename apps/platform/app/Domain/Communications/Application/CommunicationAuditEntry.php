<?php

namespace App\Domain\Communications\Application;

use Illuminate\Support\Carbon;

/**
 * Phase 5A.11 §8/§10/§11 -- the stable, presentation-safe projection of
 * one App\Models\SchoolAuditEvent row for the Communication Hub audit
 * timeline. Normalizes only the DISPLAY label (`label`) -- `event`
 * keeps the raw, historically-recorded event_type string exactly as
 * stored (brief §10: "Do not rewrite old audit records"). `metadata`
 * is already the SAFE, allowlisted subset
 * CommunicationAuditReadModel::safeMetadata() produced -- never the
 * raw SchoolAuditEvent::metadata array.
 */
final class CommunicationAuditEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $event,
        public readonly string $label,
        public readonly ?string $actorName,
        public readonly Carbon $occurredAt,
        public readonly array $metadata,
        public readonly bool $isEmergency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'label' => $this->label,
            'actorName' => $this->actorName,
            'occurredAt' => $this->occurredAt->toIso8601String(),
            'metadata' => $this->metadata,
            'isEmergency' => $this->isEmergency,
        ];
    }
}
