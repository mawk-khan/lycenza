<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\InvalidChargeAccountException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

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
 *
 * UAT-C4 (UAT-FIN-11, UAT-FIN-20; ADR 0075 section 17): an ad-hoc charge
 * follows the same account rule as every fee head (FEE.1): the receivable
 * is an active asset account and the revenue an active income account of
 * the School. `similarUncancelledCharges()` finds charges the new one looks
 * like, so the form can warn before a Student is billed twice -- a warning
 * only; nothing is refused for resembling another charge.
 */
class ChargeAdministrationService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly LedgerService $ledger,
        private readonly TenantContext $context,
    ) {}

    public function assess(School $school, AssessChargeData $data, User $actor): ChargeResult
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.manage', $school);

        if (! $this->accountsOfType($school, 'asset')->contains($data->receivableLedgerAccountId)) {
            throw new InvalidChargeAccountException('receivable_ledger_account_id', 'The receivable account must be an active asset account of this School.');
        }
        if (! $this->accountsOfType($school, 'income')->contains($data->revenueLedgerAccountId)) {
            throw new InvalidChargeAccountException('revenue_ledger_account_id', 'The revenue account must be an active income account of this School.');
        }

        return $this->charges->assess($school, $data, $actor);
    }

    /**
     * The accounts an ad-hoc charge may use: `asset` (receivable) or
     * `income` (revenue), active, this School's.
     *
     * @return Collection<int, LedgerAccountSummary>
     */
    public function accountChoices(School $school, string $type, User $actor): Collection
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.manage', $school);

        return $this->ledger->activeAccountsOfType($school, $type);
    }

    /**
     * Uncancelled charges of the same Student and academic year that the
     * new one resembles: the same description once case, spacing and
     * punctuation are ignored, one description containing the other
     * ("Term 2 Tuition Fee" inside "Term 2 tuition fee 2026-27"), or the
     * same amount due on the same date. At most 10, newest first.
     *
     * @return list<array{chargeId: string, description: string, amount: string, currency: string, dueDate: string|null, assessedOn: string}>
     */
    public function similarUncancelledCharges(School $school, string $studentId, string $academicYearId, string $description, Money $amount, ?string $dueDate, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.manage', $school);

        $wanted = self::normalize($description);

        return $this->context->withSchool($school, fn () => Charge::query()
            ->where('school_id', $school->id)
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->whereNull('cancelled_at')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->filter(function (Charge $c) use ($wanted, $amount, $dueDate) {
                $existing = self::normalize($c->description);
                $shorter = min(strlen($existing), strlen($wanted));

                return $existing === $wanted
                    || ($shorter >= 6 && (str_contains($existing, $wanted) || str_contains($wanted, $existing)))
                    || ($dueDate !== null && $c->due_date?->toDateString() === $dueDate && bccomp($c->amount, $amount->amount(), 2) === 0);
            })
            ->take(10)
            ->map(fn (Charge $c) => [
                'chargeId' => $c->id,
                'description' => $c->description,
                'amount' => $c->amount,
                'currency' => $c->currency,
                'dueDate' => $c->due_date?->toDateString(),
                'assessedOn' => $c->created_at->copy()->setTimezone($school->timezone)->toDateString(),
            ])->values()->all());
    }

    private static function normalize(string $description): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($description));
    }

    /** @return Collection<int, string> */
    private function accountsOfType(School $school, string $type): Collection
    {
        return $this->ledger->activeAccountsOfType($school, $type)->map(fn (LedgerAccountSummary $a) => $a->ledgerAccountId);
    }

    public function cancel(School $school, string $chargeId, User $actor, ?string $reason = null): ChargeResult
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.manage', $school);

        return $this->charges->cancel($school, $chargeId, $actor, $reason);
    }
}
