<?php

namespace App\Domain\Fees\Application;

/**
 * FEE.5 (ADR 0062 §16.1): the facts `App\Domain\Payments`' late-fee run
 * needs about one rule, read through `LateFeeRuleService` -- never the
 * `fee_late_fee_rules` table. `accountsValid` is true only when the
 * late-fee head is active and its receivable (asset) and revenue (income)
 * accounts are active. Money and percentages are exact decimal strings.
 */
final class LateFeeRuleSnapshot
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $name,
        public readonly string $feeStructureId,
        public readonly ?string $feeHeadId,
        public readonly string $lateFeeHeadId,
        public readonly string $lateFeeHeadName,
        public readonly int $graceDays,
        public readonly string $kind,
        public readonly ?string $fixedAmount,
        public readonly ?string $percentage,
        public readonly ?string $maxAmount,
        public readonly string $status,
        public readonly int $configurationVersion,
        public readonly string $receivableLedgerAccountId,
        public readonly string $revenueLedgerAccountId,
        public readonly bool $accountsValid,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
