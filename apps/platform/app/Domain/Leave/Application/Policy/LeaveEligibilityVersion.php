<?php

namespace App\Domain\Leave\Application\Policy;

/**
 * HR.9: one policy version as the eligibility resolver sees it -- an active
 * version (with its stored rule ids) or a draft being previewed (rule ids
 * null). `annualAllocationUnits` is what the existing annual allocation run
 * would grant (no proration, ADR 0065 §22.2).
 */
final readonly class LeaveEligibilityVersion
{
    /** @param  list<array{0: ?string, 1: LeaveEligibilityCriteria}>  $rules */
    public function __construct(
        public string $policyId,
        public string $name,
        public int $annualAllocationUnits,
        public array $rules,
    ) {}
}
