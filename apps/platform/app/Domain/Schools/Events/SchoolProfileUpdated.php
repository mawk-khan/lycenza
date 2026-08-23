<?php

namespace App\Domain\Schools\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0D section 43. Placed under a `Schools` domain module
 * (docs/architecture/DOMAIN-MAP.md Layer 1), distinct from Phase 0C's
 * `App\Domain\Platform\Events\SchoolSettingChanged` -- School profile
 * fields (legal name, address, contact details) are an organizational
 * concern, not the generic key/value settings store. Payload carries
 * only the CHANGED field names, never the values themselves (most are
 * Internal-tier per docs/security/DATA-CLASSIFICATION.md, but this
 * still follows the "ids + minimal facts" rule, EVENTS.md).
 */
class SchoolProfileUpdated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly string $schoolId,
        public readonly array $changedFields,
    ) {}

    public function eventType(): string
    {
        return 'school.profile.updated.v1';
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
        return ['changedFields' => $this->changedFields];
    }
}
