<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\Payroll\Application\Exceptions\KnownSamePersonDecisionException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationReviewerNotIndependentException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
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
 * HR.R1-C34 (WO-16; owner decision HR.R1-C33): an account known to be the same person as a
 * participant (two accounts with uncorrected links to one Employee, rule 145) never takes the
 * independent decision that person's own account could not -- Payroll approval, posting
 * attestation and reconciliation review -- in the service AND as the raw runtime role. Unknown
 * (no shared Employee) is never refused and never proof; a corrected link is no evidence; a
 * completed decision is never re-read. Synthetic accounts, every date in 2015.
 */
class PayrollKnownSamePersonTest extends TestCase
{
    use BuildsPayrollIndependenceScenarios, CreatesLinkedHrStaff, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    /** One person, two accounts: an Employee (never paid) linked to $first, unlinked, then linked to $second. */
    private function samePerson(User $first, User $second): Employee
    {
        $own = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($first, $own);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($own->id)->update(['user_id' => null]));
        $this->link($second, $own);

        return $own;
    }

    private function approver(): User
    {
        return $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
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

    /** As the runtime role, in the School's context, inside a savepoint: 'ok' or the database's refusal. */
    private function raw(callable $write): string
    {
        try {
            $this->inSchool($this->school, fn () => DB::transaction($write));

            return 'ok';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    #[Test]
    public function a_known_same_account_of_the_preparer_never_approves_and_nothing_changes(): void
    {
        $this->buildWorld();
        $alt = $this->approver();
        $this->samePerson($this->preparer, $alt);
        $run = $this->calculatedRun('2015-07-01');
        $before = $this->runRow($run);

        $this->assertTrue($this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePerson($this->school->id, $this->preparer->id, $alt->id)), 'the relation C33 decided on');
        $this->refusedAs(KnownSamePersonDecisionException::class, fn () => $this->approve($run, $alt));

        $this->assertSame($before, $this->runRow($run), 'still calculated, no approver, no evidence');
        $this->assertSame([], $this->audit('payroll.run.approved', $run));
        $decision = app(PayrollApprovalReviewService::class)->packet($this->school, $run->id, $alt);
        $this->assertContains('PAYROLL_SAME_PERSON_DECISION', array_column($decision['blocking'], 'code'), 'the packet says so before the decision');
        $this->assertStringNotContainsString($this->preparer->email, json_encode($decision['blocking']), 'the refusal names no account');

        // The account itself is still refused as before, and someone unknown to be the preparer approves.
        $this->refusedAs(SelfApprovalNotAllowedException::class, fn () => $this->approve($run, $this->preparer));
        $this->assertSame('approved', $this->approve($run, $this->checker)->status);
    }

    #[Test]
    public function a_known_same_account_of_a_compensation_author_never_approves_the_run_that_pays_it_first(): void
    {
        $this->buildWorld();
        $this->raise('2015-08-01', '52000.00');
        $alt = $this->approver();
        $this->samePerson($this->x, $alt);
        $run = $this->calculatedRun('2015-08-01');

        $this->assertContains($this->x->id, $this->inSchool($this->school, fn () => app(PayrollRunService::class)->participants($run->fresh())->all()['compensation_author'] ?? []));
        $this->refusedAs(KnownSamePersonDecisionException::class, fn () => $this->approve($run, $alt));
        $this->assertSame('calculated', $this->runRow($run)['status']);
        $this->assertSame('approved', $this->approve($run, $this->checker)->status);
    }

    #[Test]
    public function unknown_is_never_refused_and_a_corrected_link_is_no_evidence(): void
    {
        $this->buildWorld();
        $stranger = $this->approver();
        $alt = $this->approver();
        $own = $this->samePerson($this->preparer, $alt);
        $run = $this->calculatedRun('2015-07-01');

        // Unknown: different accounts with no shared Employee. Not refused, and not proof either (RG-ID-1).
        $this->assertSame([], $this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePersonAccounts($this->school->id, $stranger->id)));

        // An approved two-person correction voids the preparer's old link: the two accounts are no longer known to be one person.
        $link = $this->inSchool($this->school, fn () => EmployeeAccountLink::query()->where('employee_id', $own->id)->where('user_id', $this->preparer->id)->firstOrFail());
        $this->correctLink($this->inSchool($this->school, fn () => Employee::query()->findOrFail($own->id)), $link->id);
        $this->assertFalse($this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePerson($this->school->id, $this->preparer->id, $alt->id)));

        $this->assertSame('approved', $this->approve($run, $alt)->status);
    }

    #[Test]
    public function the_runtime_role_cannot_record_an_approval_by_a_known_same_account(): void
    {
        $this->buildWorld();
        $alt = $this->approver();
        $this->samePerson($this->preparer, $alt);
        $run = $this->calculatedRun('2015-07-01');
        $latest = $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($run->fresh()));
        $approveAs = fn (User $as) => $this->raw(fn () => DB::update(
            "UPDATE payroll_runs SET status = 'approved', approved_by_user_id = ?, approved_at = now(), approved_calculation_id = ? WHERE id = ?",
            [$as->id, $latest, $run->id],
        ));

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');
        $this->assertStringContainsString('approved by an account known to be the same person as one who prepared it', $approveAs($alt));
        $this->assertSame('calculated', $this->runRow($run)['status'], 'nothing committed');
        // A forged column naming someone else is a different decider, and is judged as that decider.
        $this->assertSame('ok', $approveAs($this->checker), 'the positive control: an independent approver passes every guard');
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

    private function postAs(PayrollRun $run, User $as): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $as, $this->freshMfaProofFor($as), null, new PostingAttestation($this->evidence($run), true)));
    }

    #[Test]
    public function a_known_same_account_of_the_approver_never_attests_and_nothing_reaches_finance(): void
    {
        $this->buildWorld();
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $alt = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $this->samePerson($this->x, $alt);
        $this->assertNotSame('', $this->evidence($november));

        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $alt, true));
        $this->assertSame('same_person', $review['blockedReason']);
        $this->refusedAs(KnownSamePersonDecisionException::class, fn () => $this->postAs($november, $alt));

        $this->assertSame('approved', $this->runRow($november)['status']);
        $this->assertSame(0, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $november->id)->count()), 'nothing reached Finance');
        $this->assertSame(0, $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $november->id)->count()));

        $this->postAs($november, $this->poster);
        $this->assertSame('posted', $this->runRow($november)['status'], 'an independent poster still attests');
    }

    #[Test]
    public function the_runtime_role_cannot_record_an_attestation_by_a_known_same_account(): void
    {
        $this->buildWorld();
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $alt = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $this->samePerson($this->x, $alt);
        $version = $this->evidence($november);
        $calculation = $this->runRow($november)['approved_calculation_id'];
        // A real journal entry for each raw posting row, so the only thing under test is the attestation guard.
        $journal = fn () => app(LedgerService::class)->post($this->school, new PostJournalEntryData('INR', 'C34 raw proof', [
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

        $this->assertStringContainsString('an attestor is not known to be the same person as the approver or a maker of the run', $attestAs($alt));
        $this->assertSame(0, $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $november->id)->count()));
        $this->assertSame('ok', $attestAs($this->poster), 'the positive control');
    }

    #[Test]
    public function a_known_same_account_of_the_reverser_never_reviews_the_obligation(): void
    {
        $this->buildWorld();
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);
        $obligation = $this->obligation($november);
        $alt = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $this->samePerson($this->finance, $alt);

        $this->refusedAs(ReconciliationReviewerNotIndependentException::class, fn () => $this->reviewAs($obligation, $this->finance));
        $this->refusedAs(KnownSamePersonDecisionException::class, fn () => $this->reviewAs($obligation, $alt));
        $this->assertSame(0, $this->reviews());
        $page = $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationReadService::class)->show($this->school, $alt, $obligation->id, true));
        $this->assertSame('same_person', $page['blockedReason']);

        // As the runtime role: the guard refuses the same row, and accepts the independent reviewer's.
        $insert = fn (User $as) => $this->raw(fn () => DB::insert(
            'INSERT INTO payroll_reversal_reconciliation_reviews (id, school_id, reconciliation_id, evidence_version, statement_code, unlinked_employee_count, reviewed_by_user_id) SELECT ?, ?, ?, ?, ?, (SELECT count(DISTINCT s.employee_id) FROM payroll_reversal_reconciliation_subjects(?, ?) s WHERE s.user_id IS NULL), ?',
            [(string) Str::uuid7(), $this->school->id, $obligation->id, $this->reviewVersion($obligation), PayrollReversalReconciliationReview::STATEMENT_REVERSAL_IMPACT_REVIEWED, $this->school->id, $obligation->id, $as->id],
        ));
        $this->assertStringContainsString('a reviewer is not known to be the same person as the approver, the reverser, a maker or an author', $insert($alt));
        $this->assertSame(0, $this->reviews());
        $this->reviewAs($obligation, $this->reviewer);
        $this->assertSame(1, $this->reviews(), 'an independent reviewer still closes it');
    }

    #[Test]
    public function a_completed_approval_is_never_reread_when_a_link_later_makes_two_accounts_one_person(): void
    {
        $this->buildWorld();
        $alt = $this->approver();
        $run = $this->calculatedRun('2015-07-01');
        $this->approve($run, $alt);
        $before = $this->runRow($run);
        $audits = $this->audit('payroll.run.approved', $run);

        $this->samePerson($this->preparer, $alt);

        $this->assertTrue($this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePerson($this->school->id, $this->preparer->id, $alt->id)));
        $this->assertSame($before, $this->runRow($run), 'the decision stands exactly as taken (C33: never retroactive)');
        $this->assertSame($audits, $this->audit('payroll.run.approved', $run));
        $this->postRun($run);
        $this->assertSame('posted', $this->runRow($run)['status'], 'and posts: the approval is not re-judged');
    }

    #[Test]
    public function another_schools_links_never_make_two_accounts_one_person(): void
    {
        $this->buildWorld();
        $alt = $this->approver();
        $run = $this->calculatedRun('2015-07-01');
        // The same two accounts, one Employee -- in ANOTHER School. Nothing here can read it, and nothing here refuses because of it.
        $other = $this->createSchool();
        $this->createMembership($this->preparer, $other);
        $this->createMembership($alt, $other);
        $foreign = $this->createEmployee($other, ['user_id' => null]);
        $this->inSchool($other, fn () => Employee::query()->whereKey($foreign->id)->update(['user_id' => $this->preparer->id]));
        $this->inSchool($other, fn () => Employee::query()->whereKey($foreign->id)->update(['user_id' => null]));
        $this->inSchool($other, fn () => Employee::query()->whereKey($foreign->id)->update(['user_id' => $alt->id]));

        $this->assertSame([], $this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePersonAccounts($this->school->id, $alt->id)));
        $this->assertSame([], $this->inSchool($this->school, fn () => app(HrSelfAdministrationGuard::class)->knownSamePersonAccounts($other->id, $alt->id)), 'RLS: another School\'s links are invisible in this School\'s context');
        $this->assertSame('approved', $this->approve($run, $alt)->status);
    }
}
