<?php

namespace App\Domain\Finance\Application\Periods;

use App\Domain\Finance\Infrastructure\FinancialPeriod;

/**
 * E21.3A: a financial period as other modules and the UI see it. Dates are
 * School-local `Y-m-d` strings.
 */
final class FinancialPeriodSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly string $status,
        public readonly ?string $closedAt,
        public readonly ?string $closedByUserId,
        public readonly ?string $fingerprint,
    ) {}

    public static function fromModel(FinancialPeriod $period): self
    {
        return new self(
            id: $period->id,
            key: $period->period_key,
            startsOn: $period->starts_on->toDateString(),
            endsOn: $period->ends_on->toDateString(),
            status: $period->status,
            closedAt: $period->closed_at?->toIso8601String(),
            closedByUserId: $period->closed_by_user_id,
            fingerprint: $period->close_fingerprint,
        );
    }

    public function isClosed(): bool
    {
        return $this->status === FinancialPeriod::CLOSED;
    }
}
