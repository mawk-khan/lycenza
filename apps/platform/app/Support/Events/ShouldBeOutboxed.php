<?php

namespace App\Support\Events;

/**
 * Implemented by every durable domain event. Dispatch normally via
 * Laravel's `event()` helper -- `RecordDomainEventToOutbox` (registered
 * for this interface in AppServiceProvider) writes the outbox row
 * SYNCHRONOUSLY, in-process, as part of handling the dispatch. Since
 * `event()` is called from inside the same DB::transaction() as the
 * business state change, and this listener's INSERT uses the same
 * connection, the outbox row is written in the SAME database
 * transaction automatically -- no extra plumbing required. See
 * docs/architecture/adr/0025-transactional-outbox.md and
 * docs/architecture/EVENTS.md.
 *
 * Payloads must contain references and minimal facts, never full
 * records or secrets (section 55 of the Phase 0C brief,
 * docs/security/DATA-CLASSIFICATION.md) -- e.g. a `school_id` and
 * `setting_key`, not a guardian's phone number or a password.
 */
interface ShouldBeOutboxed
{
    /**
     * Stable external name, e.g. "school.setting.changed.v1" --
     * independent of the PHP class name so refactoring the class never
     * silently breaks an external integration watching for this name.
     * See docs/architecture/EVENTS.md ("Event naming convention").
     */
    public function eventType(): string;

    public function eventVersion(): int;

    public function schoolId(): ?string;

    public function campusId(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;

    /**
     * The id of the event/request that directly caused this one, if
     * any -- e.g. a consumer reacting to event X and emitting event Y
     * sets Y's causationId to X's event id. Null for a fresh top-level
     * event (section 34).
     */
    public function causationId(): ?string;
}
