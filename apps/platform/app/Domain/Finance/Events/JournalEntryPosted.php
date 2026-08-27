<?php

namespace App\Domain\Finance\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0G.2: raised exactly once by `LedgerService::post()`, from
 * inside the same DB transaction that persists the journal entry and
 * its lines (ADR 0025's transactional outbox -- see
 * App\Listeners\RecordDomainEventToOutbox). Payload carries minimal,
 * safe facts only (ids, currency, line count) -- never a full line
 * dump, matching ShouldBeOutboxed's own payload-minimization rule.
 * Internal-only: NOT registered in
 * App\Support\Webhooks\WebhookEventRegistry, so it is not externally
 * webhook-subscribable merely by existing in the outbox (rule 45) --
 * that is a deliberate, separate, future decision.
 */
class JournalEntryPosted implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $journalEntryId,
        public readonly string $currency,
        public readonly int $lineCount,
    ) {}

    public function eventType(): string
    {
        return 'journal_entry.posted.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return [
            'journalEntryId' => $this->journalEntryId,
            'currency' => $this->currency,
            'lineCount' => $this->lineCount,
        ];
    }
}
