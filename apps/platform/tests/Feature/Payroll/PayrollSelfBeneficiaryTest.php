<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\Exceptions\SelfBeneficiaryConflictException;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PayrollSelfBeneficiaries;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C6 (PAY-19, OD-HR-24): nobody decides on a run that pays a change to their
 * own pay for the first time. Receiving a salary is not a conflict.
 */
class PayrollSelfBeneficiaryTest extends TestCase
{
    use BuildsPayrollIndependenceScenarios, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** The Employee's change is discharged by an approved, posted September. */
    private function septemberPaysTheChange(): PayrollRun
    {
        $this->travelTo(Carbon::parse('2015-08-28 06:00:00'));
        $this->raise('2015-09-01', '55000.00');
        $september = $this->calculatedRun('2015-09-01');
        $this->approve($september, $this->checker);
        $this->postRun($september);

        return $september;
    }

    /** @return array<string, array{0: string}> */
    public static function amounts(): array
    {
        return ['a rise' => ['60000.00'], 'a cut' => ['1000.00'], 'the same amount' => ['50000.00']];
    }

    #[Test]
    public function a_person_who_merely_receives_a_salary_may_approve_the_school_payroll(): void
    {
        $this->septemberPaysTheChange();
        $this->link($this->reviewer);

        $october = $this->calculatedRun('2015-10-01');
        $approved = $this->approve($october, $this->reviewer);

        $this->assertSame('approved', $approved->status, 'their pay is in the run, unchanged and already approved');
    }

    #[Test]
    #[DataProvider('amounts')]
    public function the_employee_of_a_first_paid_change_cannot_approve_whatever_it_did_to_the_amount(string $amount): void
    {
        $this->link($this->reviewer);
        $this->septemberPaysTheChange();
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', $amount);
        $october = $this->calculatedRun('2015-10-01');

        try {
            $this->approve($october, $this->reviewer);
            $this->fail('approved a change to their own pay');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame('calculated', $this->runRow($october)['status']);
        }
    }

    #[Test]
    public function an_author_of_their_own_change_stays_a_maker_first(): void
    {
        $this->link($this->x);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00', $this->checker);
        $october = $this->calculatedRun('2015-10-01');

        $this->expectException(SelfApprovalNotAllowedException::class);
        $this->approve($october, $this->checker);
    }

    #[Test]
    public function someone_else_may_approve_the_run_the_employee_cannot(): void
    {
        $this->link($this->reviewer);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');

        $this->assertSame('approved', $this->approve($october, $this->checker)->status);
    }

    #[Test]
    public function every_key_held_at_once_changes_nothing(): void
    {
        $all = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.post', 'payroll.runs.reverse', 'payroll.compensation.sensitive.view', 'payroll.compensation.sensitive.manage', 'payroll.runs.view']);
        $this->link($all);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');

        $this->expectException(SelfBeneficiaryConflictException::class);
        $this->approve($october, $all);
    }

    #[Test]
    public function the_first_payroll_of_a_school_needs_an_approver_not_paid_by_it(): void
    {
        // Owner decision 2026-11-06: an Employee's first assignment counts, with no override.
        $this->link($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');

        try {
            $this->approve($october, $this->reviewer);
            $this->fail('approved their own opening salary');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame('calculated', $this->runRow($october)['status'], 'it waits; nothing lowers the rule');
        }
        $this->assertSame('approved', $this->approve($october, $this->checker)->status, 'someone not paid by it can');
    }

    #[Test]
    public function an_implicated_employee_with_no_account_proceeds_and_is_recorded_never_called_clear(): void
    {
        $october = $this->calculatedRun('2015-10-01');

        $this->assertSame(1, $this->inSchool($this->school, fn () => app(PayrollSelfBeneficiaries::class)->of($october, (string) app(PayrollRunService::class)->latestCalculationId($october), $this->checker->id))->unlinkedEmployees);
        $packet = $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $october->id, $this->checker));
        $this->assertContains('PAYROLL_EMPLOYEES_WITHOUT_ACCOUNT', array_column($packet['warnings'], 'code'));

        $this->approve($october, $this->checker);

        $this->assertSame(1, $this->audit('payroll.run.approved', $october)[0]['employeesWithoutAccount']);
    }

    #[Test]
    public function the_review_packet_shows_the_conflict_before_the_attempt_and_names_nothing_else(): void
    {
        $this->link($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');

        $packet = $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->packet($this->school, $october->id, $this->reviewer));

        $this->assertContains('PAYROLL_SELF_BENEFICIARY_CONFLICT', array_column($packet['blocking'], 'code'));
        $this->assertFalse($packet['approvable']);
        $this->assertNotContains('PAYROLL_EMPLOYEES_WITHOUT_ACCOUNT', array_column($packet['warnings'], 'code'), 'the only first-paid Employee is linked');
    }

    #[Test]
    public function a_link_in_another_school_is_no_conflict_here(): void
    {
        $other = $this->createSchool();
        $setup = $this->payrollSetup($other);
        $employee = $this->paidEmployee($other, $setup);
        $this->inSchool($other, fn () => DB::table('employees')->where('id', $employee->id)->update(['user_id' => $this->checker->id]));
        $october = $this->calculatedRun('2015-10-01');

        $this->assertSame('approved', $this->approve($october, $this->checker)->status, 'employees is School-scoped, and so is this');
    }

    #[Test]
    public function a_rehire_stays_the_same_person_on_a_new_employment(): void
    {
        $this->link($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $hr = $this->createUserWithCapabilities($this->school, ['hr.employees.manage', 'hr.employees.assignments.manage']);
        $this->separate($this->employee, '2015-10-31');
        $this->inSchool($this->school, fn () => DB::table('employment_records')->where('id', $this->record->id)->update(['ends_on' => '2015-10-31']));
        $this->travelTo(Carbon::parse('2015-12-01 06:00:00'));

        $this->inSchool($this->school, fn () => app(EmployeeLifecycleService::class)->rehire($this->employee->fresh(), ['employment_type' => 'permanent', 'starts_on' => '2015-12-01'], $hr));
        $rehired = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $this->employee->id)->whereKeyNot($this->record->id)->firstOrFail());
        $this->raise('2015-12-01', '60000.00', record: $rehired);
        $december = $this->calculatedRun('2015-12-01');
        $finding = fn (string $userId) => $this->inSchool($this->school, fn () => app(PayrollSelfBeneficiaries::class)->of($december, $this->runCalculation($december), $userId));

        $this->assertNotSame($this->record->id, $rehired->id);
        $this->assertTrue($finding($this->reviewer->id)->conflicted, 'the Employee row carries the identity, not the employment');
        $this->assertFalse($finding($this->checker->id)->conflicted);
    }

    private function runCalculation(PayrollRun $run): string
    {
        return (string) app(PayrollRunService::class)->latestCalculationId($run);
    }

    /** X approves November; October is reversed; November awaits posting. */
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

    #[Test]
    public function the_employee_of_an_implicated_change_cannot_attest_to_it(): void
    {
        $november = $this->awaitingAttestation();
        $this->link($this->poster);
        $version = (string) $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($november->fresh()));

        try {
            $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($november->fresh(), $this->poster, $this->freshMfaProofFor($this->poster), null, new PostingAttestation($version, true)));
            $this->fail('attested to a change to their own pay');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame('approved', $this->runRow($november)['status']);
        }

        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->poster, true));
        $this->assertSame(['self_beneficiary', null, null], [$review['blockedReason'], $review['evidenceVersion'], $review['details']]);
    }

    #[Test]
    public function an_independent_attestor_records_the_employees_the_check_could_not_see(): void
    {
        $november = $this->awaitingAttestation();
        $version = (string) $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($november->fresh()));

        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($november->fresh(), $this->poster, $this->freshMfaProofFor($this->poster), null, new PostingAttestation($version, true)));

        $this->assertSame(1, $this->audit('payroll.run.independence_attested', $november)[0]['employeesWithoutAccount']);
    }

    #[Test]
    public function the_employee_of_an_implicated_change_cannot_review_its_reconciliation(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);
        $o = $this->obligation($november);
        $this->link($this->reviewer);

        try {
            $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer));
            $this->fail('reviewed a change to their own pay');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame(0, $this->reviews());
        }
    }

    #[Test]
    public function a_reviewer_records_the_employees_the_check_could_not_see(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);

        $review = $this->inSchool($this->school, fn () => $this->reviewAs($this->obligation($november), $this->reviewer));

        $this->assertSame(1, $review->unlinked_employee_count);
        $this->assertSame(1, $this->audit('payroll.run.reconciliation_reviewed', $november)[0]['employeesWithoutAccount']);
    }

    private function assertRuntimeRefuses(callable $write, string $because): void
    {
        try {
            DB::transaction(fn () => $this->inSchool($this->school, $write));
            $this->fail('the runtime role was allowed to: '.$because);
        } catch (QueryException $e) {
            $this->assertStringContainsString('guard', $e->getMessage(), $because);
        }
    }

    #[Test]
    public function the_database_refuses_it_whichever_code_writes(): void
    {
        $this->link($this->reviewer);
        $october = $this->calculatedRun('2015-10-01');
        $calculation = $this->inSchool($this->school, fn () => $this->runCalculation($october));

        $this->assertRuntimeRefuses(fn () => DB::table('payroll_runs')->where('id', $october->id)->update(['status' => 'approved', 'approved_by_user_id' => $this->reviewer->id, 'approved_at' => now(), 'approved_calculation_id' => $calculation]), 'approve own change');
        $this->assertSame('calculated', $this->runRow($october)['status']);
    }

    #[Test]
    public function the_database_refuses_a_forged_attestation_and_review_by_a_beneficiary(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);
        $o = $this->obligation($november);
        $this->link($this->reviewer);

        $this->assertRuntimeRefuses(fn () => DB::table('payroll_reversal_reconciliation_reviews')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'reconciliation_id' => $o->id,
            'evidence_version' => $this->reviewVersion($o), 'statement_code' => 'reversal_impact_reviewed_v1', 'unlinked_employee_count' => 0, 'reviewed_by_user_id' => $this->reviewer->id]), 'review own change');
        $this->assertSame(0, $this->reviews());
    }

    #[Test]
    public function changing_an_account_link_takes_the_compensation_lock_a_decision_holds(): void
    {
        $trigger = DB::selectOne("select pg_get_triggerdef(oid) as d from pg_trigger where tgname = 'trg_employees_user_link_payroll_lock'");

        $this->assertStringContainsString('user_id', $trigger->d);
        $this->assertStringContainsString('employees_user_link_payroll_lock', $trigger->d);
    }
}
