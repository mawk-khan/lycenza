<?php

namespace App\Domain\Finance\Application\Retention;

use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E21.3A2 (E21-D8, project-adopted, pending legal ratification): when a
 * closed financial period's detail may expire.
 *
 * The trigger is the period's CLOSE, never a posting, creation or receipt
 * date: eligible exactly when `closed_at <= now - N calendar years`
 * (`isAgeEligible`, the same arithmetic as the database floor). A period closed during the
 * E21.3A cutover therefore starts its clock at that close, even if its
 * financial year ended long before: retention never predates trustworthy
 * evidence of the close (ADR 0064 §16).
 *
 * Configuration fails closed:
 * - `FINANCE_RETENTION_ENABLED` must be true, else `retention_cutover_not_enabled`;
 * - `FINANCE_RETENTION_YEARS` unset means nothing is eligible; set below
 *   the adopted 8 (the database floor) or not a whole number is an error.
 */
class FinanceRetentionEligibility
{
    public const MINIMUM_YEARS = 8;

    public function __construct(
        private readonly FinancialPeriodService $periods,
        private readonly RetentionHolds $holds,
        private readonly TenantContext $context,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('retention.finance_enabled', false);
    }

    /** @throws InvalidArgumentException when set but invalid or shorter than 8 years */
    public function years(): ?int
    {
        $years = RetentionPeriod::years(config('retention.finance_years'));
        if ($years !== null && $years < self::MINIMUM_YEARS) {
            throw new InvalidArgumentException('FINANCE_RETENTION_YEARS must be at least '.self::MINIMUM_YEARS.' (the adopted D8 period and the database floor).');
        }

        return $years;
    }

    /**
     * Age-eligible exactly when `closed_at <= now - N calendar years`, the
     * database floor's own arithmetic (`now() - interval 'N years'`, no
     * overflow): closed 2028-02-29 12:00 becomes eligible at 2036-02-29
     * 12:00; closed 2092-02-29 at 2100-03-01 00:00 (2100 has no 29
     * February). An open period is never eligible.
     */
    public static function isAgeEligible(FinancialPeriodSummary $period, int $years, CarbonImmutable $now): bool
    {
        return $period->closedAt !== null
            && $now->utc()->subYearsNoOverflow($years)->gte(CarbonImmutable::parse($period->closedAt)->utc());
    }

    /**
     * Why this period's detail may not expire now (codes, in order); [] when
     * it may. `$years` null means retention is not configured.
     *
     * @return list<string>
     */
    public function blockers(School $school, FinancialPeriodSummary $period, CarbonImmutable $now, ?int $years): array
    {
        $blockers = [];
        if (! $this->enabled() || $years === null) {
            $blockers[] = 'retention_cutover_not_enabled';
        }
        if (! $period->isClosed()) {
            $blockers[] = 'period_not_closed';
        } elseif ($years !== null && ! self::isAgeEligible($period, $years, $now)) {
            $blockers[] = 'period_too_young';
        }
        if ($this->periods->unmappedEntryCount($school, $period->endsOn) > 0) {
            $blockers[] = 'period_mapping_incomplete';
        }
        if ($period->isClosed() && ! $this->hasBaseline($school, $period)) {
            $blockers[] = 'baseline_missing';
        }
        if ($this->holds->isHeld($school->id)) {
            $blockers[] = 'legal_hold';
        }

        return $blockers;
    }

    /**
     * The latest closed period up to which every period may expire now: the
     * last of the unbroken run of unblocked periods, oldest first. Null when
     * the oldest is blocked.
     */
    public function horizon(School $school, CarbonImmutable $now, ?int $years): ?FinancialPeriodSummary
    {
        $horizon = null;
        foreach (array_reverse($this->periods->periods($school)) as $period) {
            if ($this->blockers($school, $period, $now, $years) !== []) {
                break;
            }
            $horizon = $period;
        }

        return $horizon;
    }

    /**
     * A closed period's account baseline is present when it has rows, or
     * when nothing at all had been posted through it (a cumulative zero).
     */
    private function hasBaseline(School $school, FinancialPeriodSummary $period): bool
    {
        return $this->context->withSchool($school, function () use ($school, $period) {
            if (DB::table('financial_period_account_balances')->where('school_id', $school->id)->where('financial_period_id', $period->id)->exists()) {
                return true;
            }

            return ! DB::table('journal_entries as e')
                ->join('financial_periods as p', fn ($j) => $j->on('p.id', '=', 'e.financial_period_id')->on('p.school_id', '=', 'e.school_id'))
                ->where('e.school_id', $school->id)
                ->where('p.starts_on', '<=', $period->startsOn)
                ->exists()
                && ! DB::table('financial_period_expiries')->where('school_id', $school->id)->exists();
        });
    }
}
