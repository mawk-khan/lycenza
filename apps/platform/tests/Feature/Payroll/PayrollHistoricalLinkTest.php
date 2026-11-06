<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkVoidReason;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\PayrollSelfAdministrationException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestationRequiredException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\Exceptions\SelfBeneficiaryConflictException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PayrollSelfBeneficiaries;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C7 (OD-HR-25): an unlink does not change who a person is. A link not corrected
 * as erroneous counts wherever a link counted, at any date, for a run paying THAT
 * Employee's change first and for nothing else. Reproduced before the fix: with only
 * `employees.user_id`, each of these decisions passed once the link was removed.
 * Final state is asserted in the database, not only the refusal.
 */
class PayrollHistoricalLinkTest extends TestCase
{
    use BuildsPayrollIndependenceScenarios, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.manage']);
    }

    /** Through the real service, as HR does it. */
    private function linkAndUnlink(User $user, ?Employee $employee = null): void
    {
        $employee ??= $this->employee;
        $this->inSchool($this->school, function () use ($user, $employee): void {
            app(EmployeeService::class)->linkUser($employee->fresh(), $user->id, $this->hr);
            app(EmployeeService::class)->unlinkUser($employee->fresh(), $this->hr);
        });
    }

    private function unlink(?Employee $employee = null): void
    {
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->unlinkUser(($employee ?? $this->employee)->fresh(), $this->hr));
    }

    private function member(User $user): void
    {
        if (! DB::table('school_memberships')->where('school_id', $this->school->id)->where('user_id', $user->id)->exists()) {
            $this->createMembership($user, $this->school);
        }
    }

    private function correctLinkOf(User $user, ?Employee $employee = null): void
    {
        $employee ??= $this->employee;
        $this->inSchool($this->school, function () use ($user, $employee): void {
            $link = EmployeeAccountLink::query()->where('employee_id', $employee->id)->where('user_id', $user->id)->firstOrFail();
            app(EmployeeAccountLinkCorrectionService::class)->correct($employee->fresh(), $link->id, EmployeeAccountLinkVoidReason::WrongPerson, $this->hr, new FreshMfaProof($this->hr->id, CarbonImmutable::now()));
        });
    }

    private function assertApprovalRefused(PayrollRun $run, User $as): void
    {
        try {
            $this->approve($run, $as);
            $this->fail('approved a change to their own pay');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame('calculated', $this->runRow($run)['status']);
            $this->assertNull($this->runRow($run)['approved_by_user_id']);
        }
    }

    private function awaitingAttestation(): PayrollRun
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

    private function attest(PayrollRun $run, User $as): void
    {
        $this->inSchool($this->school, function () use ($run, $as): void {
            $version = (string) app(PayrollPostingIndependence::class)->version($run->fresh());
            app(PayrollPostingService::class)->post($run->fresh(), $as, $this->freshMfaProofFor($as), null, new PostingAttestation($version, true));
        });
    }

    private function attestations(PayrollRun $run): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $run->id)->count());
    }

    private function calculation(PayrollRun $run): string
    {
        return $this->inSchool($this->school, fn () => (string) app(PayrollRunService::class)->latestCalculationId($run));
    }

    /** @return array{conflicted: bool, unlinked: int, corrected: int} */
    private function finding(PayrollRun $run, User $user): array
    {
        $f = $this->inSchool($this->school, fn () => app(PayrollSelfBeneficiaries::class)->of($run, $this->calculation($run), $user->id));

        return ['conflicted' => $f->conflicted, 'unlinked' => $f->unlinkedEmployees, 'corrected' => $f->correctedEmployees];
    }

    // 1, 2, 9, 18.

    #[Test]
    public function an_unlink_before_approval_never_clears_the_conflict(): void
    {
        $this->member($this->reviewer);
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $this->reviewer->id, $this->hr));
        $october = $this->calculatedRun('2015-10-01');
        $this->assertApprovalRefused($october, $this->reviewer);

        $this->unlink();

        $this->assertNull($this->inSchool($this->school, fn () => $this->employee->fresh()->user_id), 'the unlink itself is legitimate');
        $this->assertApprovalRefused($october, $this->reviewer);
        $this->assertSame(['conflicted' => true, 'unlinked' => 0, 'corrected' => 0], $this->finding($october, $this->reviewer), 'known, not unknown');

        $this->approve($october, $this->checker);
        $this->assertSame($this->checker->id, $this->runRow($october)['approved_by_user_id'], 'an independent checker proceeds');
    }

    // 3.

    #[Test]
    public function a_relink_to_another_account_leaves_both_people_excluded(): void
    {
        $this->member($this->reviewer);
        $this->member($this->poster);
        $this->linkAndUnlink($this->reviewer);
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $this->poster->id, $this->hr));
        $october = $this->calculatedRun('2015-10-01');

        $this->assertApprovalRefused($october, $this->reviewer);
        $this->assertTrue($this->finding($october, $this->poster)['conflicted']);
        $this->assertFalse($this->finding($october, $this->checker)['conflicted']);
    }

    // 4.

    #[Test]
    public function an_unlink_after_approval_changes_nothing_about_that_approval(): void
    {
        $this->member($this->reviewer);
        $this->link($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $before = $this->runRow($october);
        $audit = $this->audit('payroll.run.approved', $october);

        $this->unlink();

        $this->assertSame($before, $this->runRow($october));
        $this->assertSame($audit, $this->audit('payroll.run.approved', $october));
    }

    // 5.

    #[Test]
    public function a_link_before_posting_still_needs_the_attestation(): void
    {
        $this->member($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->reviewer);
        $this->linkAndUnlink($this->reviewer);

        try {
            $this->postRun($october);
            $this->fail('posted without an attestation');
        } catch (PostingAttestationRequiredException) {
            $this->assertSame('approved', $this->runRow($october)['status']);
        }
    }

    // 6.

    #[Test]
    public function an_unlink_before_the_attestation_never_clears_the_conflict(): void
    {
        $november = $this->awaitingAttestation();
        $this->member($this->poster);
        $this->linkAndUnlink($this->poster);

        try {
            $this->attest($november, $this->poster);
            $this->fail('attested to a change to their own pay');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame('approved', $this->runRow($november)['status']);
            $this->assertSame(0, $this->attestations($november));
        }
        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->poster, true));
        $this->assertSame('self_beneficiary', $review['blockedReason']);
    }

    // 7, 22.

    #[Test]
    public function an_unlink_before_the_reconciliation_review_never_clears_the_conflict(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->member($this->reviewer);
        $this->link($this->reviewer);
        $this->reverseRun($october);
        $o = $this->obligation($november);
        $this->unlink();

        try {
            $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer));
            $this->fail('reviewed a change to their own pay');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame(0, $this->reviews());
        }
        $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->checker));
        $this->assertSame(1, $this->reviews(), 'an independent reviewer closes it');
    }

    #[Test]
    public function a_link_made_and_removed_after_the_reversal_excludes_the_reviewer_too(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);
        $o = $this->obligation($november);
        $this->member($this->reviewer);
        $this->linkAndUnlink($this->reviewer);

        $this->expectException(SelfBeneficiaryConflictException::class);
        $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer));
    }

    // Database agreement.

    #[Test]
    public function the_database_refuses_the_decision_whichever_code_writes_after_an_unlink(): void
    {
        $this->member($this->reviewer);
        $this->linkAndUnlink($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $calculation = $this->calculation($october);

        try {
            DB::transaction(fn () => $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $october->id)
                ->update(['status' => 'approved', 'approved_by_user_id' => $this->reviewer->id, 'approved_at' => now(), 'approved_calculation_id' => $calculation])));
            $this->fail('the runtime role approved it');
        } catch (QueryException $e) {
            $this->assertStringContainsString('an approver is not the Employee of a change the run pays first', (string) $e->getPrevious()?->getMessage());
        }
        $this->assertSame('calculated', $this->runRow($october)['status']);

        $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $october->id)
            ->update(['status' => 'approved', 'approved_by_user_id' => $this->checker->id, 'approved_at' => now(), 'approved_calculation_id' => $calculation]));
        $this->assertSame('approved', $this->runRow($october)['status'], 'positive control');
    }

    // 8, 24.

    #[Test]
    public function an_employee_never_linked_stays_unknown_and_is_recorded_as_unknown(): void
    {
        $october = $this->calculatedRun('2015-10-01');

        $this->assertSame(['conflicted' => false, 'unlinked' => 1, 'corrected' => 0], $this->finding($october, $this->checker));
        $packet = $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $october->id, $this->checker));
        $this->assertContains('PAYROLL_EMPLOYEES_WITHOUT_ACCOUNT', array_column($packet['warnings'], 'code'));

        $this->approve($october, $this->checker);
        $audit = $this->audit('payroll.run.approved', $october)[0];
        $this->assertSame(1, $audit['employeesWithoutAccount'], 'never presented as proof of no conflict');
        $this->assertSame(0, $audit['employeesWithCorrectedLink']);
    }

    // 10.

    #[Test]
    public function a_link_corrected_as_erroneous_stops_counting_and_the_employee_is_unknown_again(): void
    {
        $this->member($this->reviewer);
        $this->linkAndUnlink($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $this->assertApprovalRefused($october, $this->reviewer);

        $this->correctLinkOf($this->reviewer);

        $this->assertSame(['conflicted' => false, 'unlinked' => 1, 'corrected' => 1], $this->finding($october, $this->reviewer));
        $packet = $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $october->id, $this->reviewer));
        $this->assertContains('PAYROLL_EMPLOYEES_WITHOUT_ACCOUNT', array_column($packet['warnings'], 'code'), 'a correction says who they are not, never who they are');

        $this->approve($october, $this->reviewer);
        $audit = $this->audit('payroll.run.approved', $october)[0];
        $this->assertSame(1, $audit['employeesWithoutAccount']);
        $this->assertSame(1, $audit['employeesWithCorrectedLink'], 'the decision says what it relied on');
    }

    #[Test]
    public function correcting_one_link_leaves_the_true_one_counting(): void
    {
        $this->member($this->reviewer);
        $this->member($this->poster);
        $this->linkAndUnlink($this->reviewer);
        $this->linkAndUnlink($this->poster);
        $this->correctLinkOf($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');

        $this->assertFalse($this->finding($october, $this->reviewer)['conflicted']);
        $this->assertTrue($this->finding($october, $this->poster)['conflicted']);
    }

    #[Test]
    public function a_correction_changes_no_snapshot_and_frees_someone_it_should_never_have_excluded(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->member($this->reviewer);
        $this->link($this->reviewer);
        $this->reverseRun($october);
        $o = $this->obligation($november);
        $snapshot = [$o->first_paid_assignments, $o->implicated_assignments, $o->evidence_version];

        $this->correctLinkOf($this->reviewer);

        $fresh = $this->obligation($november);
        $this->assertSame($snapshot, [$fresh->first_paid_assignments, $fresh->implicated_assignments, $fresh->evidence_version]);
        $this->inSchool($this->school, fn () => $this->reviewAs($fresh, $this->reviewer));
        $this->assertSame(1, $this->reviews(), 'the link was never true, so they were never the Employee');
    }

    #[Test]
    public function a_decision_records_that_its_decider_had_a_corrected_link(): void
    {
        $this->member($this->reviewer);
        $this->member($this->poster);
        $this->linkAndUnlink($this->reviewer);
        $this->correctLinkOf($this->reviewer);
        $this->link($this->poster);
        $october = $this->calculatedRun('2015-10-01');

        $this->approve($october, $this->reviewer);

        $audit = $this->audit('payroll.run.approved', $october)[0];
        $this->assertSame(0, $audit['employeesWithoutAccount'], 'the Employee is known, through the true link');
        $this->assertSame(1, $audit['employeesWithCorrectedLink']);
        $this->assertTrue($audit['deciderHadCorrectedLink'], 'a correction that cleared the way is visible on the decision it cleared');
    }

    #[Test]
    public function nobody_records_their_own_pay_after_an_unlink(): void
    {
        $this->member($this->x);
        $this->linkAndUnlink($this->x);

        try {
            $this->inSchool($this->school, fn () => app(PayrollCompensationAdministrationService::class)->assign(
                $this->school, $this->record->fresh(), $this->setup['structure'], Carbon::parse('2015-10-01'),
                [new FixedComponentValueInput($this->setup['line'], '90000.00')], $this->x, $this->freshMfaProofFor($this->x),
            ));
            $this->fail('recorded a raise of their own');
        } catch (PayrollSelfAdministrationException) {
            $this->assertSame(1, $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $this->record->id)->count()));
        }

        $this->correctLinkOf($this->x);
        $this->inSchool($this->school, fn () => app(PayrollCompensationAdministrationService::class)->assign(
            $this->school, $this->record->fresh(), $this->setup['structure'], Carbon::parse('2015-10-01'),
            [new FixedComponentValueInput($this->setup['line'], '90000.00')], $this->x, $this->freshMfaProofFor($this->x),
        ));
        $this->assertSame(2, $this->inSchool($this->school, fn () => DB::table('employee_compensation_assignments')->where('employment_record_id', $this->record->id)->count()), 'positive control: a link that was never true');
    }

    // 11.

    #[Test]
    public function a_link_made_after_posting_rewrites_no_decision_and_opens_nothing(): void
    {
        $this->member($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->reviewer);
        $this->postRun($october);
        $row = $this->runRow($october);

        $this->link($this->reviewer);

        $this->assertSame($row, $this->runRow($october), 'the approval and who made it stand');
        $this->assertSame(0, $this->obligations(), 'a notice, not a new obligation (OD-HR-25)');
    }

    #[Test]
    public function an_unlink_never_restores_a_discharge_a_link_removed(): void
    {
        $this->member($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->reviewer);
        $this->postRun($october);
        $this->linkAndUnlink($this->reviewer);

        $november = $this->calculatedRun('2015-11-01');

        $this->assertTrue($this->finding($november, $this->reviewer)['conflicted'], 'their own approval discharged nothing, linked or not');
        $this->assertFalse($this->finding($november, $this->checker)['conflicted']);
    }

    // 12: the scope of the rule. Overblocking is a defect too.

    #[Test]
    public function receiving_a_salary_is_still_no_conflict_after_an_unlink(): void
    {
        $this->travelTo(Carbon::parse('2015-08-28 06:00:00'));
        $september = $this->calculatedRun('2015-09-01');
        $this->approve($september, $this->checker);
        $this->postRun($september);
        $this->member($this->reviewer);
        $this->linkAndUnlink($this->reviewer);

        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->reviewer);

        $this->assertSame('approved', $this->runRow($october)['status'], 'a run that pays nothing new for that Employee');
    }

    #[Test]
    public function an_old_link_blocks_only_that_employees_changes_never_payroll_generally(): void
    {
        $unrelated = $this->paidEmployee($this->school, $this->setup);
        $this->member($this->reviewer);
        $this->linkAndUnlink($this->reviewer, $unrelated);
        $september = $this->calculatedRun('2015-09-01');
        $this->assertTrue($this->finding($september, $this->reviewer)['conflicted'], 'control: the first pay of that Employee is theirs');
        $this->approve($september, $this->checker);
        $this->postRun($september);

        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');

        $this->assertFalse($this->finding($october, $this->reviewer)['conflicted'], 'another Employee\'s change');
        $this->approve($october, $this->reviewer);
        $this->assertSame($this->reviewer->id, $this->runRow($october)['approved_by_user_id']);
    }

    // 13-16.

    /** @return array<string, array{0: string}> */
    public static function amounts(): array
    {
        return ['increased' => ['60000.00'], 'decreased' => ['1000.00'], 'same amount' => ['50000.00']];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function an_unlinked_employee_stays_excluded_whatever_the_change_did_to_the_amount(string $amount): void
    {
        $this->travelTo(Carbon::parse('2015-08-28 06:00:00'));
        $september = $this->calculatedRun('2015-09-01');
        $this->approve($september, $this->checker);
        $this->postRun($september);
        $this->member($this->reviewer);
        $this->linkAndUnlink($this->reviewer);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', $amount);

        $this->assertApprovalRefused($this->calculatedRun('2015-10-01'), $this->reviewer);
    }

    // 13, 20.

    #[Test]
    public function the_first_payroll_of_a_school_still_needs_an_approver_it_does_not_pay_after_an_unlink(): void
    {
        $this->member($this->reviewer);
        $this->linkAndUnlink($this->reviewer);
        $first = $this->calculatedRun('2015-08-01');

        $this->assertApprovalRefused($first, $this->reviewer);
        $this->approve($first, $this->checker);
        $this->assertSame('approved', $this->runRow($first)['status'], 'someone outside it');
    }

    // 17.

    #[Test]
    public function an_author_stays_a_maker_whether_or_not_they_were_ever_linked(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $this->member($this->x);
        $this->linkAndUnlink($this->x);
        $october = $this->calculatedRun('2015-10-01');

        try {
            $this->approve($october, $this->x);
            $this->fail('a maker approved');
        } catch (SelfApprovalNotAllowedException|SelfBeneficiaryConflictException) {
            $this->assertSame('calculated', $this->runRow($october)['status']);
        }
    }

    // 19.

    #[Test]
    public function every_payroll_and_hr_key_held_at_once_changes_nothing(): void
    {
        $everything = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.compensation.sensitive.view', 'payroll.compensation.sensitive.manage', 'payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view', 'payroll.runs.prepare', 'hr.employees.manage']);
        $this->linkAndUnlink($everything);

        $this->assertApprovalRefused($this->calculatedRun('2015-10-01'), $everything);
    }

    #[Test]
    public function hr_link_authority_gives_no_payroll_authority(): void
    {
        $october = $this->calculatedRun('2015-10-01');

        try {
            $this->inSchool($this->school, function () use ($october): void {
                $runs = app(PayrollRunService::class);
                $fresh = $october->fresh();
                app(PayrollRunAdministrationService::class)->approve($fresh, $this->hr, $this->freshMfaProofFor($this->hr), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
            });
            $this->fail('HR approved Payroll');
        } catch (AuthorizationException) {
            $this->assertSame('calculated', $this->runRow($october)['status']);
        }
    }

    // 21.

    #[Test]
    public function a_school_wide_revision_needs_someone_it_does_not_pay_after_any_unlink(): void
    {
        $second = $this->paidEmployee($this->school, $this->setup);
        $secondRecord = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $second->id)->firstOrFail());
        $september = $this->calculatedRun('2015-09-01');
        $this->approve($september, $this->checker);
        $this->postRun($september);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $this->raise('2015-10-01', '56000.00', record: $secondRecord);
        $this->member($this->reviewer);
        $this->member($this->poster);
        $this->linkAndUnlink($this->reviewer);
        $this->linkAndUnlink($this->poster, $second);
        $october = $this->calculatedRun('2015-10-01');

        $this->assertApprovalRefused($october, $this->reviewer);
        $this->assertApprovalRefused($october, $this->poster);
        $this->approve($october, $this->checker);
        $this->assertSame('approved', $this->runRow($october)['status']);
    }

    // 23.

    #[Test]
    public function every_affected_run_keeps_the_exclusion_after_an_unlink(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->x);
        $this->postRun($december);
        $this->member($this->reviewer);
        $this->link($this->reviewer);
        $this->reverseRun($october);
        $this->unlink();

        $this->assertSame(2, $this->obligations());
        foreach ([$november, $december] as $run) {
            try {
                $this->inSchool($this->school, fn () => $this->reviewAs($this->obligation($run), $this->reviewer));
                $this->fail('reviewed a change to their own pay');
            } catch (SelfBeneficiaryConflictException) {
                $this->assertSame(0, $this->reviews());
            }
        }
    }

    // Rehire.

    #[Test]
    public function a_rehire_keeps_the_person_and_never_moves_an_old_decision(): void
    {
        $this->member($this->reviewer);
        $this->link($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $row = $this->runRow($october);
        $this->unlink();
        $this->separate($this->employee, '2015-10-31');
        $this->inSchool($this->school, fn () => DB::table('employment_records')->where('id', $this->record->id)->update(['ends_on' => '2015-10-31']));
        $this->travelTo(Carbon::parse('2015-12-01 06:00:00'));
        $hr = $this->createUserWithCapabilities($this->school, ['hr.employees.manage', 'hr.employees.assignments.manage']);

        $this->inSchool($this->school, fn () => app(EmployeeLifecycleService::class)->rehire($this->employee->fresh(), ['employment_type' => 'permanent', 'starts_on' => '2015-12-01'], $hr));
        $rehired = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $this->employee->id)->whereKeyNot($this->record->id)->firstOrFail());
        $this->raise('2015-12-01', '60000.00', record: $rehired);
        $december = $this->calculatedRun('2015-12-01');

        $this->assertTrue($this->finding($december, $this->reviewer)['conflicted'], 'the Employee row carries the person across employments');
        $this->assertFalse($this->finding($december, $this->checker)['conflicted']);
        $this->assertSame($row, $this->runRow($october), 'the old run stays on its old employment');
        $this->assertSame(1, $this->inSchool($this->school, fn () => EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->count()), 'and a rehire makes no link');
    }
}
