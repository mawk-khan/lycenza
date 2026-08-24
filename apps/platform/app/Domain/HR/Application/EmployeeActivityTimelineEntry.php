<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.11 -- one row in an Employee's Activity Timeline. A
 * DISCLOSURE PROJECTION over `App\Models\SchoolAuditEvent`, exactly
 * like `EmployeeDirectoryEntry` is over `Employee` -- never
 * constructed from `$auditEvent->toArray()`, and `$audit->metadata` is
 * never passed through wholesale. Every property here is an explicit
 * scalar or a narrow array of plain field-name strings; the property
 * list below is the entire disclosure contract
 * (docs/modules/HR.md "Audit & Activity Timeline (8A.11, implemented)").
 *
 * Deliberately excludes: raw `metadata` (any key not explicitly
 * allow-listed by `EmployeeActivityTimelineService`'s per-category
 * transform), `subject_type`/`subject_id` (internal linkage
 * mechanism, not user-facing), `request_id`, `ip_address`/`user_agent`
 * (never collected on `SchoolAuditEvent` in the first place), actor
 * email or any other account/security detail beyond a display name,
 * and any resource id (`documentId`/`qualificationId`/...) -- 8A.11
 * deliberately ships without resource-id exposure, see this
 * checkpoint's "Resource IDs" design note.
 */
final class EmployeeActivityTimelineEntry
{
    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $eventType,
        public readonly string $category,
        public readonly string $occurredAt,
        public readonly ?string $actorUserId,
        public readonly ?string $actorDisplayName,
        public readonly array $changedFields,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     event_type: string,
     *     category: string,
     *     occurred_at: string,
     *     actor_user_id: string|null,
     *     actor_display_name: string|null,
     *     changed_fields: array<int, string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'category' => $this->category,
            'occurred_at' => $this->occurredAt,
            'actor_user_id' => $this->actorUserId,
            'actor_display_name' => $this->actorDisplayName,
            'changed_fields' => $this->changedFields,
        ];
    }
}
