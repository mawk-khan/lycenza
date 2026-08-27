<?php

namespace App\Domain\Payments\Application;

/**
 * Phase 0G.5: the typed input for
 * `PaymentProviderEventService::recordSettlement()`. `settlementLedgerAccountId`
 * is an EXPLICIT input (rule 11: never a hardcoded/magic-looked-up
 * account) -- the School-scoped asset ledger account (e.g. "Cash"/
 * "Bank") the settled money is debited into.
 *
 * @param  list<ChargeAllocationInput>  $allocations
 */
final class RecordSettlementData
{
    public function __construct(
        public readonly NormalizedProviderEvent $event,
        public readonly string $settlementLedgerAccountId,
        public readonly array $allocations,
    ) {}
}
