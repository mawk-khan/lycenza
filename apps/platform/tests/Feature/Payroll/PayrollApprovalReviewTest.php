<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\Exceptions\StaleRunReviewException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollApprovalReviewService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Models\School;
use App\Models\SchoolAuditEvent as AuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * OD-HR-1 / OD-HR-2 (owner decisions 2026-11-05): the purpose-limited payroll
 * approval review and the approval it binds.
 *
 * World: August 2015 approved and posted (A, B, C at 50,000.00 each). In
 * September B's pay changes mid-month (a new compensation assignment recorded
 * by the HR officer, a manual override of 52,000.00 by the payroll officer),
 * C left on 31 August, D joined on 1 September at 40,000.00 (recorded by the
 * HR officer), and A is unchanged. Every amount is a synthetic input; the
 * expected figures are worked out by hand here, never by a second engine.
 */
class PayrollApprovalReviewTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    private PayrollRun $run;

    /** @var array<string, User> */
    private array $people = [];

    /** @var array<string, Employee> */
    private array $staff = [];

    /** @var array<string, mixed> */
    private array $setup = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Every fixture is recorded in its own (synthetic) past, so "since the previous approval" means what it says.
        $this->travelTo(Carbon::parse('2015-07-01 06:00:00'));
        $this->school = $this->createSchool();
        $setup = $this->setup = $this->payrollSetup($this->school);
        foreach (['A', 'B', 'C'] as $who) {
            $this->staff[$who] = $this->paidEmployee($this->school, $setup);
        }
        $this->postedRun($this->school, '2015-08-01', '2015-09-05');
        $this->travelTo(Carbon::parse('2015-09-20 06:00:00'));

        $this->people['hr'] = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view']);
        $this->people['officer'] = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->people['preparer'] = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage']);
        $this->people['checker'] = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);

        $this->inSchool($this->school, function () use ($setup): void {
            $record = fn (string $who) => EmploymentRecord::query()->where('employee_id', $this->staff[$who]->id)->firstOrFail();
            // C left at the end of August.
            DB::table('employment_records')->where('id', $record('C')->id)->update(['ends_on' => '2015-08-31', 'status' => 'separated']);
            DB::table('employee_compensation_assignments')->where('employment_record_id', $record('C')->id)->update(['effective_to' => '2015-08-31']);
            // B: a new assignment mid-September (so the run needs an override), recorded by the HR officer.
            app(CompensationService::class)->assign($this->school, $record('B'), $setup['structure'], Carbon::parse('2015-09-16'),
                [new FixedComponentValueInput($setup['line'], '55000.00')], $this->people['hr']);
            // D joined on 1 September, recorded by the HR officer.
            $this->staff['D'] = $this->createEmployee($this->school);
            $d = $this->createEmploymentRecord($this->staff['D'], ['status' => 'active', 'starts_on' => '2015-09-01', 'ends_on' => null]);
            app(CompensationService::class)->assign($this->school, $d, $setup['structure'], Carbon::parse('2015-09-01'),
                [new FixedComponentValueInput($setup['line'], '40000.00')], $this->people['hr']);

            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse('2015-09-01'), null, $this->people['preparer']), $this->people['preparer']);
            $runs = app(PayrollRunService::class);
            $this->run = $runs->createRun($period, $this->people['preparer']);
            $basic = SalaryComponent::query()->where('code', 'BASIC')->firstOrFail();
            $runs->recordManualOverride($this->run, $record('B'), [$basic->id => '52000.00'], 'Mid-month raise from 16 September', $this->people['officer']);
            $this->assertTrue($runs->calculate($this->run, $this->people['preparer'])->transitionedToCalculated);
            $this->run = $this->run->fresh();
        });
    }

    private function packet(?User $as = null, array $pages = []): array
    {
        return app(PayrollApprovalReviewService::class)->packet($this->school, $this->run->id, $as ?? $this->people['checker'], $pages);
    }

    private function approve(User $as, ?string $calculationId): PayrollRun
    {
        return $this->inSchool($this->school, fn () => app(PayrollRunAdministrationService::class)->approve($this->run->fresh(), $as, $this->freshMfaProofFor($as), $calculationId));
    }

    #[Test]
    public function an_author_who_recorded_a_change_before_the_previous_approval_never_approves_the_run_that_first_pays_it(): void
    {
        // Security review finding (2026-11-05): the window is what the run pays, not when the row was recorded.
        $early = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view']);
        $assignment = $this->inSchool($this->school, function () use ($early) {
            $a = EmploymentRecord::query()->where('employee_id', $this->staff['A']->id)->firstOrFail();

            return app(CompensationService::class)->assign($this->school, $a, $this->setup['structure'], Carbon::parse('2015-09-01'),
                [new FixedComponentValueInput($this->setup['line'], '50000.00')], $early);
        });
        // Recorded on 20 August -- before August's approval on 5 September -- effective from 1 September.
        DB::table('employee_compensation_assignments')->where('id', $assignment->id)->update(['created_at' => '2015-08-20 06:00:00']);

        $this->assertContains($early->id, $this->inSchool($this->school, fn () => app(PayrollApprovalReviewService::class)->compensationAuthors($this->run->fresh())));
        $this->assertSame(3, $this->packet()['compensation']->total(), 'the checker sees the change too');
        $this->assertContains('PAYROLL_SELF_APPROVAL', array_column($this->packet($early)['blocking'], 'code'));
        try {
            $this->approve($early, app(PayrollRunService::class)->latestCalculationId($this->run));
            $this->fail('a compensation author approved the run that pays the change');
        } catch (SelfApprovalNotAllowedException) {
            $this->assertSame('calculated', $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $this->run->id)->value('status')));
        }
    }

    #[Test]
    public function the_packet_shows_totals_changes_adjustments_and_compensation_changes_from_payroll_results(): void
    {
        $p = $this->packet();

        $this->assertSame(['gross' => '142000.00', 'deductions' => '0.00', 'net' => '142000.00', 'entries' => 3, 'reconciles' => true], $p['totals'], 'A 50,000 + B 52,000 (override) + D 40,000');
        $this->assertSame(['gross' => '150000.00', 'deductions' => '0.00', 'net' => '150000.00', 'entries' => 3, 'reconciles' => true], $p['previousTotals']);
        $this->assertSame([['code' => 'BASIC', 'name' => 'Basic', 'type' => 'earning', 'effect' => 'increase', 'amount' => '142000.00', 'currency' => 'INR']], $p['components']);
        $this->assertSame('2015-08', $p['previousRun']['periodMonth']);

        // Only what changed: B changed, C left, D is new; unchanged A is not repeated.
        $changes = collect($p['changes']->items())->keyBy('kind');
        $this->assertSame(3, $p['changes']->total());
        $this->assertSame([['gross' => '50000.00', 'deductions' => '0.00', 'net' => '50000.00'], ['gross' => '52000.00', 'deductions' => '0.00', 'net' => '52000.00']], [$changes['changed']['previous'], $changes['changed']['current']]);
        $this->assertNull($changes['left']['current']);
        $this->assertSame([null, '40000.00'], [$changes['new']['previous'], $changes['new']['current']['net']]);

        $adjustment = $p['adjustments']->items()[0];
        $this->assertSame(['manual_override', '52000.00', 'Mid-month raise from 16 September', $this->people['officer']->name], [$adjustment['mode'], $adjustment['amount'], $adjustment['reason'], $adjustment['author']]);

        // OD-HR-2: both compensation changes recorded since August's approval, with their author.
        $this->assertSame(2, $p['compensation']->total());
        $this->assertSame([$this->people['hr']->name, $this->people['hr']->name], array_column($p['compensation']->items(), 'author'));

        $this->assertTrue($p['approvable']);
        $this->assertSame($p['calculation']['id'], app(PayrollRunService::class)->latestCalculationId($this->run));
    }

    #[Test]
    public function the_packet_never_carries_profile_identity_bank_or_statutory_data(): void
    {
        $json = json_encode($this->packet());
        foreach (['date_of_birth', 'address', 'bank', 'ifsc', 'pan', 'aadhaar', 'uan', 'personal_email', 'personal_phone', 'nominee', 'work_email'] as $field) {
            $this->assertStringNotContainsStringIgnoringCase('"'.$field, $json, $field);
        }
        $employee = $this->packet()['changes']->items()[0]['employee'];
        $this->assertSame(['name', 'number'], array_keys($employee), 'a name and staff number only');
    }

    #[Test]
    public function every_participant_is_refused_and_the_packet_says_so(): void
    {
        foreach (['preparer', 'officer', 'hr'] as $who) {
            $user = $this->people[$who];
            // Give each participant the checker keys too: participation still refuses.
            $membership = SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $this->school->id)->firstOrFail();
            LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['payroll.runs.approve', 'payroll.compensation.sensitive.view']));
            app(CapabilityResolver::class)->forgetCache($user, $this->school);
            $p = $this->packet($user);
            $this->assertContains('PAYROLL_SELF_APPROVAL', array_column($p['blocking'], 'code'), $who);
            $this->assertFalse($p['approvable']);
            try {
                $this->approve($user, $p['calculation']['id']);
                $this->fail("{$who} must not approve");
            } catch (SelfApprovalNotAllowedException) {
            }
        }
    }

    #[Test]
    public function approval_binds_to_the_reviewed_calculation_and_a_recalculation_makes_it_stale(): void
    {
        $reviewed = $this->packet()['calculation']['id'];
        $this->inSchool($this->school, fn () => app(PayrollRunService::class)->calculate($this->run->fresh(), $this->people['preparer']));

        try {
            $this->approve($this->people['checker'], $reviewed);
            $this->fail('A stale review must be refused');
        } catch (StaleRunReviewException $e) {
            $this->assertSame('PAYROLL_REVIEW_STALE', $e->errorCode());
        }
        $this->assertSame('calculated', $this->inSchool($this->school, fn () => $this->run->fresh()->status));

        $fresh = $this->packet()['calculation']['id'];
        $this->assertNotSame($reviewed, $fresh);
        $approved = $this->approve($this->people['checker'], $fresh);
        $this->assertSame(['approved', $fresh], [$approved->status, $approved->approved_calculation_id]);
        $audit = $this->inSchool($this->school, fn () => AuditEvent::query()->where('event_type', 'payroll.run.approved')->where('subject_id', $this->run->id)->firstOrFail());
        $this->assertSame($fresh, $audit->metadata['approvedCalculationId']);

        // The decision is history: the packet now shows who approved and against what, and blocks.
        $after = $this->packet();
        $this->assertSame([$this->people['checker']->name, $fresh], [$after['run']['approvedBy'], $after['run']['approvedCalculationId']]);
        $this->assertContains('PAYROLL_RUN_NOT_AWAITING_APPROVAL', array_column($after['blocking'], 'code'));
    }

    #[Test]
    public function approving_needs_approve_and_sensitive_view_now_and_viewing_needs_both(): void
    {
        $approveOnly = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view']); // school_admin's default shape
        $sensitiveOnly = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.view', 'payroll.runs.view']);
        $calculation = app(PayrollRunService::class)->latestCalculationId($this->run);

        foreach ([$approveOnly, $sensitiveOnly] as $user) {
            try {
                $this->packet($user);
                $this->fail('the review needs both keys');
            } catch (AuthorizationException) {
            }
        }
        $this->expectException(AuthorizationException::class);
        $this->approve($approveOnly, $calculation);
    }

    #[Test]
    public function the_review_page_needs_current_mfa_and_both_keys_and_another_schools_run_is_not_found(): void
    {
        $checker = $this->people['checker'];
        $this->actingAs($checker)->post("/app/schools/{$this->school->id}/activate");
        // No current MFA assurance (no factor yet): the page never opens.
        $this->assertContains($this->get("/app/payroll/runs/{$this->run->id}/review")->status(), [302, 403]);

        $this->withMfaAssurance($checker);
        $page = $this->get("/app/payroll/runs/{$this->run->id}/review")->assertOk()->viewData('page')['props']['review'];
        $this->assertSame('142000.00', $page['totals']['net']);
        $viewed = $this->inSchool($this->school, fn () => AuditEvent::query()->where('event_type', 'payroll.run.review_viewed')->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail());
        $this->assertSame(['calculationId', 'previousRunId'], array_keys($viewed->metadata), 'ids only, never an amount');

        // The approval form needs the reviewed calculation.
        $this->post("/app/payroll/runs/{$this->run->id}/approve", ['mfa_code' => $this->freshMfaCode($checker)])->assertSessionHasErrors('calculation_id');

        // A user with only approve (the school_admin default) cannot open the review.
        $admin = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view']);
        $this->actingAs($admin)->post("/app/schools/{$this->school->id}/activate");
        $this->withMfaAssurance($admin)->get("/app/payroll/runs/{$this->run->id}/review")->assertForbidden();

        // Another School's checker never reads this run.
        $other = $this->createSchool();
        $outsider = $this->createUserWithCapabilities($other, ['payroll.runs.approve', 'payroll.compensation.sensitive.view']);
        $this->actingAs($outsider)->post("/app/schools/{$other->id}/activate");
        $this->withMfaAssurance($outsider)->get("/app/payroll/runs/{$this->run->id}/review")->assertNotFound();
    }

    #[Test]
    public function the_api_review_needs_an_mfa_token_and_approve_names_the_calculation(): void
    {
        $checker = $this->people['checker'];
        $plain = $checker->createToken('pre-mfa')->plainTextToken;
        $this->withToken($plain)->getJson("/api/v1/schools/{$this->school->id}/payroll-runs/{$this->run->id}/review")->assertForbidden();

        $token = $this->mfaToken($checker);
        $review = $this->withToken($token)->getJson("/api/v1/schools/{$this->school->id}/payroll-runs/{$this->run->id}/review")->assertOk()->json('data');
        $this->assertSame('142000.00', $review['totals']['net']);

        $this->withToken($token)->withHeader('Idempotency-Key', 'odhr1-missing')->postJson("/api/v1/schools/{$this->school->id}/payroll-runs/{$this->run->id}/approve", $this->mfaBody($token))
            ->assertStatus(422)->assertJsonPath('error.errors.calculation_id.0', 'The calculation id field is required.');
        $this->withToken($token)->withHeader('Idempotency-Key', 'odhr1-ok')->postJson("/api/v1/schools/{$this->school->id}/payroll-runs/{$this->run->id}/approve", [...$this->mfaBody($token), 'calculation_id' => $review['calculation']['id']])
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    #[Test]
    public function the_reviewer_add_on_role_holds_exactly_the_review_keys_and_school_admin_is_unchanged(): void
    {
        $reviewer = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')->where('roles.key', 'payroll_approval_reviewer')->orderBy('capability_key')->pluck('capability_key')->all();
        $this->assertSame(['payroll.compensation.sensitive.view', 'payroll.runs.view'], $reviewer);
        $admin = DB::table('role_capabilities')->join('roles', 'roles.id', '=', 'role_capabilities.role_id')->where('roles.key', 'school_admin')->pluck('capability_key')->all();
        $this->assertContains('payroll.runs.approve', $admin);
        $this->assertNotContains('payroll.compensation.sensitive.view', $admin, 'never a default sensitive view');
    }

    #[Test]
    public function the_database_refuses_an_approval_against_anything_but_the_latest_calculation_and_freezes_it(): void
    {
        $calc = app(PayrollRunService::class)->latestCalculationId($this->run);
        $first = $this->inSchool($this->school, fn () => DB::table('payroll_run_calculations')->where('payroll_run_id', $this->run->id)->orderBy('ordinal')->value('id'));
        $this->inSchool($this->school, fn () => app(PayrollRunService::class)->calculate($this->run->fresh(), $this->people['preparer']));
        $latest = app(PayrollRunService::class)->latestCalculationId($this->run);
        $this->assertNotSame($calc, $latest);

        $raw = fn (?string $id) => $this->inSchool($this->school, function () use ($id): string {
            try {
                DB::transaction(fn () => DB::update("update payroll_runs set status = 'approved', approved_by_user_id = ?, approved_at = now(), approved_calculation_id = ? where id = ?", [$this->people['checker']->id, $id, $this->run->id]));

                return 'ok';
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        });
        $this->assertStringContainsString('must be approved against its latest calculation', $raw($first));
        $this->assertStringContainsString('must be approved against its latest calculation', $raw(null));
        $this->assertSame('ok', $raw($latest));
        $changed = $this->inSchool($this->school, function () use ($first): string {
            try {
                DB::transaction(fn () => DB::update('update payroll_runs set approved_calculation_id = ? where id = ?', [$first, $this->run->id]));

                return 'ok';
            } catch (QueryException $e) {
                return $e->getMessage();
            }
        });
        $this->assertStringContainsString('approval evidence is final', $changed);
    }
}
