<?php

namespace Tests\Feature\Finance;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * ORG.8-P1 (ADR 0076 §57): committed, real PostgreSQL. A raw back-door
 * reversal of a posted run's payroll journal entry holds its uncommitted
 * reversal; Payroll's own reversal is observed BLOCKED on it, the back door
 * then fails at its own COMMIT (no reversal posting links it), and Payroll's
 * reversal proceeds: exactly one reversal, recorded by a reversal posting.
 */
class PayrollJournalReversalConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->purgeSchoolsAsAdmin([$this->school->id]);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_waiting_payroll_reversal_wins_over_a_raw_reversal_that_cannot_commit(): void
    {
        $this->school = $this->createSchool();
        $setup = $this->payrollSetup($this->school);
        $this->paidEmployee($this->school, $setup);
        $run = $this->postedRun($this->school, '2015-08-01', '2015-08-31');
        $original = $this->inSchool($this->school, fn () => (string) DB::table('payroll_run_postings')->where('payroll_run_id', $run->id)->value('journal_entry_id'));

        $script = __DIR__.'/../../Support/subledger-reversal-op.php';
        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $script, 'raw-reverse', $this->school->id, $original],
            ['php', $script, 'reverse-payroll', $this->school->id, $run->id, $setup['actor']->id],
        );

        $this->assertSame('rejected:guard', $holder, 'the back door fails at its own COMMIT');
        $this->assertSame('ok', $contender);
        $reversals = $this->inSchool($this->school, fn () => DB::table('journal_entries')->where('reversal_of_journal_entry_id', $original)->pluck('id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame($reversals, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $run->id)
            ->where('posting_kind', 'reversal')->pluck('journal_entry_id')->map(fn ($id) => (string) $id)->all()), 'the one reversal is the one Payroll recorded');
        $this->assertCount(1, $reversals);
    }
}
