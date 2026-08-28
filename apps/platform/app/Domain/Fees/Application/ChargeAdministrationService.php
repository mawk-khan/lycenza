<?php

namespace App\Domain\Fees\Application;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * Phase 0G.4 -- the authorized ADMINISTRATIVE entry point for
 * assessing and cancelling charges that a future HTTP/UI layer will
 * call, mirroring `App\Domain\Finance\Application\LedgerAdministrationService`'s
 * exact split from its own trusted core (`ChargeService`, which has no
 * authorization of its own -- see its docblock). No transport may
 * expose `ChargeService` directly; a future controller depends on this
 * class.
 *
 * Single capability, `finance.charges.manage`
 * (`docs/modules/FINANCE.md` "Authorization architecture"), gates both
 * `assess()` and `cancel()` -- FINANCE.md's own conceptual capability
 * family lists one `view`/`manage` pair for charges, not a finer
 * assess-vs-cancel split like Ledger's `post`/`reverse` (0G.3 already
 * decided ledger posting and reversal are distinct enough blast-radius
 * actions to warrant separate capabilities; charges assessment and
 * cancellation are both already gated behind the SAME "can administer
 * this School's receivables" responsibility FINANCE.md committed to).
 * A denied caller triggers zero charges, zero journal postings/
 * reversals, zero audit events, zero outbox events -- `Gate::authorize()`
 * throws before either method body reaches `ChargeService` at all.
 */
class ChargeAdministrationService
{
    use AuthorizesCapability;

    public function __construct(private readonly ChargeService $charges) {}

    public function assess(School $school, AssessChargeData $data, User $actor): ChargeResult
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.manage', $school);

        return $this->charges->assess($school, $data, $actor);
    }

    public function cancel(School $school, string $chargeId, User $actor, ?string $reason = null): ChargeResult
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.manage', $school);

        return $this->charges->cancel($school, $chargeId, $actor, $reason);
    }
}
