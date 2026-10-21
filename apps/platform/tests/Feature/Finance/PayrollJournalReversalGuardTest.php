<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Exceptions\JournalEntryNotReversibleException;
use App\Domain\Finance\Application\LedgerAdministrationService;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollPostingService;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * ORG.8-P1 (ADR 0076 §57, closes ORG.8 G-1): a posted payroll run's payroll
 * and statutory journal entries are reversed only through Payroll, which
 * records the reversal posting that keeps run state and ledger in step. The
 * generic ledger reversal refuses both originals and both reversal entries;
 * Payroll's own reversal still works and satisfies the database check.
 */
class PayrollJournalReversalGuardTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    /** @return array{0: School, 1: string, 2: User, 3: User} school, run id, ledger reverser, payroll actor */
    private function postedWorld(): array
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $this->statutorySetup($school, $setup);
        $this->paidEmployee($school, $setup);
        $run = $this->postedRun($school, '2015-08-01', '2015-08-31', statutory: true);

        return [$school, $run->id, $this->createUserWithCapabilities($school, ['finance.ledger.view', 'finance.ledger.reverse']), $setup['actor']];
    }

    /** @return array<string, string> posting kind => journal entry id, for both posting tables */
    private function entries(School $school, string $runId): array
    {
        return $this->inSchool($school, fn () => [
            ...DB::table('payroll_run_postings')->where('payroll_run_id', $runId)->pluck('journal_entry_id', 'posting_kind')->mapWithKeys(fn ($id, $kind) => ["payroll_{$kind}" => (string) $id])->all(),
            ...DB::table('payroll_statutory_run_postings')->where('payroll_run_id', $runId)->pluck('journal_entry_id', 'posting_kind')->mapWithKeys(fn ($id, $kind) => ["statutory_{$kind}" => (string) $id])->all(),
        ]);
    }

    private function assertGenericallyRefused(School $school, User $reverser, array $entries): void
    {
        $before = $this->inSchool($school, fn () => JournalEntry::query()->whereNotNull('reversal_of_journal_entry_id')->count());
        foreach ($entries as $kind => $entryId) {
            try {
                app(LedgerAdministrationService::class)->reverse($school, $entryId, $reverser, 'attempt');
                $this->fail("The {$kind} payroll journal entry must not be reversible through the generic ledger reversal.");
            } catch (JournalEntryNotReversibleException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->inSchool($school, fn () => JournalEntry::query()->whereNotNull('reversal_of_journal_entry_id')->count()));
    }

    #[Test]
    public function a_posted_runs_journal_entries_are_reversed_only_through_payroll(): void
    {
        [$school, $runId, $reverser, $actor] = $this->postedWorld();
        $originals = $this->entries($school, $runId);
        $this->assertSame(['payroll_original', 'statutory_original'], array_keys($originals));

        $this->assertGenericallyRefused($school, $reverser, $originals);

        $run = $this->inSchool($school, fn () => PayrollRun::query()->findOrFail($runId));
        $this->inSchool($school, fn () => app(StatutoryPayrollPostingService::class)->reverse($run, $actor, 'posted in error'));
        $this->inSchool($school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $actor, 'posted in error'));
        $this->inSchool($school, function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });

        $all = $this->entries($school, $runId);
        $this->assertSame($originals['payroll_original'], $this->inSchool($school, fn () => JournalEntry::query()->findOrFail($all['payroll_reversal'])->reversal_of_journal_entry_id));
        $this->assertSame($originals['statutory_original'], $this->inSchool($school, fn () => JournalEntry::query()->findOrFail($all['statutory_reversal'])->reversal_of_journal_entry_id));

        $this->assertGenericallyRefused($school, $reverser, ['payroll_reversal' => $all['payroll_reversal'], 'statutory_reversal' => $all['statutory_reversal']]);
    }

    #[Test]
    public function the_database_classifies_every_posting_kind(): void
    {
        [$school, $runId, , $actor] = $this->postedWorld();
        $run = $this->inSchool($school, fn () => PayrollRun::query()->findOrFail($runId));
        $this->inSchool($school, fn () => app(StatutoryPayrollPostingService::class)->reverse($run, $actor));
        $this->inSchool($school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $actor));

        $expected = [
            'payroll_original' => 'payroll_posting', 'payroll_reversal' => 'payroll_posting_reversal',
            'statutory_original' => 'payroll_statutory_posting', 'statutory_reversal' => 'payroll_statutory_posting_reversal',
        ];
        foreach ($this->entries($school, $runId) as $kind => $entryId) {
            $this->assertSame($expected[$kind], $this->inSchool($school, fn () => DB::selectOne('select public.journal_entry_subledger_owner(?, ?) as owner', [$entryId, $school->id])->owner), $kind);
        }
    }
}
