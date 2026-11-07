<?php

namespace Tests\Feature\Retention;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\Payroll\Application\Exceptions\SelfBeneficiaryConflictException;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\User;
use App\Support\Retention\Erasure\ErasureCaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\Feature\Payroll\Concerns\BuildsPayrollCorrections;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C7 (OD-HR-25), through the REAL retention commands on committed data, as the
 * retention identity: `platform:payroll-retention-prune` (retention_expire_payroll_*)
 * and `platform:employee-retention-prune`. Link history is evidence of the Employee
 * and leaves with them. A run another Employee's results keep also keeps this
 * Employee, because a calculation source names their assignment, so the exclusion
 * holds for as long as the run does. When the run expires everything goes.
 */
class PayrollAccountLinkRetentionTest extends TestCase
{
    use BuildsPayrollCorrections, BuildsPayrollIndependenceScenarios, CommitsRetentionFixtures, CreatesLinkedHrStaff, CreatesPayrollRetentionFixtures, ForcesConcurrentOverlap, ProvidesSensitiveActionMfa;

    private User $hr;

    private Employee $stayer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePayrollRetention();
        $this->buildWorld();
        $this->hr = $this->fullHrActor($this->school);
        $this->stayer = $this->paidEmployee($this->school, $this->setup);
        // Paid in Aug, so October pays only the change, and the stayer's first pay is decided.
        $august = $this->calculatedRun('2015-08-01');
        $this->approve($august, $this->checker);
        $this->postRun($august);
    }

    private function linkThenUnlinkAfter(User $user, callable $between): mixed
    {
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($this->employee->fresh(), $user->id, $this->hr));
        $result = $between();
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->unlinkUser($this->employee->fresh(), $this->hr));

        return $result;
    }

    private function member(User $user): void
    {
        if (! DB::table('school_memberships')->where('school_id', $this->school->id)->where('user_id', $user->id)->exists()) {
            $this->createMembership($user, $this->school);
        }
    }

    private function rows(string $table, string $column, string $id): int
    {
        return $this->rowsWhere($this->school, $table, $column, $id);
    }

    private function pruneEverything(): void
    {
        $this->travelBack();
        $this->artisan('platform:payroll-retention-prune')->assertSuccessful();
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->assertSuccessful();
    }

    private function endEmployment(Employee $employee): void
    {
        $this->separate($employee, '2015-12-31');
        $this->inSchool($this->school, fn () => DB::table('employment_records')->where('employee_id', $employee->id)->update(['ends_on' => '2015-12-31']));
    }

    private function obligationAfterUnlink(): PayrollRun
    {
        $this->member($this->reviewer);

        return $this->linkThenUnlinkAfter($this->reviewer, function (): PayrollRun {
            [$october, $november] = $this->octoberAndPostedNovember();
            $this->reverseRun($october);

            return $november;
        });
    }

    #[Test]
    public function live_evidence_keeps_everything_while_the_employee_is_employed(): void
    {
        $november = $this->obligationAfterUnlink();

        $this->pruneEverything();

        $this->assertSame(1, $this->rows('employees', 'id', $this->employee->id));
        $this->assertSame(1, $this->rows('employee_account_links', 'employee_id', $this->employee->id));
        $this->assertSame(1, $this->obligations($november));
    }

    #[Test]
    public function a_retained_run_keeps_its_employee_their_history_and_the_exclusion_until_the_run_goes(): void
    {
        $november = $this->obligationAfterUnlink();
        $this->endEmployment($this->employee);

        $this->pruneEverything();

        $this->assertSame(0, $this->rows('payroll_run_results', 'employee_id', $this->employee->id), 'control: the personal pay evidence really expired');
        $this->assertSame(1, $this->rows('payroll_runs', 'id', $november->id), 'the other Employee keeps the run');
        $this->assertSame(1, $this->obligations($november), 'and its obligation');
        $this->assertGreaterThan(1, $this->rows('employee_compensation_assignments', 'employment_record_id', $this->record->id), 'a decision still reads these');
        $this->assertSame(1, $this->rows('employees', 'id', $this->employee->id));
        $this->assertSame(1, $this->rows('employee_account_links', 'employee_id', $this->employee->id));

        $kept = $this->obligation($november);
        try {
            $this->inSchool($this->school, fn () => $this->reviewAs($kept, $this->reviewer));
            $this->fail('the Employee reviewed their own change after their pay evidence expired');
        } catch (SelfBeneficiaryConflictException) {
            $this->assertSame(0, $this->reviews());
        }
        $this->inSchool($this->school, fn () => $this->reviewAs($kept, $this->checker));
        $this->assertSame(1, $this->reviews(), 'an independent reviewer can still close it');

        // A lawful purge still proceeds: when the last evidence expires, everything goes.
        $this->endEmployment($this->stayer);

        $this->pruneEverything();

        $this->assertSame(0, $this->rows('payroll_runs', 'id', $november->id));
        $this->assertSame(0, $this->obligations(), 'the obligation leaves with its run');
        $this->assertSame(0, $this->reviews());
        $this->assertSame(0, $this->rows('employee_compensation_assignments', 'employment_record_id', $this->record->id));
        $this->assertSame(0, $this->rows('employees', 'id', $this->employee->id), 'nothing holds the Employee any longer');
        $this->assertSame(0, $this->rows('employee_account_links', 'employee_id', $this->employee->id), 'and the history leaves with them');
        $this->assertSame(0, $this->rows('employees', 'id', $this->stayer->id));
    }

    #[Test]
    public function a_retained_attestation_stands_and_goes_only_with_its_run(): void
    {
        $this->member($this->reviewer);
        $november = $this->linkThenUnlinkAfter($this->reviewer, function (): PayrollRun {
            $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
            $this->raise('2015-10-01', '55000.00');
            $october = $this->calculatedRun('2015-10-01');
            $this->approve($october, $this->checker);
            $this->postRun($october);
            $november = $this->calculatedRun('2015-11-01');
            $this->approve($november, $this->x);
            $this->reverseRun($october);

            return $november;
        });
        $this->inSchool($this->school, function () use ($november): void {
            $version = (string) app(PayrollPostingIndependence::class)->version($november->fresh());
            app(PayrollPostingService::class)->post($november->fresh(), $this->poster, $this->freshMfaProofFor($this->poster), null, new PostingAttestation($version, true));
        });
        $this->endEmployment($this->employee);

        $this->pruneEverything();

        $attestation = $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $november->id)->first());
        $this->assertSame($this->poster->id, $attestation->attested_by_user_id, 'the decision stays as it was made');
        $this->assertSame(1, $this->rows('employee_account_links', 'employee_id', $this->employee->id));

        $this->endEmployment($this->stayer);

        $this->pruneEverything();

        $this->assertSame(0, $this->rows('payroll_posting_attestations', 'payroll_run_id', $november->id));
        $this->assertSame(0, $this->rows('employee_account_links', 'employee_id', $this->employee->id));
    }

    #[Test]
    public function the_database_refuses_removing_an_assignment_a_calculation_read(): void
    {
        $assignment = $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->value('compensation_assignment_id'));

        $this->expectExceptionMessage('payroll_run_calculation_sources_assignment_fk');
        DB::connection('pgsql_admin')->table('employee_compensation_assignments')->where('id', $assignment)->delete();
    }

    #[Test]
    public function a_current_link_keeps_a_leaver_until_it_is_removed_and_then_the_history_goes_with_them(): void
    {
        $leaver = $this->createEmployee($this->school, ['user_id' => null]);
        $this->createEmploymentRecord($leaver, ['status' => 'separated', 'starts_on' => '2014-01-01', 'ends_on' => '2015-12-31']);
        $user = $this->createUser();
        $this->createMembership($user, $this->school);
        $this->inSchool($this->school, fn () => app(EmployeeService::class)->linkUser($leaver->fresh(), $user->id, $this->hr));
        $this->backdateEndRecording();

        $this->pruneEverything();

        $this->assertSame(1, $this->rows('employees', 'id', $leaver->id), 'a current link is a live purpose');

        $this->inSchool($this->school, fn () => app(EmployeeService::class)->unlinkUser($leaver->fresh(), $this->hr));
        $this->backdateEndRecording();

        $this->pruneEverything();

        $this->assertSame(0, $this->rows('employees', 'id', $leaver->id));
        $this->assertSame(0, $this->rows('employee_account_links', 'employee_id', $leaver->id), 'history never keeps a person past their own period');
        $this->assertSame(1, DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->count(), 'nor does it touch the account');
    }

    #[Test]
    public function a_rehired_employee_keeps_their_link_history_while_any_employment_is_live(): void
    {
        $this->member($this->reviewer);
        $this->linkThenUnlinkAfter($this->reviewer, fn () => null);
        $this->endEmployment($this->employee);
        $this->createEmploymentRecord($this->employee, ['status' => 'active', 'starts_on' => '2016-02-01', 'ends_on' => null]);

        $this->pruneEverything();

        $this->assertSame(1, $this->rows('employees', 'id', $this->employee->id));
        $this->assertSame(1, $this->rows('employee_account_links', 'employee_id', $this->employee->id));
    }

    private function formerMember(): User
    {
        $user = $this->createUser(['name' => 'Someone Identifiable']);
        $this->createMembership($user, $this->school);
        $this->inSchool($this->school, function () use ($user): void {
            app(EmployeeService::class)->linkUser($this->employee->fresh(), $user->id, $this->hr);
            app(EmployeeService::class)->unlinkUser($this->employee->fresh(), $this->hr);
        });
        DB::connection('pgsql_admin')->table('school_memberships')->where('user_id', $user->id)->update(['status' => 'suspended']);

        return $user;
    }

    private function approvedUserCase(User $user): string
    {
        $this->backdateEndRecording();
        $cases = app(ErasureCaseService::class);

        return $cases->decide($cases->open(null, 'user', $user->id, 'written')->id, 'approve', 'request_valid')->id;
    }

    #[Test]
    public function minimizing_a_former_user_keeps_the_history_and_what_it_proves(): void
    {
        $user = $this->formerMember();
        DB::connection('pgsql_admin')->table('school_memberships')->where('user_id', $user->id)->delete();

        app(ErasureCaseService::class)->execute($this->approvedUserCase($user), false);

        $row = DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->first();
        $this->assertNotSame('Someone Identifiable', $row->name, 'control: the identity really went');
        $this->assertNotNull($row->minimized_at);
        $this->assertSame(1, $this->rows('employee_account_links', 'user_id', $user->id), 'a stable id, with no personal data, keeps saying who this was');

        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $conflicted = $this->inSchool($this->school, fn () => DB::selectOne('SELECT bool_or(b.user_id = ?) AS c FROM payroll_run_first_paid_beneficiaries(?, ?, (SELECT id FROM payroll_run_calculations WHERE payroll_run_id = ? ORDER BY ordinal DESC LIMIT 1)) b', [$user->id, $this->school->id, $october->id, $october->id])->c);
        $this->assertTrue((bool) $conflicted);
    }

    /** HR.R1-C8 (PAY-17): one that can never post must not hold anything forever. */
    private function deadCorrectionOf(PayrollRun $target): PayrollRun
    {
        $correction = $this->calculatedCorrection($target, '2015-12-01');
        $this->reverseRun($target);

        return $correction;
    }

    #[Test]
    public function patching_the_retention_functions_kept_their_identity_hold_and_anchor_checks(): void
    {
        foreach (['retention_expire_payroll_employee_evidence', 'retention_expire_payroll_run'] as $function) {
            $source = (string) DB::connection('pgsql_admin')->selectOne('select prosrc from pg_proc where proname = ?', [$function])->prosrc;
            foreach (['retention_assert_retention_identity', 'retention_assert_not_held', 'app.retention_anchor_cutoff', 'payroll_run_is_dead_correction'] as $needed) {
                $this->assertStringContainsString($needed, $source, $function);
            }
        }
    }

    #[Test]
    public function a_held_school_still_keeps_a_dead_correction(): void
    {
        $this->travelTo(Carbon::parse('2015-09-01 06:00:00'));
        $target = $this->postedTarget('2015-09-01');
        $dead = $this->deadCorrectionOf($target);
        $this->endEmployment($this->employee);
        $this->endEmployment($this->stayer);
        config(['retention.hold_school_ids' => [$this->school->id]]);

        $this->pruneEverything();

        $this->assertSame(1, $this->rows('payroll_runs', 'id', $dead->id), 'a hold beats expiry');
        config(['retention.hold_school_ids' => []]);
    }

    #[Test]
    public function a_correction_that_can_never_post_holds_nothing_forever(): void
    {
        $this->travelTo(Carbon::parse('2015-09-01 06:00:00'));
        $target = $this->postedTarget('2015-09-01');
        $dead = $this->deadCorrectionOf($target);
        $this->endEmployment($this->employee);
        $this->endEmployment($this->stayer);

        $this->pruneEverything();

        $this->assertSame(0, $this->rows('payroll_runs', 'id', $dead->id), 'the dead correction left with its target');
        $this->assertSame(0, $this->rows('payroll_runs', 'id', $target->id));
        $this->assertSame(0, $this->rows('payroll_run_results', 'payroll_run_id', $dead->id));
        $this->assertSame(0, $this->rows('employees', 'id', $this->employee->id), 'and held no Employee');
    }

    #[Test]
    public function a_correction_that_may_still_post_keeps_holding_its_evidence(): void
    {
        $this->travelTo(Carbon::parse('2015-09-01 06:00:00'));
        $target = $this->postedTarget('2015-09-01');
        $live = $this->calculatedCorrection($target, '2015-12-01');
        $this->endEmployment($this->employee);
        $this->endEmployment($this->stayer);

        $this->pruneEverything();

        $this->assertSame(1, $this->rows('payroll_runs', 'id', $live->id), 'live working state is never aged');
        $this->assertSame(1, $this->rows('payroll_runs', 'id', $target->id));
        $this->assertSame(1, $this->rows('employees', 'id', $this->employee->id));
    }

    #[Test]
    public function a_correction_and_a_request_leave_with_their_employee(): void
    {
        $this->member($this->reviewer);
        $this->linkThenUnlinkAfter($this->reviewer, fn () => null);
        $link = $this->inSchool($this->school, fn () => EmployeeAccountLink::query()->where('employee_id', $this->employee->id)->firstOrFail());
        $correction = $this->requestCorrection($this->employee, $link->id, $this->linkedHrStaff($this->school));
        $this->approveCorrection($this->school, $correction, $this->linkedHrStaff($this->school));
        $this->endEmployment($this->employee);
        $this->endEmployment($this->stayer);

        $this->pruneEverything();

        $this->assertSame(0, $this->rows('employees', 'id', $this->employee->id), 'control');
        $this->assertSame(0, $this->rows('employee_account_links', 'employee_id', $this->employee->id));
        $this->assertSame(0, $this->rows('employee_account_link_corrections', 'employee_id', $this->employee->id), 'a corrected link and its decision block no lawful purge');
    }

    private function userWhoLeftTheSchool(): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $this->school, 'suspended');

        return $user;
    }

    #[Test]
    public function a_link_committed_first_keeps_the_identity_a_waiting_minimization_wanted(): void
    {
        $user = $this->userWhoLeftTheSchool();
        $case = $this->approvedUserCase($user);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', 'link', $this->school->id, $this->employee->id, $user->id],
            ['php', __DIR__.'/../../Support/erasure-op.php', 'execute-user', $case],
        );

        $this->assertSame('ok', $holder);
        $this->assertStringContainsString('user_identity=dependency_blocked', $contender, 'proved again under the User lock');
        $this->assertNull(DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->value('minimized_at'));
        $this->assertSame(1, $this->rows('employee_account_links', 'user_id', $user->id));
    }

    #[Test]
    public function a_minimization_committed_first_refuses_the_late_link_and_leaves_no_false_history(): void
    {
        $user = $this->userWhoLeftTheSchool();
        $case = $this->approvedUserCase($user);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', __DIR__.'/../../Support/erasure-op.php', 'execute-user', $case],
            ['php', __DIR__.'/../../Support/payroll-reconciliation-op.php', 'link', $this->school->id, $this->employee->id, $user->id],
        );

        $this->assertStringContainsString('user_identity=completed', $holder);
        $this->assertSame('rejected:Illuminate\\Database\\QueryException', $contender, 'users_minimized');
        $this->assertNotNull(DB::connection('pgsql_admin')->table('users')->where('id', $user->id)->value('minimized_at'));
        $this->assertSame(0, $this->rows('employee_account_links', 'user_id', $user->id));
        $this->assertNull($this->inSchool($this->school, fn () => $this->employee->fresh()->user_id));
    }
}
