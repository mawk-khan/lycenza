<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use Illuminate\Support\Collection;

/**
 * UAT-C6 (UAT-FIN-20, ADR 0075 section 19): which existing charges MAY
 * already cover the period a fee-run item would bill -- a review signal,
 * never a verdict (owner decision: no automatic exclusion, no silent
 * inclusion; every overlap gets an explicit decision).
 *
 * What is authoritative stays authoritative: a live `fee_assessments` row
 * for the same Student, year, fee head and billing period is
 * `already_assessed` (and `fee_assessments_one_live_per_period` decides at
 * execution). Any charge a fee assessment created -- of any run, head or
 * period -- carries that provenance and is never a candidate here.
 *
 * Every OTHER uncancelled charge of the Student in the structure's academic
 * year (ad-hoc charges carry no head or period) is a potential overlap when
 * any of these holds:
 *
 * 1. it posts to the fee head's revenue (income) account and its due date
 *    falls inside the instalment's billing period (inclusive);
 * 2. its description resembles the charge the run would create,
 *    "{head} · {instalment}" (`SimilarCharge`: equal ignoring case, spacing
 *    and punctuation, or one contains the other, at least 6 characters);
 * 3. it has the instalment's amount and due date (`SimilarCharge`).
 *
 * Cancelled charges, other Students, other academic years and other Schools
 * (tenant scope) never match.
 */
class FeeRunOverlapDetector
{
    /**
     * The candidate charges of these Students in one academic year, by Student.
     * Call inside the School's TenantContext.
     *
     * @param  list<string>  $studentIds
     * @return Collection<array-key, Collection<int, Charge>>
     */
    public function pool(string $academicYearId, array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return Charge::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('student_id', $studentIds)
            ->whereNull('cancelled_at')
            ->whereNotIn('id', FeeAssessment::query()->select('charge_id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('student_id')
            ->toBase()
            ->map(fn ($charges) => $charges->toBase());
    }

    /**
     * The ids of the charges in $charges that may cover this item's period.
     *
     * @param  Collection<int, Charge>  $charges  one Student's pool
     * @return list<string>
     */
    public function overlapping(Collection $charges, FeeHead $head, FeeStructureInstallment $installment): array
    {
        $from = $installment->period_starts_on->toDateString();
        $to = $installment->period_ends_on->toDateString();
        $description = self::proposedDescription($head, $installment);
        $amount = (string) $installment->amount;
        $due = $installment->due_date->toDateString();

        return $charges
            ->filter(function (Charge $c) use ($head, $from, $to, $description, $amount, $due) {
                $dueDate = $c->due_date?->toDateString();

                return ($c->revenue_ledger_account_id === $head->revenue_ledger_account_id && $dueDate !== null && $dueDate >= $from && $dueDate <= $to)
                    || SimilarCharge::descriptionsResemble($c->description, $description)
                    || SimilarCharge::sameAmountAndDueDate($c, $amount, $due);
            })
            ->pluck('id')
            ->values()
            ->all();
    }

    /** The description a fee run gives the charge it creates (the executor uses the same). */
    public static function proposedDescription(FeeHead $head, FeeStructureInstallment $installment): string
    {
        return mb_substr("{$head->name} · {$installment->label}", 0, 255);
    }
}
