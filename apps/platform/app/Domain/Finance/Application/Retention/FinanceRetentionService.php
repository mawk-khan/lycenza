<?php

namespace App\Domain\Finance\Application\Retention;

use App\Domain\Finance\Application\LedgerBalanceReader;
use App\Domain\Finance\Application\Periods\FinancialBalanceVerifier;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseService;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Periods\JournalPeriodIndex;
use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * E21.3A2 (E21-D8, ADR 0064 §14-§18): expires the detail of closed financial
 * periods, PERIOD by period, in dependency-safe UNITS. Only
 * `platform:finance-retention-prune` calls it.
 *
 * Per School:
 * 1. Holds: a held School expires nothing (its units count as held).
 * 2. The horizon: the latest closed period up to which every period is
 *    eligible (`FinanceRetentionEligibility`: enabled, configured, closed
 *    >= N calendar years ago, fully mapped, baseline present).
 * 3. Accounting verification BEFORE anything is deleted: the dual-read
 *    check must pass for the School, or nothing is deleted.
 * 4. Units:
 *    - Payments' settled charge clusters (charges, payments, allocations,
 *      adjustments, late fees, receipts, provider events);
 *    - Payroll's (always retained: D9 evidence references them);
 *    - Finance's own standalone journal entries, grouped with their
 *      reversal partners.
 *    A unit with any entry after the horizon is not yet eligible.
 * 5. Each unit is ONE transaction (REPEATABLE READ: one snapshot):
 *    - exact readings before (every account balance through the production
 *      read, plus the participant's: affected charges, Student dues,
 *      receipt counters);
 *    - the database expiry (which re-verifies the unit itself);
 *    - the same readings after.
 *    Any difference throws and rolls the unit back.
 * 6. The dual-read check again after the School's pass.
 *
 * Counts only; no identifier, amount or name leaves this class.
 */
class FinanceRetentionService
{
    /** @param  iterable<FinanceRetentionParticipant>  $participants */
    public function __construct(
        private readonly FinancialPeriodService $periods,
        private readonly FinanceRetentionEligibility $eligibility,
        private readonly FinancialBalanceVerifier $verifier,
        private readonly LedgerBalanceReader $balances,
        private readonly RetentionExpiry $expiry,
        private readonly RetentionHolds $holds,
        private readonly TenantContext $context,
        #[Tag(FinanceRetentionParticipant::TAG)] private readonly iterable $participants,
    ) {}

    /**
     * @return array{periods_eligible: int, eligible: int, deleted: int, held: int, dependency_blocked: int, errors: int, verification_failed: int}
     */
    public function prune(School $school, int $limit, bool $dryRun, ?int $years): array
    {
        $result = ['periods_eligible' => 0, 'eligible' => 0, 'deleted' => 0, 'held' => 0, 'dependency_blocked' => 0, 'errors' => 0, 'verification_failed' => 0];
        $held = $this->holds->isHeld($school->id);
        $now = CarbonImmutable::now('UTC');

        // A held School is only counted (its horizon ignores the hold); a dry
        // run previews even before the explicit switch is on.
        $horizon = $this->horizon($school, $now, $years, array_values(array_filter([
            $held ? 'legal_hold' : null,
            $dryRun ? 'retention_cutover_not_enabled' : null,
        ])));
        if ($horizon === null) {
            return $result;
        }
        $result['periods_eligible'] = $this->periodsThrough($school, $horizon);

        $before = $this->verifier->verifySnapshot($school);
        if (! $before->passed()) {
            $result['verification_failed'] = 1;
            Log::error('retention.finance_prune.verification_failed', ['school_id' => $school->id, 'phase' => 'before']);

            return $result;
        }

        $units = $this->plan($school, $horizon);
        $deletedAny = false;
        foreach ($units as $unit) {
            if ($unit->isBlocked()) {
                $result['dependency_blocked']++;

                continue;
            }
            if ($result['eligible'] >= $limit) {
                break;
            }
            $result['eligible']++;
            if ($held) {
                $result['held']++;

                continue;
            }

            $outcome = $this->expire($school, $unit, $dryRun);
            if ($outcome === 'dependency_blocked') {
                // The database refused it: not eligible after all.
                $result['eligible']--;
            }
            if ($outcome !== 'validated') {
                $result[$outcome] = $result[$outcome] + 1;
            }
            $deletedAny = $deletedAny || $outcome === 'deleted';
        }

        if ($deletedAny) {
            $after = $this->verifier->verifySnapshot($school);
            if (! $after->passed()) {
                $result['verification_failed'] = 1;
                Log::error('retention.finance_prune.verification_failed', ['school_id' => $school->id, 'phase' => 'after']);
            }
        }

        return $result;
    }

    /**
     * Every unit at or before the horizon, deterministically ordered.
     *
     * @return list<FinanceRetentionUnit>
     */
    public function plan(School $school, FinancialPeriodSummary $horizon): array
    {
        return $this->context->withSchool($school, function () use ($school, $horizon) {
            $index = $this->periods->journalPeriodIndex($school);
            $groups = $this->reversalGroups($school, $index);
            $claimed = [];
            $moduleUnits = [];
            foreach ($this->participants as $participant) {
                $claimed += $participant->claimedEntryIds($school);
                foreach ($participant->units($school, $horizon, $index) as $unit) {
                    $moduleUnits[] = $unit;
                }
            }

            // A module unit takes along any unclaimed reversal of its own
            // entries (e.g. a ledger reversal of a charge's entry); if that
            // reversal is after the horizon the unit is not yet eligible.
            $units = [];
            foreach ($moduleUnits as $unit) {
                $extra = [];
                $later = false;
                foreach ($unit->entryIds as $entryId) {
                    foreach ($groups['members'][$groups['root'][$entryId] ?? $entryId] ?? [] as $member) {
                        if (! isset($claimed[$member])) {
                            $extra[$member] = true;
                            $later = $later || ! $index->isThrough($member, $horizon);
                        }
                    }
                }
                if ($later) {
                    continue;
                }
                $units[] = $extra === [] ? $unit : new FinanceRetentionUnit($unit->participant, $unit->chargeIds,
                    array_values(array_unique(array_merge($unit->entryIds, array_keys($extra)))), $unit->studentIds, $unit->blockedReason);
            }
            foreach ($this->standaloneUnits($horizon, $index, $groups, $claimed) as $unit) {
                $units[] = $unit;
            }

            usort($units, fn (FinanceRetentionUnit $a, FinanceRetentionUnit $b) => [$a->participant, $a->chargeIds[0] ?? '', $a->entryIds[0] ?? ''] <=> [$b->participant, $b->chargeIds[0] ?? '', $b->entryIds[0] ?? '']);

            return $units;
        });
    }

    /** @return 'validated'|'deleted'|'dependency_blocked'|'errors' ('validated': a dry run the database accepted) */
    private function expire(School $school, FinanceRetentionUnit $unit, bool $dryRun): string
    {
        $participant = $this->participant($unit->participant);
        $outermost = DB::transactionLevel() === 0;

        try {
            return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $unit, $participant, $dryRun, $outermost) {
                if ($outermost) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                // The School's period-maintenance lock first (the close takes it too).
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [FinancialPeriodCloseService::maintenanceLockKey($school)]);

                if ($dryRun) {
                    $this->expiry->financeUnit($school, $unit->chargeIds, $unit->entryIds, true);

                    return 'validated';
                }

                $before = $this->readings($school, $unit, $participant);
                $this->expiry->financeUnit($school, $unit->chargeIds, $unit->entryIds, false);
                $after = $this->readings($school, $unit, $participant);

                if ($before !== $after) {
                    throw new RuntimeException('finance retention: an accounting reading changed');
                }

                return 'deleted';
            }));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'retention_finance_dependency') || str_contains($e->getMessage(), 'retention_finance_open_charge')) {
                return 'dependency_blocked';
            }
            Log::warning('retention.finance_prune.unit_failed', ['school_id' => $school->id, 'participant' => $unit->participant, 'error' => $e::class]);

            return 'errors';
        } catch (RuntimeException $e) {
            Log::error('retention.finance_prune.unit_rolled_back', ['school_id' => $school->id, 'participant' => $unit->participant, 'reason' => 'reading_changed']);

            return 'errors';
        }
    }

    /** @return array<string, string> */
    private function readings(School $school, FinanceRetentionUnit $unit, ?FinanceRetentionParticipant $participant): array
    {
        $readings = [];
        foreach ($this->balances->balances($school) as $key => $balance) {
            $readings["account:{$key}"] = $balance['debit'].'|'.$balance['credit'];
        }
        foreach ($participant?->readings($school, $unit) ?? [] as $key => $value) {
            $readings[$unit->participant.':'.$key] = $value;
        }
        ksort($readings);

        return $readings;
    }

    /**
     * Journal entries grouped by reversal links (an entry, its reversal, a
     * reversal of that reversal, ...).
     *
     * @return array{root: array<string, string>, members: array<string, list<string>>}
     */
    private function reversalGroups(School $school, JournalPeriodIndex $index): array
    {
        $parent = [];
        $find = function (string $id) use (&$parent, &$find): string {
            if (! isset($parent[$id]) || $parent[$id] === $id) {
                return $parent[$id] = $id;
            }

            return $parent[$id] = $find($parent[$id]);
        };
        foreach (DB::table('journal_entries')->where('school_id', $school->id)->whereNotNull('reversal_of_journal_entry_id')
            ->get(['id', 'reversal_of_journal_entry_id']) as $row) {
            $parent[$find((string) $row->id)] = $find((string) $row->reversal_of_journal_entry_id);
        }

        $root = [];
        $members = [];
        foreach ($index->entryIds() as $id) {
            $root[$id] = $find($id);
            $members[$root[$id]][] = $id;
        }

        return ['root' => $root, 'members' => $members];
    }

    /**
     * Finance's own units: groups of journal entries no module references
     * (manual postings and their reversals). A group touching a module's
     * entry goes with that module's unit; a group with any entry after the
     * horizon is not yet eligible.
     *
     * @param  array{root: array<string, string>, members: array<string, list<string>>}  $groups
     * @param  array<string, true>  $claimed
     * @return list<FinanceRetentionUnit>
     */
    private function standaloneUnits(FinancialPeriodSummary $horizon, JournalPeriodIndex $index, array $groups, array $claimed): array
    {
        $units = [];
        foreach ($groups['members'] as $members) {
            if (array_filter($members, fn (string $id) => isset($claimed[$id]) || ! $index->isThrough($id, $horizon)) !== []) {
                continue;
            }
            sort($members);
            $units[] = new FinanceRetentionUnit('ledger', [], $members);
        }

        return $units;
    }

    private function participant(string $key): ?FinanceRetentionParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->key() === $key) {
                return $participant;
            }
        }

        return null;
    }

    private function periodsThrough(School $school, FinancialPeriodSummary $horizon): int
    {
        return count(array_filter($this->periods->periods($school), fn (FinancialPeriodSummary $p) => $p->startsOn <= $horizon->startsOn));
    }

    /**
     * `FinanceRetentionEligibility::horizon()`, ignoring the named blockers
     * (counting only: a held School, a dry run before the switch).
     *
     * @param  list<string>  $ignored
     */
    private function horizon(School $school, CarbonImmutable $now, ?int $years, array $ignored): ?FinancialPeriodSummary
    {
        if ($ignored === []) {
            return $this->eligibility->horizon($school, $now, $years);
        }

        $horizon = null;
        foreach (array_reverse($this->periods->periods($school)) as $period) {
            if (array_diff($this->eligibility->blockers($school, $period, $now, $years), $ignored) !== []) {
                break;
            }
            $horizon = $period;
        }

        return $horizon;
    }
}
