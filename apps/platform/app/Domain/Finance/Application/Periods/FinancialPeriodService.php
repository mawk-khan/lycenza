<?php

namespace App\Domain\Finance\Application\Periods;

use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.3A (ADR 0064): trusted, read-mostly access to a School's financial
 * periods. It performs no capability check; the calling layer authorizes.
 * The only write is creating the CURRENT period on demand, which is the
 * same thing a posting would do.
 */
class FinancialPeriodService
{
    public function __construct(private readonly TenantContext $context) {}

    public function localToday(School $school): string
    {
        return now()->setTimezone($school->timezone ?: 'UTC')->toDateString();
    }

    /** The period containing the School-local today, created open when missing. */
    public function current(School $school): FinancialPeriodSummary
    {
        return $this->context->withSchool($school, function () use ($school) {
            $id = FinancialPeriod::ensureContaining($school->id, $school->timezone ?: 'UTC', now());

            return FinancialPeriodSummary::fromModel(FinancialPeriod::query()->findOrFail($id));
        });
    }

    /** @return list<FinancialPeriodSummary> newest first */
    public function periods(School $school): array
    {
        return $this->context->withSchool($school, fn () => FinancialPeriod::query()
            ->where('school_id', $school->id)
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (FinancialPeriod $period) => FinancialPeriodSummary::fromModel($period))
            ->values()
            ->all());
    }

    public function find(School $school, string $periodId): ?FinancialPeriodSummary
    {
        $period = $this->context->withSchool($school, fn () => FinancialPeriod::query()
            ->where('school_id', $school->id)
            ->whereKey($periodId)
            ->first());

        return $period === null ? null : FinancialPeriodSummary::fromModel($period);
    }

    public function latestClosed(School $school): ?FinancialPeriodSummary
    {
        $period = $this->context->withSchool($school, fn () => FinancialPeriod::query()
            ->where('school_id', $school->id)
            ->where('status', FinancialPeriod::CLOSED)
            ->orderByDesc('starts_on')
            ->first());

        return $period === null ? null : FinancialPeriodSummary::fromModel($period);
    }

    /** Every journal entry of the School with its period start, plus the latest closed period. */
    public function journalPeriodIndex(School $school): JournalPeriodIndex
    {
        return $this->context->withSchool($school, function () use ($school) {
            $starts = [];
            foreach (DB::cursor(
                'SELECT e.id, p.starts_on FROM journal_entries e LEFT JOIN financial_periods p ON p.id = e.financial_period_id AND p.school_id = e.school_id WHERE e.school_id = ?',
                [$school->id],
            ) as $row) {
                $starts[(string) $row->id] = $row->starts_on === null ? null : (string) $row->starts_on;
            }

            return new JournalPeriodIndex($starts, $this->latestClosed($school));
        });
    }

    /**
     * Entries posted before E21.3A and not yet mapped to a period, counted
     * through a School-local date (inclusive), or all of them.
     */
    public function unmappedEntryCount(School $school, ?string $throughLocalDate = null): int
    {
        return $this->context->withSchool($school, function () use ($school, $throughLocalDate) {
            $query = DB::table('journal_entries')->where('school_id', $school->id)->whereNull('financial_period_id');
            if ($throughLocalDate !== null) {
                $query->whereRaw("((posted_at AT TIME ZONE 'UTC') AT TIME ZONE ?)::date <= ?::date", [$school->timezone ?: 'UTC', $throughLocalDate]);
            }

            return $query->count();
        });
    }

    public function entryCountIn(School $school, string $periodId): int
    {
        return $this->context->withSchool($school, fn () => DB::table('journal_entries')
            ->where('school_id', $school->id)
            ->where('financial_period_id', $periodId)
            ->count());
    }
}
