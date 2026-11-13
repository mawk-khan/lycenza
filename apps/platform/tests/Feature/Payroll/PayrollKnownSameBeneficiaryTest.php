<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\Payroll\Application\Exceptions\PostingAttestationRequiredException;
use App\Domain\Payroll\Application\Exceptions\SelfBeneficiaryConflictException;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationReadService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollPostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliationReview;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-17 (C34-N1, owner decision D-C35-1): OD-HR-24 refuses a Payroll decider who is the
 * Employee of a compensation change the run pays first. An account KNOWN to be the same person as
 * that Employee's account (rule 152) is now refused too -- approval, posting attestation and
 * reconciliation review, in the service and as the raw runtime role. The C34-N1 shape: account A
 * is linked to the paid Employee E; a duplicate Employee E' was linked to A and then to D, so A and
 * D are one person while D has no link to E. Unknown is never refused; a corrected link is no
 * evidence; a completed decision is never re-read. Synthetic accounts, every date in 2015.
 */
class PayrollKnownSameBeneficiaryTest extends TestCase
{
    use BuildsPayrollIndependenceScenarios, CreatesLinkedHrStaff, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    private function decider(array $more = []): User
    {
        return $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view', ...$more]);
    }

    /** A is linked to the paid Employee; D shares the duplicate Employee E' with A. Returns [A, D, E']. */
    private function aliasOfThePaidEmployee(array $more = []): array
    {
        $a = $this->decider($more);
        $d = $this->decider($more);
        $dup = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($a, $dup);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => null]));
        $this->link($a, $this->employee);
        $this->link($d, $dup);

        return [$a, $d, $dup];
    }

    private function refusedAs(string $exception, callable $write): void
    {
        try {
            $write();
            $this->fail("expected {$exception}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }
    }

    private function raw(callable $write): string
    {
        try {
            $this->inSchool($this->school, fn () => DB::transaction($write));

            return 'ok';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    private function augustPayingTheRaise(): PayrollRun
    {
        $this->raise('2015-08-01', '52000.00');

        return $this->calculatedRun('2015-08-01');
    }

    #[Test]
    public function c34_n1_an_account_known_to_be_the_paid_employee_never_approves_and_nothing_changes(): void
    {
        $this->buildWorld();
        [$a, $d] = $this->aliasOfThePaidEmployee();
        $run = $this->augustPayingTheRaise();
        $before = $this->runRow($run);

        $this->assertTrue($this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePerson($this->school->id, $a->id, $d->id)));
        $this->assertFalse($this->inSchool($this->school, fn () => EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->where('user_id', $d->id)->exists()), 'D has no link of its own to the paid Employee');

        $this->refusedAs(SelfBeneficiaryConflictException::class, fn () => $this->approve($run, $a));
        $this->refusedAs(SelfBeneficiaryConflictException::class, fn () => $this->approve($run, $d));
        $this->assertSame($before, $this->runRow($run), 'still calculated, no approver, no evidence');
        $this->assertSame([], $this->audit('payroll.run.approved', $run));

        $packet = app(PayrollApprovalReviewService::class)->packet($this->school, $run->id, $d);
        $this->assertContains('PAYROLL_SELF_BENEFICIARY_CONFLICT', array_column($packet['blocking'], 'code'), 'the packet says so before the decision');
        $this->assertStringNotContainsString('52000', json_encode($packet['blocking']), 'the reason names no amount');

        $this->assertSame('approved', $this->approve($run, $this->checker)->status, 'an independent approver still approves');
    }

    #[Test]
    public function a_replaced_account_of_the_paid_employee_stays_refused_as_before(): void
    {
        $this->buildWorld();
        $old = $this->decider();
        $new = $this->decider();
        $this->link($old, $this->employee);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($this->employee->id)->update(['user_id' => null]));
        $this->link($new, $this->employee);
        $run = $this->augustPayingTheRaise();

        $this->refusedAs(SelfBeneficiaryConflictException::class, fn () => $this->approve($run, $old));
        $this->refusedAs(SelfBeneficiaryConflictException::class, fn () => $this->approve($run, $new));
    }

    #[Test]
    public function a_corrected_link_is_no_evidence_and_unknown_is_never_refused(): void
    {
        $this->buildWorld();
        [$a, $d, $dup] = $this->aliasOfThePaidEmployee();
        $run = $this->augustPayingTheRaise();

        // An approved two-person correction voids A's old link to the duplicate: A and D are no longer known to be one person.
        $link = $this->inSchool($this->school, fn () => EmployeeAccountLink::query()->where('employee_id', $dup->id)->where('user_id', $a->id)->firstOrFail());
        $this->correctLink($this->inSchool($this->school, fn () => Employee::query()->findOrFail($dup->id)), $link->id);

        $this->assertSame([], $this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePersonAccounts($this->school->id, $d->id)), 'D is unknown now');
        $this->refusedAs(SelfBeneficiaryConflictException::class, fn () => $this->approve($run, $a));
        $this->assertSame('approved', $this->approve($run, $d)->status);
    }

    #[Test]
    public function the_runtime_role_cannot_record_an_approval_by_an_account_known_to_be_the_paid_employee(): void
    {
        $this->buildWorld();
        [, $d] = $this->aliasOfThePaidEmployee();
        $run = $this->augustPayingTheRaise();
        $latest = $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($run->fresh()));
        $approveAs = fn (User $as) => $this->raw(fn () => DB::update(
            "UPDATE payroll_runs SET status = 'approved', approved_by_user_id = ?, approved_at = now(), approved_calculation_id = ? WHERE id = ?",
            [$as->id, $latest, $run->id],
        ));

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        $this->assertStringContainsString('payroll_runs_self_beneficiary_guard: an approver is not the Employee of a change the run pays first', $approveAs($d));
        $this->assertSame('calculated', $this->runRow($run)['status'], 'nothing committed');
        $this->assertSame('ok', $approveAs($this->checker), 'the positive control');
    }

    #[Test]
    public function a_completed_approval_is_never_rewritten_when_a_later_link_makes_the_approver_the_paid_employee(): void
    {
        $this->buildWorld();
        $a = $this->decider();
        $d = $this->decider();
        $dup = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($a, $dup);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => null]));
        $this->link($a, $this->employee);
        $run = $this->augustPayingTheRaise();
        $this->approve($run, $d);
        $before = $this->runRow($run);

        $this->link($d, $dup);

        $this->assertSame($before, $this->runRow($run), 'decided on the history as it stood (C33: never retroactive)');

        // WO17-N1 Option A (D-WO18-1): as for the Employee's own account (rules 144, 145: "a link change alone can create
        // the exception"), the run now needs an independent attestation before posting; the approval itself is untouched.
        $this->refusedAs(PostingAttestationRequiredException::class, fn () => $this->postRun($run));
        $version = (string) $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($run->fresh()));
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $this->poster, $this->freshMfaProofFor($this->poster), null, new PostingAttestation($version, true)));
        $this->assertSame('posted', $this->runRow($run)['status']);
        $this->assertSame([$before['approved_by_user_id'], $before['approved_at']], [$this->runRow($run)['approved_by_user_id'], $this->runRow($run)['approved_at']]);
    }

    #[Test]
    public function another_schools_links_never_make_the_approver_the_paid_employee(): void
    {
        $this->buildWorld();
        $a = $this->decider();
        $d = $this->decider();
        $this->link($a, $this->employee);
        $other = $this->createSchool();
        $this->createMembership($a, $other);
        $this->createMembership($d, $other);
        $foreign = $this->createEmployee($other, ['user_id' => null]);
        foreach ([$a->id, null, $d->id] as $userId) {
            $this->inSchool($other, fn () => Employee::query()->whereKey($foreign->id)->update(['user_id' => $userId]));
        }
        $run = $this->augustPayingTheRaise();

        $this->assertSame('approved', $this->approve($run, $d)->status, 'A and D are joined only in another School');
    }

    /** X approves November while October, independently approved, stands; October is then reversed. */
    private function novemberApprovedByItsAuthorThenOctoberReversed(): PayrollRun
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->reverseRun($october);

        return $november;
    }

    private function evidence(PayrollRun $run): string
    {
        return (string) $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($run->fresh()));
    }

    #[Test]
    public function an_account_known_to_be_the_paid_employee_never_attests_in_the_service_or_the_database(): void
    {
        $this->buildWorld();
        [, $d] = $this->aliasOfThePaidEmployee(['payroll.runs.post']);
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $this->assertNotSame('', $this->evidence($november));

        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $d, true));
        $this->assertSame('self_beneficiary', $review['blockedReason']);
        $this->refusedAs(SelfBeneficiaryConflictException::class, fn () => $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($november->fresh(), $d, $this->freshMfaProofFor($d), null, new PostingAttestation($this->evidence($november), true))));
        $this->assertSame('approved', $this->runRow($november)['status']);

        $version = $this->evidence($november);
        $calculation = $this->runRow($november)['approved_calculation_id'];
        $journal = fn () => app(LedgerService::class)->post($this->school, new PostJournalEntryData('INR', 'WO-17 raw proof', [
            new JournalLineData($this->setup['expense']->id, JournalSide::Debit, Money::of('1.00', 'INR')),
            new JournalLineData($this->setup['payable']->id, JournalSide::Credit, Money::of('1.00', 'INR')),
        ]), $this->finance)->journalEntryId;
        $attestAs = fn (User $as) => $this->raw(function () use ($as, $november, $version, $calculation, $journal): void {
            $posting = (string) Str::uuid7();
            DB::insert("INSERT INTO payroll_run_postings (id, school_id, payroll_run_id, journal_entry_id, currency, posting_kind, actor_user_id) VALUES (?, ?, ?, ?, 'INR', 'original', ?)",
                [$posting, $this->school->id, $november->id, $journal(), $as->id]);
            DB::insert('INSERT INTO payroll_posting_attestations (id, school_id, payroll_run_id, payroll_run_calculation_id, payroll_run_posting_id, evidence_version, statement_code, attested_by_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, now())',
                [(string) Str::uuid7(), $this->school->id, $november->id, $calculation, $posting, $version, PayrollPostingAttestation::STATEMENT_INDEPENDENCE_CHANGED, $as->id]);
        });
        $this->assertStringContainsString('an attestor is not the Employee of a change the run pays first', $attestAs($d));
        $this->assertSame(0, $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $november->id)->count()));
        $this->assertSame('ok', $attestAs($this->poster), 'the positive control');
    }

    #[Test]
    public function an_account_known_to_be_the_paid_employee_never_reviews_the_obligation(): void
    {
        $this->buildWorld();
        [, $d] = $this->aliasOfThePaidEmployee();
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);
        $obligation = $this->obligation($november);

        $this->refusedAs(SelfBeneficiaryConflictException::class, fn () => $this->reviewAs($obligation, $d));
        $page = $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationReadService::class)->show($this->school, $d, $obligation->id, true));
        $this->assertSame('self_beneficiary', $page['blockedReason']);

        $insert = fn (User $as) => $this->raw(fn () => DB::insert(
            'INSERT INTO payroll_reversal_reconciliation_reviews (id, school_id, reconciliation_id, evidence_version, statement_code, unlinked_employee_count, reviewed_by_user_id) SELECT ?, ?, ?, ?, ?, (SELECT count(DISTINCT s.employee_id) FROM payroll_reversal_reconciliation_subjects(?, ?) s WHERE s.user_id IS NULL), ?',
            [(string) Str::uuid7(), $this->school->id, $obligation->id, $this->reviewVersion($obligation), PayrollReversalReconciliationReview::STATEMENT_REVERSAL_IMPACT_REVIEWED, $this->school->id, $obligation->id, $as->id],
        ));
        $this->assertStringContainsString('a reviewer is independent of the run, its reversal and its first-paid changes', $insert($d));
        $this->assertSame(0, $this->reviews());
        $this->reviewAs($obligation, $this->reviewer);
        $this->assertSame(1, $this->reviews(), 'an independent reviewer still closes it');
    }
}
