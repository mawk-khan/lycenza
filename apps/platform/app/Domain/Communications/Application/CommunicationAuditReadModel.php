<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase 5A.11 §8-14 -- the ONE Communication-specific audit read
 * boundary. Projects the generic, cross-domain App\Models\SchoolAuditEvent
 * ledger (App\Support\Audit\AuditRecorder is the only writer -- this
 * class never writes) into a stable, presentation-safe
 * CommunicationAuditEntry shape. UI code never queries
 * SchoolAuditEvent directly.
 *
 * Answers "who performed an action, and what state/policy transition
 * occurred" -- deliberately distinct from
 * CommunicationDeliveryAnalyticsReadModel, which answers "what
 * happened to recipient/channel delivery after publication" (brief
 * §4). Never mixes the two.
 */
class CommunicationAuditReadModel
{
    /**
     * Phase 5A.10 §24/§25: `announcement.emergency_declared` carries the
     * internal `justification` free-text in its metadata -- present
     * server-side ONLY for a viewer who could already see it on the
     * announcement detail page itself (`canManage ||
     * canDispatchEmergency`, matching
     * AnnouncementController::presentDetail()'s existing
     * `includeEmergencyJustification` gate exactly, brief §14/§31: audit
     * privilege must never become a wider route to content than the
     * viewer already has).
     */
    private const ALLOWED_METADATA_KEYS = [
        'audienceType', 'resolvedCount', 'scheduledAt', 'previousScheduledAt',
        'newScheduledAt', 'requirement', 'channel', 'requestedChannels', 'justification',
        // Phase 5A.12 §47/§91 -- approval workflow evidence. Never a
        // recipient list or message body; `fingerprint`/`requestId` are
        // opaque identifiers only, safe to display for verification.
        'reasons', 'fingerprint', 'previousFingerprint', 'decisionNote', 'requestId',
    ];

    /**
     * Phase 5A.10 evidence -- the closed set of events this checkpoint
     * treats as Emergency-related for distinct visual emphasis (brief
     * §70), never guessed from the event name string.
     */
    private const EMERGENCY_EVENTS = [
        'announcement.emergency_declared',
        'announcement.emergency_published',
        'communication.emergency_quiet_hours_bypass_used',
    ];

    /**
     * Brief §10: presentation labels only -- the stored `event_type`
     * string is never rewritten. An event type recorded before this
     * checkpoint existed, or emitted by a future Communications feature
     * not yet in this map, falls back to a readable-enough default
     * (title-cased, dot/underscore replaced) rather than a raw code.
     */
    private const LABELS = [
        'announcement.created' => 'Announcement created',
        'announcement.updated' => 'Announcement updated',
        'announcement.scheduled' => 'Scheduled',
        'announcement.rescheduled' => 'Rescheduled',
        'announcement.schedule_cancelled' => 'Schedule cancelled',
        'announcement.cancelled' => 'Cancelled',
        'announcement.published' => 'Published',
        'announcement.emergency_published' => 'Emergency published',
        'announcement.emergency_declared' => 'Marked Emergency',
        'communication.emergency_quiet_hours_bypass_used' => 'Quiet-hours bypass used',
        // Phase 5A.12 §47/§91.
        'announcement.approval_requested' => 'Submitted for approval',
        'announcement.approved' => 'Approved',
        'announcement.rejected' => 'Rejected',
        'announcement.approval_withdrawn' => 'Approval withdrawn',
        'announcement.approval_invalidated' => 'Approval invalidated by edit',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return LengthAwarePaginator<int, CommunicationAuditEntry>
     */
    public function timelineForAnnouncement(
        School $school,
        CommunicationAnnouncement $announcement,
        bool $includeEmergencyJustification,
        int $perPage = 25,
        int $page = 1,
    ): LengthAwarePaginator {
        return $this->context->withSchool($school, function () use ($announcement, $includeEmergencyJustification, $perPage, $page) {
            $paginated = SchoolAuditEvent::query()
                ->where('subject_type', CommunicationAnnouncement::class)
                ->where('subject_id', $announcement->id)
                ->with('actor:id,name')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->paginate($perPage, page: $page);

            return $paginated->through(fn (SchoolAuditEvent $event) => $this->present($event, $includeEmergencyJustification));
        });
    }

    private function present(SchoolAuditEvent $event, bool $includeEmergencyJustification): CommunicationAuditEntry
    {
        $metadata = array_intersect_key($event->metadata ?? [], array_flip(self::ALLOWED_METADATA_KEYS));

        if (! $includeEmergencyJustification) {
            unset($metadata['justification']);
        }

        return new CommunicationAuditEntry(
            event: $event->event_type,
            label: self::LABELS[$event->event_type] ?? $this->fallbackLabel($event->event_type),
            actorName: $event->actor?->name,
            occurredAt: $event->occurred_at,
            metadata: $metadata,
            isEmergency: in_array($event->event_type, self::EMERGENCY_EVENTS, true),
        );
    }

    private function fallbackLabel(string $eventType): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $eventType));
    }
}
