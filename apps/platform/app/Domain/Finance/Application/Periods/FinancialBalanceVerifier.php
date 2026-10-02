<?php

namespace App\Domain\Finance\Application\Periods;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

/**
 * E21.3A (ADR 0064 §6): the dual-read check. The OLD reading derives every
 * balance from the whole history (what every Finance read does today); the
 * NEW reading starts from the latest closed baseline and adds only later
 * detail. They must be exactly equal for every ledger account, every
 * charge (outstanding, allocations, live adjustments, cancellation) and
 * every Student's dues. The close runs it inside its own transaction and
 * rolls back on any difference; `platform:finance-balances-verify` runs it
 * read-only. Read-only, no capability check (the caller authorizes).
 */
class FinancialBalanceVerifier
{
    /** @param  iterable<FinancialPeriodCloseParticipant>  $participants */
    public function __construct(
        private readonly FinancialPeriodService $periods,
        private readonly LedgerPeriodBalances $ledger,
        private readonly TenantContext $context,
        #[Tag(FinancialPeriodCloseParticipant::TAG)] private readonly iterable $participants,
    ) {}

    /**
     * Inside the caller's transaction (the close holds the period locks).
     *
     * @phpstan-impure
     */
    public function verify(School $school): FinancialBalanceVerification
    {
        return $this->context->withSchool($school, function () use ($school) {
            $index = $this->periods->journalPeriodIndex($school);
            $participantMismatches = [];
            foreach ($this->participants as $participant) {
                $participantMismatches[$participant->key()] = $participant->verify($school, $index);
            }

            return new FinancialBalanceVerification(
                $index->latestClosed?->key,
                $this->ledger->mismatches($school),
                $participantMismatches,
            );
        });
    }

    /**
     * One consistent read-only snapshot (REPEATABLE READ), for the verify
     * command. Inside an existing transaction it reads in that transaction.
     *
     * @phpstan-impure
     */
    public function verifySnapshot(School $school): FinancialBalanceVerification
    {
        $outermost = DB::transactionLevel() === 0;

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $outermost) {
            if ($outermost) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $this->verify($school);
        }));
    }
}
