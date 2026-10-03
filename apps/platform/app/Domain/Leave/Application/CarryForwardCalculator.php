<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Infrastructure\LeavePolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * HRX.1 (ADR 0065 §4.6): the deterministic year-close arithmetic for ONE
 * balance, in integer half-day units. Pure. HRX.2's year-close run writes
 * its result as `carry_forward_out` (closing year), `carry_forward_in` (new
 * year) and `expiry` entries; nothing is recalculated in place.
 *
 * - carried = 0 unless the policy allows carry-forward; else
 *   min(closing balance, cap);
 * - lapsed  = closing balance - carried (expires at the close);
 * - the carried units expire `carry_forward_expiry_days` after the new year
 *   starts (null: they do not expire within the year).
 */
final class CarryForwardCalculator
{
    /** @return array{carried: int, lapsed: int, carried_expire_on: ?string} */
    public function plan(LeavePolicy $policy, int $closingBalance, string $newYearStartsOn): array
    {
        if ($closingBalance < 0) {
            throw new InvalidArgumentException('A leave balance is never negative.');
        }

        $carried = $policy->carry_forward_allowed ? min($closingBalance, (int) $policy->carry_forward_cap_units) : 0;
        $expireOn = $carried > 0 && $policy->carry_forward_expiry_days !== null
            ? CarbonImmutable::createFromFormat('!Y-m-d', $newYearStartsOn)->addDays((int) $policy->carry_forward_expiry_days)->toDateString()
            : null;

        return ['carried' => $carried, 'lapsed' => $closingBalance - $carried, 'carried_expire_on' => $expireOn];
    }
}
