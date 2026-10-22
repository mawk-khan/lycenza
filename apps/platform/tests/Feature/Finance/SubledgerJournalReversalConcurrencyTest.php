<?php

namespace Tests\Feature\Finance;

use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Application\LedgerAdministrationService;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.8-P1 (ADR 0076 §57): committed, real PostgreSQL. Two OS processes race
 * one Charge's journal entry; the contender is positively observed BLOCKED
 * on the holder's uncommitted reversal (journal_entries_reversal_of_unique)
 * before the holder commits (ForcesConcurrentOverlap):
 *
 *   A  raw back-door reversal first -> it fails at its own COMMIT (the
 *      owner-link check), and the waiting canonical cancellation then wins
 *   B  canonical cancellation first -> the waiting raw reversal is the
 *      second reversal and is refused by the unique index
 *
 * Either way exactly one reversal exists and it is the Charge's own
 * cancellation entry. A last test proves the pre-P1 repair: an orphan
 * reversal written before P1 is itself ledger-native, so reversing it
 * restores the ledger to match the still-live Charge.
 */
class SubledgerJournalReversalConcurrencyTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private User $actor;

    private LedgerAccount $receivable;

    private Charge $charge;

    /** @var list<string> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->actor = $this->schoolAdminFor($this->school); // ORG.8G: a current finance.charges.manage member
        $this->userIds[] = $this->actor->id;
        $this->receivable = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $this->charge = $this->assessCharge($this->school, $this->createStudent($this->school), $this->createAcademicYear($this->school),
            $this->receivable, $this->createLedgerAccount($this->school, ['type' => 'income']), '250.00');
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    /** @return array<int, string> */
    private function op(string $operation, string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/subledger-reversal-op.php', $operation, $this->school->id, ...$args];
    }

    /** @return list<object> every reversal of the Charge's entry */
    private function reversalsOfTheCharge(): array
    {
        return $this->withinSchool($this->school, fn () => JournalEntry::query()->where('reversal_of_journal_entry_id', $this->charge->journal_entry_id)->get()->all());
    }

    private function withinSchool(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    #[Test]
    public function a_waiting_canonical_cancellation_wins_over_a_raw_reversal_that_cannot_commit(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('raw-reverse', $this->charge->journal_entry_id),
            $this->op('cancel-charge', $this->charge->id, $this->actor->id),
        );

        $this->assertSame('rejected:guard', $holder, 'the back door fails at its own COMMIT');
        $this->assertSame('ok', $contender);
        $charge = $this->withinSchool($this->school, fn () => Charge::query()->findOrFail($this->charge->id));
        $this->assertNotNull($charge->cancelled_at);
        $this->assertSame([$charge->cancellation_journal_entry_id], array_map(fn ($e) => $e->id, $this->reversalsOfTheCharge()));
    }

    #[Test]
    public function a_raw_reversal_waiting_on_a_canonical_cancellation_is_the_refused_second_reversal(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('cancel-charge', $this->charge->id, $this->actor->id),
            $this->op('raw-reverse', $this->charge->journal_entry_id),
        );

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:already-reversed', $contender);
        $charge = $this->withinSchool($this->school, fn () => Charge::query()->findOrFail($this->charge->id));
        $this->assertSame([$charge->cancellation_journal_entry_id], array_map(fn ($e) => $e->id, $this->reversalsOfTheCharge()));
    }

    #[Test]
    public function a_committed_raw_reversal_is_refused_at_commit_even_with_the_tenant_context_cleared(): void
    {
        $outcome = (new Process($this->op('raw-reverse', $this->charge->journal_entry_id)))->mustRun()->getOutput();

        $this->assertSame('rejected:guard', $outcome);
        $this->assertSame([], $this->reversalsOfTheCharge());

        $runtime = DB::connection('pgsql');
        try {
            $runtime->transaction(function () use ($runtime): void {
                $runtime->select("select set_config('app.current_school_id', ?, false)", [$this->school->id]);
                $runtime->table('journal_entries')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'currency' => 'INR',
                    'description' => 'raw reversal', 'reversal_of_journal_entry_id' => $this->charge->journal_entry_id, 'posted_at' => now()]);
                // A cleared context must not hide the Charge from the COMMIT-time check (fail closed).
                $runtime->select("select set_config('app.current_school_id', '', false)");
                $runtime->statement('SET CONSTRAINTS journal_entries_subledger_reversal_owner_check IMMEDIATE');
            });
            $this->fail('A raw reversal of a Charge entry must never commit.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('journal_entries_subledger_reversal_guard', $e->getMessage());
        } finally {
            $runtime->select("select set_config('app.current_school_id', '', false)");
        }
        $this->assertSame([], $this->reversalsOfTheCharge());
    }

    #[Test]
    public function a_pre_p1_orphan_reversal_is_repaired_by_reversing_it_generically(): void
    {
        // History written before P1: a generic reversal of the Charge's entry, never linked by the Charge.
        // Recreated through the schema owner with only the COMMIT-time owner check disabled for that write.
        $orphan = (string) Str::uuid7();
        $admin = DB::connection('pgsql_admin');
        $admin->transaction(function () use ($admin, $orphan): void {
            $admin->statement('ALTER TABLE journal_entries DISABLE TRIGGER journal_entries_subledger_reversal_owner_check');
            $admin->table('journal_entries')->insert(['id' => $orphan, 'school_id' => $this->school->id, 'currency' => 'INR',
                'description' => 'pre-P1 generic reversal', 'reversal_of_journal_entry_id' => $this->charge->journal_entry_id, 'posted_at' => now()]);
            foreach ($admin->table('journal_lines')->where('journal_entry_id', $this->charge->journal_entry_id)->get() as $line) {
                $admin->table('journal_lines')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'journal_entry_id' => $orphan,
                    'ledger_account_id' => $line->ledger_account_id, 'currency' => $line->currency,
                    'debit_amount' => $line->credit_amount, 'credit_amount' => $line->debit_amount, 'created_at' => now()]);
            }
            $admin->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $admin->statement('ALTER TABLE journal_entries ENABLE TRIGGER journal_entries_subledger_reversal_owner_check');
        });
        $this->assertSame('O', $admin->selectOne("select tgenabled from pg_trigger where tgname = 'journal_entries_subledger_reversal_owner_check'")->tgenabled);

        $reverser = $this->createUserWithCapabilities($this->school, ['finance.ledger.reverse']);
        $this->userIds[] = $reverser->id;
        $repair = app(LedgerAdministrationService::class)->reverse($this->school, $orphan, $reverser, 'repair a pre-P1 generic reversal');

        $receivable = $this->withinSchool($this->school, fn () => JournalLine::query()->where('ledger_account_id', $this->receivable->id)
            ->selectRaw('coalesce(sum(debit_amount), 0) - coalesce(sum(credit_amount), 0) as net')->value('net'));
        $this->assertSame('250.00', $receivable, 'the ledger again carries the still-live Charge');
        $this->assertNull($this->withinSchool($this->school, fn () => Charge::query()->findOrFail($this->charge->id)->cancelled_at));
        $this->assertSame($orphan, $this->withinSchool($this->school, fn () => JournalEntry::query()->findOrFail($repair->journalEntryId)->reversal_of_journal_entry_id));

        // The entry keeps its one historical reversal, so the canonical cancellation stays refused (ADR 0076 §57.9).
        $this->expectException(ChargeAlreadyCancelledException::class);
        $this->cancelChargeAs($this->school, $this->charge->id, $this->actor);
    }
}
