<?php

namespace App\Domain\Platform\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0C.3 section 78/79: a synthetic, infrastructure-only domain
 * event that exists ONLY so the webhook subsystem's live proof (and
 * any future manual verification) can exercise the full
 * event -> outbox -> fanout -> signed HTTP delivery chain without a
 * real ERP business event. Emitted only by
 * App\Http\Controllers\Api\V1\Internal\WebhookTestEventController,
 * itself registered only in local/testing and capability-gated -- see
 * that controller's docblock. Never emitted by any production code
 * path.
 */
class PlatformWebhookTestEmitted implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $note,
    ) {}

    public function eventType(): string
    {
        return 'platform.webhook_test.v1';
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
        return ['note' => $this->note];
    }
}
