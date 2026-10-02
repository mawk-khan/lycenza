<?php

namespace App\Domain\Finance\Application\Periods;

use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E21.3A (ADR 0064 §3): gives every journal entry posted before E21.3A its
 * financial period. Deterministic and rerunnable: it only ever looks at
 * entries whose period is still NULL, oldest first, in batches. A dry run
 * writes nothing at all (no period is created either).
 *
 * The period follows the School's CURRENT start month. That is only
 * provably right when the start month was the same when the entry was
 * posted, so an entry is AMBIGUOUS, left unmapped and retained, when:
 * - an audited start-month change happened at or after its posting; or
 * - its own posting audit event is no longer retained, so the audit
 *   history from its posting on cannot be shown complete and a change
 *   cannot be ruled out (audit retention removes the oldest first).
 * An ambiguous entry blocks closing any period up to its date (the close
 * refuses unmapped entries); resolving one is a later, explicit decision.
 *
 * The only write is the narrow `finance_assign_journal_entry_period`
 * (NULL -> the containing open period, own School only).
 */
class FinancialPeriodBackfillService
{
    private const MONTH_CHANGE_EVENT = 'fee_settings.receipt_numbering_changed';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function backfill(School $school, bool $dryRun, int $batchSize = 500): FinancialPeriodBackfillResult
    {
        return $this->context->withSchool($school, function () use ($school, $dryRun, $batchSize) {
            [$monthChanges, $audited] = $this->auditFacts($school);
            $timezone = $school->timezone ?: 'UTC';
            $counts = ['mapped' => 0, 'ambiguous' => 0, 'blocked' => 0, 'error' => 0];
            $after = null;

            do {
                $entries = DB::table('journal_entries')
                    ->where('school_id', $school->id)
                    ->whereNull('financial_period_id')
                    ->when($after !== null, fn ($q) => $q->where(fn ($w) => $w->where('posted_at', '>', $after[0])->orWhere(fn ($e) => $e->where('posted_at', $after[0])->where('id', '>', $after[1]))))
                    ->orderBy('posted_at')->orderBy('id')
                    ->limit($batchSize)
                    ->get(['id', 'posted_at']);

                foreach ($entries as $entry) {
                    $postedAt = CarbonImmutable::parse((string) $entry->posted_at, 'UTC');
                    $after = [(string) $entry->posted_at, (string) $entry->id];

                    if (! isset($audited[(string) $entry->id])
                        || array_filter($monthChanges, fn (CarbonImmutable $at) => $at->gte($postedAt)) !== []) {
                        $counts['ambiguous']++;

                        continue;
                    }

                    $counts[$this->mapOne($school, (string) $entry->id, $postedAt, $timezone, $dryRun)]++;
                }
            } while ($entries->count() === $batchSize);

            $result = new FinancialPeriodBackfillResult(
                $dryRun, $counts['mapped'], $counts['ambiguous'], $counts['blocked'], $counts['error'],
                $dryRun ? $counts['mapped'] + $counts['ambiguous'] + $counts['blocked'] + $counts['error'] : DB::table('journal_entries')->where('school_id', $school->id)->whereNull('financial_period_id')->count(),
            );

            if (! $dryRun && array_sum($counts) > 0) {
                $this->audit->school($school, 'financial_period.backfill_applied', metadata: $result->counts());
            }

            return $result;
        });
    }

    /** @return 'mapped'|'blocked'|'error' */
    private function mapOne(School $school, string $entryId, CarbonImmutable $postedAt, string $timezone, bool $dryRun): string
    {
        $local = $postedAt->setTimezone($timezone)->toDateString();
        $closedAfter = FinancialPeriod::query()->where('school_id', $school->id)
            ->where('status', FinancialPeriod::CLOSED)
            ->where('ends_on', '>=', $local)
            ->exists();
        if ($closedAfter) {
            return 'blocked';
        }
        if ($dryRun) {
            return 'mapped';
        }

        try {
            return DB::transaction(function () use ($school, $entryId, $postedAt, $timezone) {
                $periodId = FinancialPeriod::ensureContaining($school->id, $timezone, $postedAt);
                $assigned = DB::selectOne('SELECT finance_assign_journal_entry_period(?, ?) AS ok', [$entryId, $periodId])->ok;

                return $assigned ? 'mapped' : 'error';
            });
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'financial_period_closed') || str_contains($e->getMessage(), 'is closed')) {
                return 'blocked';
            }
            Log::warning('finance.period_backfill.entry_failed', ['school_id' => $school->id, 'journal_entry_id' => $entryId, 'error' => $e::class]);

            return 'error';
        }
    }

    /** @return array{0: list<CarbonImmutable>, 1: array<string, true>} start-month changes; entries whose posting audit is retained */
    private function auditFacts(School $school): array
    {
        $changes = [];
        foreach (SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', self::MONTH_CHANGE_EVENT)->get(['occurred_at', 'metadata']) as $event) {
            $before = $event->metadata['before']['month'] ?? null;
            $after = $event->metadata['after']['month'] ?? null;
            if ($before !== $after) {
                $changes[] = CarbonImmutable::parse($event->occurred_at->format('Y-m-d H:i:s.u'), 'UTC');
            }
        }

        $audited = [];
        foreach (SchoolAuditEvent::query()->where('school_id', $school->id)
            ->where('subject_type', JournalEntry::class)
            ->whereIn('event_type', ['journal_entry.posted', 'journal_entry.reversed'])
            ->toBase()->select('subject_id')->cursor() as $row) {
            $audited[(string) $row->subject_id] = true;
        }

        return [$changes, $audited];
    }
}
