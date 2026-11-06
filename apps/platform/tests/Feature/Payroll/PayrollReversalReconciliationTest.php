<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\ReconciliationAcceptanceRequiredException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationAlreadyReviewedException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationEvidenceStaleException;
use App\Domain\Payroll\Application\Exceptions\ReconciliationReviewerNotIndependentException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationReadService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollReversalReconciliation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\SchoolAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Payroll\Concerns\BuildsPayrollIndependenceScenarios;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23): a reversal after posting always completes and
 * always leaves an OPEN obligation that only an independent person closes.
 */
class PayrollReversalReconciliationTest extends TestCase
{
    use BuildsPayrollIndependenceScenarios, CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function reversedAfterPosting(): array
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);

        return [$october, $november, $this->obligation($november)];
    }

    private function assertRefused(string $exception, callable $write): void
    {
        $before = $this->reviews();
        try {
            $write();
            $this->fail("expected {$exception}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }
        $this->assertSame($before, $this->reviews(), 'a refusal records nothing');
    }

    private function state(PayrollReversalReconciliation $o, $as = null): array
    {
        return app(PayrollReversalReconciliationReadService::class)->show($this->school, $as ?? $this->reviewer, $o->id, true);
    }

    #[Test]
    public function a_reversal_after_posting_opens_one_obligation_and_changes_no_history(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $novemberBefore = $this->runRow($november);
        $octoberBefore = $this->runRow($october);
        $sources = $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->orderBy('id')->pluck('id')->all());

        $this->reverseRun($october);

        $this->assertSame(1, $this->obligations($november));
        $this->assertSame($novemberBefore, $this->runRow($november), 'the posted run is never reopened');
        $this->assertSame($octoberBefore, $this->runRow($october), 'status stays posted; a reversal is a new row (ADR 0034)');
        $this->assertSame($sources, $this->inSchool($this->school, fn () => DB::table('payroll_run_calculation_sources')->orderBy('id')->pluck('id')->all()));

        $o = $this->obligation($november);
        $this->assertSame($novemberBefore['approved_calculation_id'], $o->payroll_run_calculation_id);
        $this->assertSame('open', $this->inSchool($this->school, fn () => $this->state($o))['state']);
        $opened = $this->audit('payroll.run.reconciliation_opened', $november);
        $this->assertCount(1, $opened);
        $this->assertSame([$o->id, $october->id], [$opened[0]['reconciliationId'], $opened[0]['reversedRunId']]);
    }

    #[Test]
    public function a_reversal_before_posting_is_the_attestation_case_and_opens_nothing(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);

        $this->reverseRun($october);

        $this->assertSame(0, $this->obligations(), 'an approved run meets the attestation at posting');
        $this->assertCount(1, $this->audit('payroll.run.independence_changed', $november));
        $version = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($november->fresh()));
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($november->fresh(), $this->poster, $this->freshMfaProofFor($this->poster), null, new PostingAttestation((string) $version, true)));
        $this->assertSame(0, $this->obligations(), 'posted with its evidence attested: nothing is unreviewed');
    }

    #[Test]
    public function a_reversal_before_calculation_or_approval_opens_nothing_and_stays_refused_at_approval(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $this->reverseRun($october);

        $november = $this->calculatedRun('2015-11-01');

        $this->assertSame(0, $this->obligations());
        $this->expectException(SelfApprovalNotAllowedException::class);
        $this->approve($november, $this->x);
    }

    #[Test]
    public function every_dependent_posted_run_gets_its_own_obligation(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->x);
        $this->postRun($december);

        $this->reverseRun($october);

        $this->assertSame([1, 1, 2], [$this->obligations($november), $this->obligations($december), $this->obligations()]);
    }

    #[Test]
    public function a_run_covered_by_another_standing_approval_is_not_affected_until_that_one_goes_too(): void
    {
        $this->travelTo(Carbon::parse('2015-08-28 06:00:00'));
        $this->raise('2015-09-01', '55000.00');
        $september = $this->calculatedRun('2015-09-01');
        $this->approve($september, $this->checker);
        $this->postRun($september);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->postRun($november);

        $this->reverseRun($october);
        $this->assertSame(0, $this->obligations(), 'September still stands');
        $this->assertCount(0, $this->audit('payroll.run.independence_changed', $november));

        $this->reverseRun($september);
        $this->assertSame(1, $this->obligations($november));
    }

    #[Test]
    public function pay_20_a_run_with_an_unrelated_exception_is_never_recorded_as_affected(): void
    {
        $second = $this->paidEmployee($this->school, $this->setup);
        $secondRecord = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $second->id)->firstOrFail());
        $this->travelTo(Carbon::parse('2015-07-28 06:00:00'));
        $this->raise('2015-08-01', '55000.00');
        foreach (['2015-08-01', '2015-09-01'] as $month) {
            $run = $this->calculatedRun($month);
            $this->approve($run, $this->checker);
            $this->postRun($run);
            $last = $run;
        }
        $this->raise('2015-10-01', '56000.00', record: $secondRecord);
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->postRun($november);

        $this->reverseRun($october); // the only approval of the second change: November now has an exception
        $this->assertSame(1, $this->obligations($november));

        $this->reverseRun($last); // September discharged the first change, and August still does
        $this->assertSame(1, $this->obligations($november), 'its evidence did not change');
        $this->assertCount(1, $this->audit('payroll.run.independence_changed', $november), 'PAY-20: and nothing says it did');
    }

    #[Test]
    public function reversing_a_correction_run_affects_nothing_because_it_paid_no_compensation_change(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $correction = $this->inSchool($this->school, function () use ($november): PayrollRun {
            $runs = app(PayrollRunService::class);
            $correction = $runs->createCorrectionRun($november->fresh(), $november->fresh()->period, $this->preparer);
            $runs->recordCorrectionDelta($correction, $this->record->fresh(), [new CorrectionDeltaInput((string) DB::table('salary_components')->where('code', 'BASIC')->value('id'), '500.00', 'increase')], 'Missed allowance', $this->preparer);
            $runs->calculate($correction->fresh(), $this->preparer);

            return $correction->fresh();
        });
        $this->approve($correction, $this->checker);
        $this->postRun($correction);

        $this->reverseRun($correction);

        $this->assertSame(0, $this->obligations());
        $this->assertCount(0, $this->audit('payroll.run.reversal_impact_unknown', $correction), 'a correction run has no provenance to miss');
    }

    #[Test]
    public function a_run_already_reversed_is_not_affected_and_one_reversed_later_keeps_its_obligation(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($november);
        $this->reverseRun($october);
        $this->assertSame(0, $this->obligations(), 'nothing of it stands to reconcile');

        $this->setUpSecondWorld();
    }

    private function setUpSecondWorld(): void
    {
        $this->buildWorld();
        [$october, $november] = $this->octoberAndPostedNovember();
        $this->reverseRun($october);
        $o = $this->obligation($november);
        $this->reverseRun($november);

        $this->assertSame(1, $this->obligations($november), 'a reversal never clears an obligation');
        $this->inSchool($this->school, function () use ($o): void {
            $state = $this->state($o);
            $this->assertSame('open', $state['state']);
            $this->assertTrue($state['affectedRun']['sinceReversed']);
        });
    }

    #[Test]
    public function an_independent_reviewer_closes_it_and_only_the_review_is_new(): void
    {
        [$october, $november, $o] = $this->reversedAfterPosting();
        $before = [$this->runRow($october), $this->runRow($november), $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->orderBy('id')->pluck('id')->all())];

        $review = $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer));

        $this->assertSame($this->reviewer->id, $review->reviewed_by_user_id);
        $this->assertSame('reversal_impact_reviewed_v1', $review->statement_code);
        $this->assertSame($before, [$this->runRow($october), $this->runRow($november), $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->orderBy('id')->pluck('id')->all())], 'never a new approval, posting or reversal');
        $state = $this->inSchool($this->school, fn () => $this->state($o));
        $this->assertSame(['reviewed', 'already_reviewed', null], [$state['state'], $state['blockedReason'], $state['reviewVersion']]);
        $this->assertSame($this->reviewer->name, $state['review']['reviewedBy']);
        $audit = $this->audit('payroll.run.reconciliation_reviewed', $november);
        $this->assertCount(1, $audit);
        $this->assertSame([$o->id, $review->id, 'reversal_impact_reviewed_v1'], [$audit[0]['reconciliationId'], $audit[0]['reviewId'], $audit[0]['statementCode']]);
    }

    #[Test]
    public function the_run_makers_the_approver_and_the_reverser_never_review_it(): void
    {
        [, $november, $o] = $this->reversedAfterPosting();

        foreach ([$this->x, $this->preparer, $this->finance] as $actor) {
            $this->assertRefused(ReconciliationReviewerNotIndependentException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $actor)));
        }
        $this->assertSame('not_independent', $this->inSchool($this->school, fn () => $this->state($o, $this->x))['blockedReason']);
        $this->assertNull($this->inSchool($this->school, fn () => $this->state($o, $this->x))['reviewVersion']);
        $this->assertSame(1, $this->obligations($november));
    }

    #[Test]
    public function the_approver_of_the_reversed_run_may_review_because_the_decision_excludes_only_the_affected_run(): void
    {
        [, , $o] = $this->reversedAfterPosting();

        $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->checker));

        $this->assertSame(1, $this->reviews());
    }

    #[Test]
    public function reviewing_needs_both_keys_now_even_for_an_independent_person(): void
    {
        [, , $o] = $this->reversedAfterPosting();

        $this->assertRefused(AuthorizationException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->poster)));
        $noSensitive = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view']);
        $this->assertRefused(AuthorizationException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $noSensitive)));
        $this->assertSame('cannot_review', $this->inSchool($this->school, fn () => $this->state($o, $this->poster))['blockedReason']);
    }

    #[Test]
    public function a_capability_lost_after_opening_the_page_is_refused_at_the_decision(): void
    {
        [, , $o] = $this->reversedAfterPosting();
        $version = $this->reviewVersion($o);
        $this->inSchool($this->school, fn () => DB::table('membership_role_assignments')->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $this->reviewer->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));

        $this->assertRefused(AuthorizationException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer, $version)));
    }

    #[Test]
    public function acceptance_is_explicit_and_mfa_is_the_reviewers_own(): void
    {
        [, , $o] = $this->reversedAfterPosting();

        $this->assertRefused(ReconciliationAcceptanceRequiredException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer, null, false)));
        $this->assertRefused(LogicException::class, fn () => $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationService::class)
            ->review($this->school, $o->id, $this->reviewer, $this->freshMfaProofFor($this->checker), $this->reviewVersion($o), true)));
    }

    #[Test]
    public function a_stale_a_foreign_or_a_replayed_review_never_lands(): void
    {
        [$october, $november, $o] = $this->reversedAfterPosting();
        $version = $this->reviewVersion($o);

        $this->assertRefused(ReconciliationEvidenceStaleException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer, str_repeat('0', 64))));
        $this->assertNotSame($o->evidence_version, $version, 'the token is bound to this obligation, not only to the evidence');

        $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer, $version));
        $this->assertRefused(ReconciliationAlreadyReviewedException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->checker, $version)));
    }

    #[Test]
    public function a_review_token_of_one_obligation_never_closes_another(): void
    {
        [$october, $november] = $this->octoberAndPostedNovember();
        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->x);
        $this->postRun($december);
        $this->reverseRun($october);
        $a = $this->obligation($november);
        $b = $this->obligation($december);

        $this->assertRefused(ReconciliationEvidenceStaleException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($b, $this->reviewer, $this->reviewVersion($a))));
    }

    #[Test]
    public function nobody_eligible_leaves_it_open_and_blocks_no_other_payroll_work(): void
    {
        [$october, $november, $o] = $this->reversedAfterPosting();

        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->checker);
        $this->postRun($december);
        $this->reverseRun($december);

        $this->assertSame('open', $this->inSchool($this->school, fn () => $this->state($o))['state']);
        // December's independent approval covered the change, and reversing it took that away again: a second obligation.
        $this->assertSame(2, $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationReadService::class)->openCount($this->school, $this->reviewer)));
        $this->assertSame([], $this->inSchool($this->school, fn () => DB::table('payroll_reversal_reconciliation_reviews')->pluck('id')->all()), 'nothing clears it on its own');
    }

    #[Test]
    public function a_later_independent_approval_never_closes_it_but_the_reviewer_is_told(): void
    {
        [$october, $november, $o] = $this->reversedAfterPosting();
        $version = $this->reviewVersion($o);

        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->checker);

        $state = $this->inSchool($this->school, fn () => $this->state($o));
        $this->assertSame('open', $state['state']);
        $this->assertFalse($state['exceptionStillApplies']);
        $this->assertRefused(ReconciliationEvidenceStaleException::class, fn () => $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer, $version)));
        $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer, $state['reviewVersion']));
        $this->assertSame(1, $this->reviews());
    }

    #[Test]
    public function evidence_is_purpose_limited_audited_and_withheld_from_anyone_not_independent(): void
    {
        [, $november, $o] = $this->reversedAfterPosting();

        $basic = $this->inSchool($this->school, fn () => app(PayrollReversalReconciliationReadService::class)->show($this->school, $this->reviewer, $o->id, false));
        $this->assertNull($basic['details'], 'no current MFA, no names');
        $this->assertSame('needs_mfa', $basic['blockedReason']);
        $this->assertCount(0, $this->audit('payroll.run.independence_reviewed', $november));

        $full = $this->inSchool($this->school, fn () => $this->state($o));
        $encoded = (string) json_encode($full);
        $this->assertSame(1, $full['details']['changes']['total']);
        $this->assertStringNotContainsString('55000', $encoded, 'never an amount');
        $this->assertCount(1, $this->audit('payroll.run.independence_reviewed', $november));

        $this->assertNull($this->inSchool($this->school, fn () => $this->state($o, $this->x))['details'], 'not the implicated author');
    }

    #[Test]
    public function no_audit_event_carries_an_amount(): void
    {
        [, $november, $o] = $this->reversedAfterPosting();
        $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer));

        $events = $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->whereIn('event_type', ['payroll.run.reconciliation_opened', 'payroll.run.reconciliation_reviewed'])->get()->map->metadata->all());
        $this->assertCount(2, $events);
        $this->assertStringNotContainsString('55000', (string) json_encode($events));
    }

    private function assertRuntimeRefuses(callable $write, string $because): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        try {
            DB::transaction(fn () => $this->inSchool($this->school, $write));
            $this->fail('the runtime role was allowed to: '.$because);
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/payroll_reversal_reconciliation|permission denied/', $e->getMessage(), $because);
        }
    }

    #[Test]
    public function the_runtime_role_cannot_forge_rewrite_or_delete_reconciliation_evidence(): void
    {
        [$october, $november, $o] = $this->reversedAfterPosting();
        $review = fn (array $over) => fn () => DB::table('payroll_reversal_reconciliation_reviews')->insert($over + ['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'reconciliation_id' => $o->id,
            'evidence_version' => $this->reviewVersion($o), 'statement_code' => 'reversal_impact_reviewed_v1', 'unlinked_employee_count' => 1, 'reviewed_by_user_id' => $this->reviewer->id]);

        $this->assertRuntimeRefuses($review(['reviewed_by_user_id' => $this->x->id]), 'review as the approver');
        $this->assertRuntimeRefuses($review(['reviewed_by_user_id' => $this->finance->id]), 'review as the reverser');
        $this->assertRuntimeRefuses($review(['reviewed_by_user_id' => $this->preparer->id]), 'review as a maker');
        $this->assertRuntimeRefuses($review(['evidence_version' => str_repeat('0', 64)]), 'review stale evidence');
        $this->assertRuntimeRefuses($review(['unlinked_employee_count' => 0]), 'hide an Employee the check could not see');
        $this->assertRuntimeRefuses(fn () => DB::table('payroll_reversal_reconciliations')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_run_id' => $october->id,
            'payroll_run_calculation_id' => $this->runRow($october)['approved_calculation_id'], 'reversal_posting_id' => $o->reversal_posting_id, 'evidence_version' => $o->evidence_version]), 'open one for a run the reversal did not affect');
        $this->assertRuntimeRefuses(fn () => DB::table('payroll_reversal_reconciliations')->where('id', $o->id)->update(['evidence_version' => str_repeat('1', 64)]), 'rewrite an obligation');
        $this->assertRuntimeRefuses(fn () => DB::table('payroll_reversal_reconciliations')->where('id', $o->id)->delete(), 'delete an obligation');

        $this->inSchool($this->school, fn () => $this->reviewAs($o, $this->reviewer));
        $this->assertRuntimeRefuses(fn () => DB::table('payroll_reversal_reconciliation_reviews')->update(['statement_code' => 'x']), 'rewrite a review');
        $this->assertRuntimeRefuses(fn () => DB::table('payroll_reversal_reconciliation_reviews')->delete(), 'delete a review');
    }

    #[Test]
    public function the_api_lists_shows_and_reviews_with_a_fresh_code(): void
    {
        [, $november, $o] = $this->reversedAfterPosting();
        $token = $this->mfaToken($this->reviewer);
        $api = "/api/v1/schools/{$this->school->id}/payroll-reconciliations";

        $this->withToken($token)->getJson($api)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $o->id)->assertJsonPath('data.0.state', 'open');
        $shown = $this->withToken($token)->getJson("{$api}/{$o->id}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $version = $shown->json('data.reviewVersion');
        $this->assertNotNull($version);

        $this->withToken($token)->postJson("{$api}/{$o->id}/review", ['review_version' => $version, 'accepted' => true])->assertStatus(422);
        $this->assertSame(0, $this->reviews(), 'no code, no review');
        $this->withToken($token)->postJson("{$api}/{$o->id}/review", ['mfa_code' => $this->freshMfaCode($this->reviewer), 'review_version' => $version, 'accepted' => false])->assertStatus(422)->assertJsonPath('error.code', 'PAYROLL_RECONCILIATION_ACCEPTANCE_REQUIRED');
        $this->withToken($token)->postJson("{$api}/{$o->id}/review", ['mfa_code' => $this->freshMfaCode($this->reviewer), 'review_version' => $version, 'accepted' => true])->assertCreated()->assertJsonPath('data.reconciliationId', $o->id);
        $this->withToken($token)->postJson("{$api}/{$o->id}/review", ['mfa_code' => $this->freshMfaCode($this->reviewer), 'review_version' => $version, 'accepted' => true])->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_RECONCILIATION_ALREADY_REVIEWED');
        $this->assertSame(1, $this->reviews());
    }

    #[Test]
    public function the_api_refuses_an_implicated_reviewer_and_someone_without_the_keys(): void
    {
        [, , $o] = $this->reversedAfterPosting();
        $api = "/api/v1/schools/{$this->school->id}/payroll-reconciliations/{$o->id}";
        $version = $this->reviewVersion($o);

        $this->withToken($this->mfaToken($this->x))->postJson("{$api}/review", ['mfa_code' => $this->freshMfaCode($this->x), 'review_version' => $version, 'accepted' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_RECONCILIATION_REVIEWER_NOT_INDEPENDENT');
        Auth::forgetGuards();
        $this->withToken($this->mfaToken($this->poster))->postJson("{$api}/review", ['mfa_code' => $this->freshMfaCode($this->poster), 'review_version' => $version, 'accepted' => true])->assertForbidden();
        $this->assertSame(0, $this->reviews());
    }

    #[Test]
    public function another_school_never_reaches_an_obligation(): void
    {
        [, , $o] = $this->reversedAfterPosting();
        $other = $this->createSchool();
        $outsider = $this->createUserWithCapabilities($other, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $token = $this->mfaToken($outsider);
        $api = "/api/v1/schools/{$other->id}/payroll-reconciliations";

        $this->withToken($token)->getJson($api)->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($token)->getJson("{$api}/{$o->id}")->assertNotFound();
        $this->withToken($token)->postJson("{$api}/{$o->id}/review", ['mfa_code' => $this->freshMfaCode($outsider), 'review_version' => str_repeat('a', 64), 'accepted' => true])->assertNotFound();
        $this->assertSame(0, $this->reviews());
    }

    #[Test]
    public function the_workspace_and_its_review_work_in_the_browser_path(): void
    {
        [, $november, $o] = $this->reversedAfterPosting();
        $this->mfaEnrolled($this->reviewer);
        $this->actingAs($this->reviewer)->post("/app/schools/{$this->school->id}/activate");

        $this->actingAs($this->reviewer)->get('/app/payroll/reconciliations')->assertOk()->assertInertia(fn ($p) => $p->component('App/Payroll/Reconciliations/Index')->where('reconciliations.total', 1));
        $this->actingAs($this->reviewer)->get("/app/payroll/runs/{$november->id}")->assertOk()->assertInertia(fn ($p) => $p->where('reconciliations.0.id', $o->id)->where('reconciliations.0.state', 'open'));
        $this->actingAs($this->reviewer)->get('/app/payroll')->assertOk()->assertInertia(fn ($p) => $p->where('openReconciliations', 1));
        $page = $this->actingAs($this->reviewer)->get("/app/payroll/reconciliations/{$o->id}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $page->assertInertia(fn ($p) => $p->component('App/Payroll/Reconciliations/Show')->where('reconciliation.state', 'open')->where('reconciliation.details', null)->where('reconciliation.blockedReason', 'needs_mfa'));

        $this->actingAs($this->reviewer)->post("/app/payroll/reconciliations/{$o->id}/review", ['review_version' => $this->reviewVersion($o), 'accepted' => true, 'mfa_code' => $this->freshMfaCode($this->reviewer)])
            ->assertRedirect("/app/payroll/reconciliations/{$o->id}");
        $this->assertSame(1, $this->reviews());

        $this->actingAs($this->reviewer)->post("/app/payroll/reconciliations/{$o->id}/review", ['review_version' => $this->reviewVersion($o), 'accepted' => true, 'mfa_code' => $this->freshMfaCode($this->reviewer)])
            ->assertSessionHasErrors('payroll');
        $this->assertSame(1, $this->reviews(), 'a replay changes nothing');
    }

    #[Test]
    public function the_browser_path_needs_both_keys_and_an_unrelated_viewer_reads_only_the_chronology(): void
    {
        [, , $o] = $this->reversedAfterPosting();
        $viewer = $this->createUserWithCapabilities($this->school, ['payroll.runs.view']);
        $this->actingAs($viewer)->post("/app/schools/{$this->school->id}/activate");

        $this->actingAs($viewer)->get("/app/payroll/reconciliations/{$o->id}")->assertOk()->assertInertia(fn ($p) => $p->where('reconciliation.details', null)->where('reconciliation.blockedReason', 'cannot_review')->where('reconciliation.reviewVersion', null));
        $this->actingAs($viewer)->post("/app/payroll/reconciliations/{$o->id}/review", ['review_version' => str_repeat('a', 64), 'accepted' => true])->assertForbidden();
        $this->assertSame(0, $this->reviews());
    }
}
