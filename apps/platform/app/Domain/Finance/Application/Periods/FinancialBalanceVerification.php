<?php

namespace App\Domain\Finance\Application\Periods;

/**
 * E21.3A: the result of one dual-read check. Mismatches name ids and
 * codes only, never amounts or personal data.
 */
final class FinancialBalanceVerification
{
    /**
     * @param  list<string>  $accountMismatches
     * @param  array<string, list<string>>  $participantMismatches  participant key => mismatches
     */
    public function __construct(
        public readonly ?string $latestClosedKey,
        public readonly array $accountMismatches,
        public readonly array $participantMismatches,
    ) {}

    public function passed(): bool
    {
        return $this->all() === [];
    }

    /** @return list<string> */
    public function all(): array
    {
        $all = $this->accountMismatches;
        foreach ($this->participantMismatches as $key => $mismatches) {
            foreach ($mismatches as $mismatch) {
                $all[] = "{$key}:{$mismatch}";
            }
        }

        return $all;
    }
}
