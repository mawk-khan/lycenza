<?php

namespace App\Support\Audit;

use App\Models\PlatformAuditEvent;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place Phase 0B code writes audit events -- see ADR 0017.
 * Deliberately has no update/delete methods: audit events are
 * append-only both here and at the database privilege level
 * (TenantRls::makeAppendOnly, ADR 0021).
 */
class AuditRecorder
{
    public function __construct(private readonly TenantContext $context) {}

    public function platform(
        string $eventType,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PlatformAuditEvent {
        return PlatformAuditEvent::create([
            'occurred_at' => now(),
            'actor_user_id' => ($actor ?? $this->context->actor())?->id,
            'event_type' => $eventType,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'request_id' => $this->context->requestId(),
            'metadata' => $metadata,
        ]);
    }

    public function school(
        School $school,
        string $eventType,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
    ): SchoolAuditEvent {
        return SchoolAuditEvent::create([
            'school_id' => $school->id,
            'occurred_at' => now(),
            'actor_user_id' => ($actor ?? $this->context->actor())?->id,
            'event_type' => $eventType,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'request_id' => $this->context->requestId(),
            // Phase 0N.3 (ADR 0044 section 13): stamped automatically --
            // never by module code -- when the row is written under a
            // platform elevation; NULL for every ordinary School action.
            'elevation_id' => app(ElevationContext::class)->elevationId(),
            'metadata' => $metadata,
        ]);
    }
}
