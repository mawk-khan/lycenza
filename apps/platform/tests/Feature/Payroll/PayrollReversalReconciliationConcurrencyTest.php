<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Application\LedgerService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationReadService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C6, on COMMITTED data, in genuinely separate OS processes with the holder
 * held open and the contender observed blocked on a lock. A reversal holds the
 * compensation lock exclusively; posting, approval and review hold it shared; a
 * link change exclusively. So each is ordered wholly before or after the other, and
 * the deferred check at commit sees the truth. A functional test's outer transaction
 * would never reach that deferred check, which is why these are here.
 */
class PayrollReversalReconciliationConcurrencyTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap, ProvidesSensitiveActionMfa;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    /** @var array<string, mixed> */
    private array $setup;

    private Employee $employee;

    private User $preparer;

    private User $checker;

    private User $x;

    private User $finance;

    private User $reviewer;

    private User $second;

    private PayrollRun $october;

    private PayrollRun $november;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            try {
                $this->purgeSchoolsAsAdmin([$this->school->id]);
            } catch (\Throwable) {
                // Frozen results make the School hard to delete; residue is reset between sessions.
            }
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $this->employee = $this->paidEmployee($this->school, $this->setup);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $this->checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->reviewer = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $this->second = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);

        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $this->employee->id)->firstOrFail(), $this->setup['structure'], Carbon::parse('2026-10-01'),
            [new FixedComponentValueInput($this->setup['line'], '55000.00')], $this->x, $this->freshMfaProofFor($this->x),
        ));
        $this->october = $this->calculatedRun('2026-10-01');
        $this->approve($this->october, $this->checker);
        $this->postRun($this->october);
        $this->november = $this->calculatedRun('2026-11-01');
        $this->approve($this->november, $this->x);
    }

    private function calculatedRun(string $month): PayrollRun
    {
        return $this->inSchool($this->school, function () use ($month): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $this->preparer), $this->preparer);
            $run = app(PayrollRunService::class)->createRun($period, $this->preparer);
            app(PayrollRunService::class)->calculate($run, $this->preparer);

            return $run->fresh();
        });
    }

    private function approve(PayrollRun $run, User $as): void
    {
        $this->inSchool($this->school, function () use ($run, $as): void {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();
            $runs->approve($fresh, $as, $this->freshMfaProofFor($as), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
        });
    }

    private function postRun(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance)));
    }

    private function reverse(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance), 'test'));
    }

    /** @return list<string> */
    private function reverseCommand(PayrollRun $run): array
    {
        return ['php', __DIR__.'/../../Support/reverse-payroll-run.php', $this->school->id, $run->id, $this->finance->id];
    }

    /** @return list<string> */
    private function postCommand(PayrollRun $run): array
    {
        return ['php', __DIR__.'/../../Support/post-payroll-run.php', $this->school->id, $run->id, $this->finance->id];
    }

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', $args[0], $this->school->id, ...array_slice($args, 1)];
    }

    private function obligations(): int
    {
        return $this->inSchool($this->school, fn () => PayrollReversalReconciliation::query()->where('payroll_run_id', $this->november->id)->count());
    }

    private function reviews(): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_reversal_reconciliation_reviews')->count());
    }

    private function obligation(): PayrollReversalReconciliation
    {
        return $this->inSchool($this->school, fn () => PayrollReversalReconciliation::query()->where('payroll_run_id', $this->november->id)->firstOrFail());
    }

    private function version(PayrollReversalReconciliation $o): string
    {
        return $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationService::class)->version($o));
    }

    private function postedThenReversed(): PayrollReversalReconciliation
    {
        $this->postRun($this->november);
        $this->reverse($this->october);

        return $this->obligation();
    }

    #[Test]
    public function a_posting_held_open_is_ordered_first_and_the_waiting_reversal_captures_the_obligation(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->postCommand($this->november), $this->reverseCommand($this->october));

        $this->assertStringStartsWith('posted:', $holder);
        $this->assertStringStartsWith('reversed:', $contender, 'the reversal is never blocked');
        $this->assertSame(1, $this->obligations(), 'it saw the posted run');
    }

    #[Test]
    public function a_reversal_held_open_is_ordered_first_and_the_waiting_posting_meets_the_attestation_instead(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand($this->october), $this->postCommand($this->november));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\PostingAttestationRequiredException', $contender);
        $this->assertSame(0, $this->obligations(), 'the run never posted, so there is nothing to reconcile');
    }

    #[Test]
    public function two_reversals_of_one_run_leave_one_reversal_and_one_obligation(): void
    {
        $this->postRun($this->november);

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand($this->october), $this->reverseCommand($this->october));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(1, $this->obligations());
    }

    #[Test]
    public function two_reviewers_leave_exactly_one_review(): void
    {
        $o = $this->postedThenReversed();
        $version = $this->version($o);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('review', $o->id, $this->reviewer->id, $version), $this->op('review', $o->id, $this->second->id, $version));

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\ReconciliationAlreadyReviewedException', $contender);
        $this->assertSame(1, $this->reviews());
    }

    #[Test]
    public function a_reversal_held_open_makes_a_waiting_review_stale(): void
    {
        $o = $this->postedThenReversed();
        $version = $this->version($o);
        // December: the independent approval covers the change again, and reversing it takes that away.
        $december = $this->calculatedRun('2026-12-01');
        $this->approve($december, $this->checker);
        $this->postRun($december);
        $version = $this->version($o);

        [$holder, $contender] = $this->raceWithHeldHolder($this->reverseCommand($december), $this->op('review', $o->id, $this->reviewer->id, $version));

        $this->assertStringStartsWith('reversed:', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\ReconciliationEvidenceStaleException', $contender, 'the evidence changed while it waited');
        $this->assertSame(0, $this->reviews());
    }

    #[Test]
    public function a_link_held_open_makes_a_waiting_review_see_the_conflict(): void
    {
        $o = $this->postedThenReversed();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('link', $this->employee->id, $this->reviewer->id), $this->op('review', $o->id, $this->reviewer->id, $this->version($o)));

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\SelfBeneficiaryConflictException', $contender);
        $this->assertSame(0, $this->reviews());
    }

    #[Test]
    public function a_review_held_open_is_ordered_before_a_waiting_link(): void
    {
        $o = $this->postedThenReversed();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('review', $o->id, $this->reviewer->id, $this->version($o)), $this->op('link', $this->employee->id, $this->reviewer->id));

        $this->assertSame(['ok', 'ok'], [$holder, $contender], 'the link waited for the decision that read it');
        $this->assertSame(1, $this->reviews());
    }

    private function decemberPayingANewChange(): PayrollRun
    {
        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $this->employee->id)->firstOrFail(), $this->setup['structure'], Carbon::parse('2026-12-01'),
            [new FixedComponentValueInput($this->setup['line'], '60000.00')], $this->x, $this->freshMfaProofFor($this->x),
        ));

        return $this->calculatedRun('2026-12-01');
    }

    /** @return list<string> */
    private function approveCommand(PayrollRun $run, User $as): array
    {
        return ['php', __DIR__.'/../../Support/approve-payroll-run.php', $this->school->id, $run->id, $as->id];
    }

    #[Test]
    public function a_link_held_open_makes_a_waiting_approval_see_the_conflict(): void
    {
        $december = $this->decemberPayingANewChange();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('link', $this->employee->id, $this->reviewer->id), $this->approveCommand($december, $this->reviewer));

        $this->assertSame('ok', $holder);
        $this->assertSame('rejected:App\\Domain\\Payroll\\Application\\Exceptions\\SelfBeneficiaryConflictException', $contender, 'it waited for the link, then saw it');
        $this->assertSame('calculated', $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $december->id)->value('status')));
    }

    #[Test]
    public function an_approval_held_open_is_ordered_before_a_waiting_unlink(): void
    {
        $december = $this->decemberPayingANewChange();

        [$holder, $contender] = $this->raceWithHeldHolder($this->approveCommand($december, $this->reviewer), $this->op('link', $this->employee->id, $this->reviewer->id));

        $this->assertSame(['approved', 'ok'], [$holder, $contender], 'the link waited for the decision that read it');
    }

    #[Test]
    public function a_reversal_cannot_commit_while_it_leaves_an_affected_posted_run_with_no_obligation(): void
    {
        $this->postRun($this->november);

        try {
            // The real ledger and the real runtime role, skipping only the obligation. Nothing raises until the commit.
            $this->inSchool($this->school, fn () => DB::transaction(function (): void {
                $original = DB::table('payroll_run_postings')->where('payroll_run_id', $this->october->id)->where('posting_kind', 'original')->first();
                $entry = app(LedgerService::class)->reverseById($this->school, $original->journal_entry_id, $this->finance, 'test');
                DB::table('payroll_run_postings')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_run_id' => $this->october->id, 'journal_entry_id' => $entry->journalEntryId,
                    'currency' => 'INR', 'posting_kind' => 'reversal', 'reversal_of_payroll_run_posting_id' => $original->id, 'actor_user_id' => $this->finance->id,
                ]);
                $this->assertSame(1, DB::table('payroll_run_postings')->where('payroll_run_id', $this->october->id)->where('posting_kind', 'reversal')->count(), 'it reached the commit');
            }));
            $this->fail('a reversal committed with no obligation');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('payroll_run_postings_reconciliation_required', $e->getMessage());
        }

        $this->assertSame(0, DB::connection('pgsql_admin')->table('payroll_run_postings')->where('payroll_run_id', $this->october->id)->where('posting_kind', 'reversal')->count(), 'nothing of it committed');
    }

    #[Test]
    public function a_commit_that_cannot_see_its_reversal_is_refused_never_passed(): void
    {
        $this->postRun($this->november);

        try {
            $this->inSchool($this->school, fn () => DB::transaction(function (): void {
                app(PayrollPostingService::class)->reverse($this->october->fresh(), $this->finance, $this->freshMfaProofFor($this->finance), 'test');
                // The obligation is in place, so only the lost context can refuse this. Fired alone, because Finance's
                // own deferred balance check refuses a blind commit too, which would hide this one.
                DB::select("SELECT set_config('app.current_school_id', '', true)");
                DB::statement('SET CONSTRAINTS trg_payroll_run_postings_reconciliation_required IMMEDIATE');
            }));
            $this->fail('a check that could see no row passed');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('this reversal cannot be checked without its School context', $e->getMessage());
        }
    }

    #[Test]
    public function the_reversed_runs_retention_never_takes_the_affected_runs_open_obligation(): void
    {
        $o = $this->postedThenReversed();
        $admin = DB::connection('pgsql_admin');

        $admin->transaction(function () use ($admin, $o): void {
            // Retention's own session guard is what a real purge satisfies; this asserts the key, not that guard.
            $admin->statement('ALTER TABLE payroll_run_postings DISABLE TRIGGER trg_retention_guard_payroll_run_postings');
            $admin->table('payroll_run_postings')->where('id', $o->reversal_posting_id)->delete();
            $admin->statement('ALTER TABLE payroll_run_postings ENABLE TRIGGER trg_retention_guard_payroll_run_postings');
        });

        $after = $this->inSchool($this->school, fn () => PayrollReversalReconciliation::query()->whereKey($o->id)->firstOrFail());
        $this->assertNull($after->reversal_posting_id, 'only the link goes');
        $this->assertSame($o->school_id, $after->school_id, 'rule 97: never school_id');
        $state = $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationReadService::class)->show($this->school, $this->reviewer, $o->id, true));
        $this->assertSame('open', $state['state']);
        $this->assertSame($this->october->id, $state['reversedRun']['id'], 'the facts stay');
        $this->assertSame(1, $state['details']['changes']['total']);
        $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationService::class)->review($this->school, $o->id, $this->reviewer, new FreshMfaProof($this->reviewer->id, CarbonImmutable::now()), $this->version($after), true));
        $this->assertSame(1, $this->reviews(), 'and it can still be closed, and only by a review');
    }

    #[Test]
    public function a_reversed_run_with_no_recorded_provenance_is_reported_unknown_never_as_nothing_affected(): void
    {
        $this->postRun($this->november);
        DB::connection('pgsql_admin')->transaction(function ($admin): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $admin->table('payroll_run_calculation_sources')->where('payroll_run_id', $this->october->id)->delete();
        });

        $this->reverse($this->october);

        $this->assertSame(0, $this->obligations());
        $this->assertSame(1, $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'payroll.run.reversal_impact_unknown')->where('subject_id', $this->october->id)->count()));
    }
}
