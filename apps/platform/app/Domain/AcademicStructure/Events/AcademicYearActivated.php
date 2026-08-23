<?php

namespace App\Domain\AcademicStructure\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * Phase 0D section 85: the one consequential academic action this
 * checkpoint proves the full transaction -> audit -> outbox chain
 * against, including rollback safety.
 */
class AcademicYearActivated implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $academicYearId,
        public readonly ?string $previousActiveAcademicYearId,
    ) {}

    public function eventType(): string
    {
        return 'academic_year.activated.v1';
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
            'academicYearId' => $this->academicYearId,
            'previousActiveAcademicYearId' => $this->previousActiveAcademicYearId,
        ];
    }
}
