<?php

namespace App\Domain\Fees\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * FEE.1 (ADR 0062 §20): a fee structure became the active structure for
 * its AcademicYear x GradeLevel (x Campus) scope. Internal only: NOT
 * registered in `App\Support\Webhooks\WebhookEventRegistry` (rules 45/77);
 * external publication needs its own reviewed decision. Payload is ids
 * only -- no amounts, names or codes.
 */
class FeeStructureActivated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $feeStructureId,
        public readonly string $academicYearId,
        public readonly string $gradeLevelId,
        public readonly ?string $campusId,
        public readonly ?string $supersedesFeeStructureId,
    ) {}

    public function eventType(): string
    {
        return 'fee_structure.activated.v1';
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
            'feeStructureId' => $this->feeStructureId,
            'academicYearId' => $this->academicYearId,
            'gradeLevelId' => $this->gradeLevelId,
            'campusId' => $this->campusId,
            'supersedesFeeStructureId' => $this->supersedesFeeStructureId,
        ];
    }
}
