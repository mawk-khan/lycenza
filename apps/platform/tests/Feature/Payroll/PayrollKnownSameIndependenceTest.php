<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\Payroll\Application\Exceptions\PostingAttestationRequiredException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestorNotIndependentException;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO17-N1, Option A (owner decision D-WO18-1). After an approval, a reversal can make the
 * approved run pay a compensation change FIRST. If its approver is that change's Employee, the run lost its
 * independence: an attestation before posting, or an obligation once posted (rules 144, 145). Before the
 * fix only the Employee's OWN account counted; an account known to be the same person (rule 152) let the run
 * post with no independent check. Now both are treated alike, in the exception, the discharge rule and the
 * reversal's affected runs. Completed approvals and postings are never rewritten. Synthetic, dates in 2015.
 */
class PayrollKnownSameIndependenceTest extends TestCase
{
    use BuildsPayrollIndependenceScenarios, CreatesLinkedHrStaff, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    private function decider(): User
    {
        return $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view', 'payroll.runs.post']);
    }

    /** U is linked to the paid Employee; D shares the duplicate E' with U. Returns [U, D, E']. */
    private function ownAndAlias(): array
    {
        $u = $this->decider();
        $d = $this->decider();
        $dup = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($u, $dup);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => null]));
        $this->link($u, $this->employee);
        $this->link($d, $dup);

        return [$u, $d, $dup];
    }

    /** October (independently approved, posted) pays X's raise first; $approver approves November; October is reversed. */
    private function novemberApprovedThenOctoberReversed(User $approver, bool $postNovemberFirst = false): PayrollRun
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $approver);
        if ($postNovemberFirst) {
            $this->postRun($november);
        }
        $this->reverseRun($october);

        return $november;
    }

    private function exceptionRows(PayrollRun $run): int
    {
        return $this->inSchool($this->school, fn () => count(DB::select('SELECT * FROM payroll_run_independence_exception(?, ?)', [$this->school->id, $run->id])));
    }

    private function postWithout(PayrollRun $run): string
    {
        try {
            $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance)));

            return 'posted';
        } catch (\Throwable $e) {
            return class_basename($e);
        }
    }

    #[Test]
    public function wo17_n1_an_alias_approver_loses_independence_exactly_like_the_employees_own_account(): void
    {
        $this->buildWorld();
        [, $d] = $this->ownAndAlias();
        $november = $this->novemberApprovedThenOctoberReversed($d);
        $before = $this->runRow($november);

        $this->assertSame(1, $this->exceptionRows($november), 'the raise November now pays first is its approver\'s own');
        $this->assertNotNull($this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($november->fresh())), 'an attestation is required');
        $this->assertSame(class_basename(PostingAttestationRequiredException::class), $this->postWithout($november));
        $this->assertSame(0, $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $november->id)->count()), 'nothing reached Finance');
        $this->assertSame($before, $this->runRow($november), 'the approval is not rewritten');

        // The alias (the approver) cannot attest its own exception; an independent poster can, and the run posts.
        $this->refused(PostingAttestorNotIndependentException::class, fn () => $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($november->fresh(), $d, $this->freshMfaProofFor($d), null, new PostingAttestation((string) app(PayrollPostingIndependence::class)->version($november->fresh()), true))));
        $version = (string) $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($november->fresh()));
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($november->fresh(), $this->poster, $this->freshMfaProofFor($this->poster), null, new PostingAttestation($version, true)));
        $after = $this->runRow($november);
        $this->assertSame('posted', $after['status']);
        $this->assertSame([$before['approved_by_user_id'], $before['approved_at'], $before['approved_calculation_id']], [$after['approved_by_user_id'], $after['approved_at'], $after['approved_calculation_id']]);
    }

    private function refused(string $exception, callable $write): void
    {
        try {
            $write();
            $this->fail("expected {$exception}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }
    }

    #[Test]
    public function the_employees_own_account_is_unchanged_as_the_control(): void
    {
        $this->buildWorld();
        [$u] = $this->ownAndAlias();
        $november = $this->novemberApprovedThenOctoberReversed($u);

        $this->assertSame(1, $this->exceptionRows($november));
        $this->assertSame(class_basename(PostingAttestationRequiredException::class), $this->postWithout($november));
    }

    #[Test]
    public function a_posted_run_approved_by_an_alias_opens_an_obligation_when_the_reversal_lands(): void
    {
        $this->buildWorld();
        [, $d] = $this->ownAndAlias();
        $november = $this->novemberApprovedThenOctoberReversed($d, postNovemberFirst: true);

        $this->assertSame(1, $this->obligations($november), 'payroll_reversal_affected_runs found it (rule 144)');
        $this->assertSame('posted', $this->runRow($november)['status'], 'the posting stands; nothing is reversed or rewritten');
    }

    #[Test]
    public function an_approval_by_an_alias_never_discharges_the_employees_change(): void
    {
        $this->buildWorld();
        $u = $this->decider();
        $d = $this->decider();
        $this->link($u, $this->employee);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        // D approves October before anything joins D to U (WO-17 would refuse it afterwards) -- then a link does.
        $this->approve($october, $d);
        $dup = $this->createEmployee($this->school, ['user_id' => null]);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($this->employee->id)->update(['user_id' => null]));
        $this->link($u, $dup);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => null]));
        $this->link($d, $dup);

        $november = $this->calculatedRun('2015-11-01');
        $latest = $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($november->fresh()));
        $firstPaid = $this->inSchool($this->school, fn () => count(DB::select('SELECT * FROM payroll_run_first_paid_compensation(?, ?, ?)', [$this->school->id, $november->id, $latest])));

        $this->assertSame(1, $firstPaid, 'October\'s approval by an account known to be the Employee does not discharge the raise');
    }

    #[Test]
    public function a_corrected_link_is_no_evidence_and_the_exception_follows_the_current_history(): void
    {
        $this->buildWorld();
        [$u, $d, $dup] = $this->ownAndAlias();
        $november = $this->novemberApprovedThenOctoberReversed($d);
        $this->assertSame(1, $this->exceptionRows($november));

        $link = $this->inSchool($this->school, fn () => EmployeeAccountLink::query()->where('employee_id', $dup->id)->where('user_id', $u->id)->firstOrFail());
        $this->correctLink($this->inSchool($this->school, fn () => Employee::query()->findOrFail($dup->id)), $link->id);

        $this->assertSame(0, $this->exceptionRows($november), 'after an approved correction D is no longer known to be the Employee');
    }

    #[Test]
    public function an_unknown_approver_and_an_alias_only_in_another_school_never_create_an_exception(): void
    {
        $this->buildWorld();
        $u = $this->decider();
        $d = $this->decider();
        $this->link($u, $this->employee);
        $other = $this->createSchool();
        $this->createMembership($u, $other);
        $this->createMembership($d, $other);
        $foreign = $this->createEmployee($other, ['user_id' => null]);
        foreach ([$u->id, null, $d->id] as $userId) {
            $this->inSchool($other, fn () => Employee::query()->whereKey($foreign->id)->update(['user_id' => $userId]));
        }
        $november = $this->novemberApprovedThenOctoberReversed($d);

        $this->assertSame(0, $this->exceptionRows($november), 'U and D are joined only in another School');
        $this->assertSame('posted', $this->postWithout($november), 'no attestation is invented');
    }

    #[Test]
    public function the_runtime_role_cannot_post_an_alias_approved_run_without_its_attestation(): void
    {
        $this->buildWorld();
        [, $d] = $this->ownAndAlias();
        $november = $this->novemberApprovedThenOctoberReversed($d);

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        try {
            $this->inSchool($this->school, fn () => DB::transaction(fn () => DB::update(
                "UPDATE payroll_runs SET status = 'posted', posted_by_user_id = ?, posted_at = now() WHERE id = ?", [$this->finance->id, $november->id],
            )));
            $this->fail('the database accepted the posting');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lost its approval independence and has no attestation', $e->getMessage());
        }
        $this->assertSame('approved', $this->runRow($november)['status']);
    }

    /**
     * WO17-N1 review L-2, the "discharged" step of payroll_reversal_affected_runs: October was approved by D, who a
     * later link shows to be the Employee, so October never discharged the raise. Reversing it takes nothing from
     * November (already paying the raise first, already in its exception since the link), so no obligation opens:
     * a link change alone opens none after posting (rule 145). Before the fix October counted as a discharge.
     */
    #[Test]
    public function reversing_a_run_an_alias_approved_implicates_nothing_new(): void
    {
        $this->buildWorld();
        $u = $this->decider();
        $d = $this->decider();
        $dup = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($u, $dup);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => null]));
        $this->link($u, $this->employee);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $d);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->postRun($november);

        $this->link($d, $dup); // D is now known to be the Employee: October never discharged the raise.
        $this->assertSame(1, $this->exceptionRows($november), 'the link alone put November in an exception');

        $this->reverseRun($october);

        $this->assertSame(0, $this->obligations($november), 'the reversal took nothing from November');
    }

    /** D2 shares a duplicate Employee with X, the author of every raise here: D2 is known to be the author. */
    private function aliasOfTheAuthor(): User
    {
        $d2 = $this->decider();
        $dup = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($this->x, $dup);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => null]));
        $this->link($d2, $dup);

        return $d2;
    }

    /** D-WO17N1-1 (review M-2): the author side, exactly as the Employee side. */
    #[Test]
    public function an_account_known_to_be_the_author_loses_independence_exactly_like_the_authors_own(): void
    {
        $this->buildWorld();
        $d2 = $this->aliasOfTheAuthor();
        $november = $this->novemberApprovedThenOctoberReversed($d2);
        $before = $this->runRow($november);

        $this->assertSame(1, $this->exceptionRows($november), 'November now pays first a raise its approver is known to have authored');
        $this->assertSame(class_basename(PostingAttestationRequiredException::class), $this->postWithout($november));
        $this->assertSame($before, $this->runRow($november), 'the approval is not rewritten');
    }

    #[Test]
    public function the_authors_own_account_is_unchanged_as_the_control(): void
    {
        $this->buildWorld();
        $november = $this->novemberApprovedThenOctoberReversed($this->x);

        $this->assertSame(1, $this->exceptionRows($november));
        $this->assertSame(class_basename(PostingAttestationRequiredException::class), $this->postWithout($november));
    }

    #[Test]
    public function a_posted_run_approved_by_an_account_known_to_be_the_author_opens_an_obligation(): void
    {
        $this->buildWorld();
        $d2 = $this->aliasOfTheAuthor();
        $november = $this->novemberApprovedThenOctoberReversed($d2, postNovemberFirst: true);

        $this->assertSame(1, $this->obligations($november));
    }

    #[Test]
    public function an_approval_by_an_account_known_to_be_the_author_never_discharges_the_change(): void
    {
        $this->buildWorld();
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $d2 = $this->decider();
        $this->approve($october, $d2); // D2 is joined to X only afterwards (WO-16 would refuse it otherwise)
        $dup = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($this->x, $dup);
        $this->inSchool($this->school, fn () => Employee::query()->whereKey($dup->id)->update(['user_id' => null]));
        $this->link($d2, $dup);

        $november = $this->calculatedRun('2015-11-01');
        $latest = $this->inSchool($this->school, fn () => app(PayrollRunService::class)->latestCalculationId($november->fresh()));
        $this->assertSame(1, $this->inSchool($this->school, fn () => count(DB::select('SELECT * FROM payroll_run_first_paid_compensation(?, ?, ?)', [$this->school->id, $november->id, $latest]))));
    }
}
