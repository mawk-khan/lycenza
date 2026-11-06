<?php

namespace Tests\Feature\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\Exceptions\InvalidRunTransitionException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestationRequiredException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestorCannotReviewException;
use App\Domain\Payroll\Application\Exceptions\PostingAttestorNotIndependentException;
use App\Domain\Payroll\Application\Exceptions\PostingEvidenceStaleException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingIndependence;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollPostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HR.R1-C4 (PAY-16, OD-HR-22): a posting never silently relies on approval
 * independence that a later reversal took away. Synthetic data only; every
 * date is in 2015.
 */
class PayrollPostingIndependenceTest extends TestCase
{
    use CreatesPayrollRetentionFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    /** @var array<string, mixed> */
    private array $setup;

    private EmploymentRecord $record;

    private User $preparer;

    private User $checker;

    private User $x;

    private User $finance;

    private User $poster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2015-07-01 06:00:00'));
        $this->school = $this->createSchool();
        $this->setup = $this->payrollSetup($this->school);
        $employee = $this->paidEmployee($this->school, $this->setup);
        $this->record = $this->inSchool($this->school, fn () => EmploymentRecord::query()->where('employee_id', $employee->id)->firstOrFail());
        $this->preparer = $this->createUserWithCapabilities($this->school, ['payroll.runs.prepare', 'payroll.runs.view', 'payroll.periods.manage', 'payroll.runs.post', 'payroll.compensation.sensitive.view']);
        $this->checker = $this->createUserWithCapabilities($this->school, ['payroll.runs.approve', 'payroll.runs.view', 'payroll.compensation.sensitive.view']);
        $this->x = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.approve', 'payroll.runs.view', 'payroll.runs.post']);
        $this->finance = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.runs.reverse', 'payroll.runs.view']);
        $this->poster = $this->createUserWithCapabilities($this->school, ['payroll.runs.post', 'payroll.compensation.sensitive.view', 'payroll.runs.view']);
    }

    private function calculatedRun(string $month): PayrollRun
    {
        $this->travelTo(Carbon::parse($month)->addDays(24)->setTime(6, 0));

        return $this->inSchool($this->school, function () use ($month): PayrollRun {
            $periods = app(PayrollPeriodService::class);
            $period = $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $this->preparer), $this->preparer);
            $run = app(PayrollRunService::class)->createRun($period, $this->preparer);
            app(PayrollRunService::class)->calculate($run, $this->preparer);

            return $run->fresh();
        });
    }

    private function approve(PayrollRun $run, User $as): PayrollRun
    {
        return $this->inSchool($this->school, function () use ($run, $as): PayrollRun {
            $runs = app(PayrollRunService::class);
            $fresh = $run->fresh();

            return $runs->approve($fresh, $as, $this->freshMfaProofFor($as), $runs->latestCalculationId($fresh), $runs->latestAttributionVersion($fresh));
        });
    }

    private function postRun(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance)));
    }

    private function reverseRun(PayrollRun $run): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->reverse($run->fresh(), $this->finance, $this->freshMfaProofFor($this->finance), 'test reversal'));
    }

    private function raise(string $effectiveFrom, string $amount, ?User $by = null): EmployeeCompensationAssignment
    {
        $by ??= $this->x;

        return $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, $this->record->fresh(), $this->setup['structure'], Carbon::parse($effectiveFrom),
            [new FixedComponentValueInput($this->setup['line'], $amount)], $by, $this->freshMfaProofFor($by),
        ));
    }

    /** @return array<string, mixed> */
    private function row(PayrollRun $run): array
    {
        return (array) $this->inSchool($this->school, fn () => DB::table('payroll_runs')->where('id', $run->id)->first(['status', 'approved_by_user_id', 'approved_at', 'approved_calculation_id']));
    }

    /** @return list<string> */
    private function compensationAuthors(PayrollRun $run): array
    {
        return $this->inSchool($this->school, fn () => app(PayrollRunService::class)->participants($run->fresh())->all()['compensation_author'] ?? []);
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

    /** The current evidence version, or '' when no exception applies. */
    private function evidence(PayrollRun $run): string
    {
        return (string) $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->version($run->fresh()));
    }

    private function postAs(PayrollRun $run, User $as, ?PostingAttestation $attestation): void
    {
        $this->inSchool($this->school, fn () => app(PayrollPostingService::class)->post($run->fresh(), $as, $this->freshMfaProofFor($as), null, $attestation));
    }

    private function attest(PayrollRun $run, User $as): void
    {
        $this->postAs($run, $as, new PostingAttestation($this->evidence($run), true));
    }

    private function attestations(PayrollRun $run): int
    {
        return $this->inSchool($this->school, fn () => PayrollPostingAttestation::query()->where('payroll_run_id', $run->id)->count());
    }

    private function postings(PayrollRun $run): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $run->id)->where('posting_kind', 'original')->count());
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $type, PayrollRun $run): array
    {
        return $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->where('subject_id', $run->id)->get()->map(fn ($e) => $e->metadata)->all());
    }

    private function assertRefused(PayrollRun $run, string $exception, callable $write): void
    {
        try {
            $write();
            $this->fail("expected {$exception}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }
        $this->assertSame('approved', $this->row($run)['status'], 'the run stays approved');
        $this->assertSame(0, $this->attestations($run));
        $this->assertSame(0, $this->postings($run), 'nothing reached Finance');
    }

    #[Test]
    public function pay_16_a_run_whose_approval_lost_its_independence_never_posts_silently(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();

        $this->assertNotSame('', $this->evidence($november), 'detected before posting');
        $this->assertRefused($november, PostingAttestationRequiredException::class, fn () => $this->postAs($november, $this->poster, null));
    }

    #[Test]
    public function an_unaffected_run_posts_as_it_always_did(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->assertSame('', $this->evidence($october));
        $this->postRun($october);
        $this->assertSame('posted', $this->row($october)['status']);

        // An approval by the author stands, and needs nothing, while its independent predecessor stands.
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->postRun($november);
        $this->assertSame('posted', $this->row($november)['status']);
        $this->assertSame(0, $this->attestations($november));
    }

    #[Test]
    public function a_reversal_before_approval_stays_refused_at_approval_and_is_no_posting_exception(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $this->reverseRun($october);
        $november = $this->calculatedRun('2015-11-01');

        try {
            $this->approve($november, $this->x);
            $this->fail('the author approved the run that first pays the change');
        } catch (SelfApprovalNotAllowedException) {
            $this->assertSame('calculated', $this->row($november)['status']);
        }
        $this->approve($november, $this->checker);
        $this->assertSame('', $this->evidence($november), 'an independent approval leaves nothing to attest');
    }

    #[Test]
    public function an_independent_poster_attests_and_the_history_stays_exactly_as_it_was(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $before = $this->row($november);
        $version = $this->evidence($november);

        $this->attest($november, $this->poster);

        $after = $this->row($november);
        $this->assertSame('posted', $after['status']);
        $this->assertSame([$before['approved_by_user_id'], $before['approved_at'], $before['approved_calculation_id']], [$after['approved_by_user_id'], $after['approved_at'], $after['approved_calculation_id']], 'not a new approval');

        $row = $this->inSchool($this->school, fn () => PayrollPostingAttestation::query()->where('payroll_run_id', $november->id)->firstOrFail());
        $this->assertSame([$before['approved_calculation_id'], $version, $this->poster->id, PayrollPostingAttestation::STATEMENT_INDEPENDENCE_CHANGED],
            [$row->payroll_run_calculation_id, $row->evidence_version, $row->attested_by_user_id, $row->statement_code]);
        $this->assertSame((string) $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $november->id)->value('id')), $row->payroll_run_posting_id);

        $audit = $this->audit('payroll.run.independence_attested', $november);
        $this->assertCount(1, $audit);
        $this->assertSame([$row->id, $version, $this->x->id], [$audit[0]['attestationId'], $audit[0]['evidenceVersion'], $audit[0]['approvedByUserId']]);
        $this->assertStringNotContainsString('55000', json_encode($audit[0]), 'no pay figure in the audit');
    }

    #[Test]
    public function an_implicated_author_never_attests_whatever_keys_they_hold(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();

        $this->assertRefused($november, PostingAttestorNotIndependentException::class, fn () => $this->postAs($november, $this->x, new PostingAttestation($this->evidence($november), true)));
    }

    #[Test]
    public function any_maker_of_the_run_is_refused_even_with_every_key(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();

        $this->assertRefused($november, PostingAttestorNotIndependentException::class, fn () => $this->postAs($november, $this->preparer, new PostingAttestation($this->evidence($november), true)));
    }

    #[Test]
    public function a_second_author_of_what_the_run_now_pays_first_is_refused_too(): void
    {
        $y = $this->createUserWithCapabilities($this->school, ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view', 'payroll.runs.post']);
        $second = $this->paidEmployee($this->school, $this->setup);
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $this->travelTo(Carbon::parse('2015-10-28 06:00:00'));
        $this->inSchool($this->school, fn () => app(CompensationService::class)->assign(
            $this->school, EmploymentRecord::query()->where('employee_id', $second->id)->firstOrFail(), $this->setup['structure'], Carbon::parse('2015-11-01'),
            [new FixedComponentValueInput($this->setup['line'], '61000.00')], $y, $this->freshMfaProofFor($y),
        ));
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->reverseRun($october);

        $this->assertRefused($november, PostingAttestorNotIndependentException::class, fn () => $this->postAs($november, $y, new PostingAttestation($this->evidence($november), true)));
        $this->attest($november, $this->poster);
        $this->assertSame('posted', $this->row($november)['status'], 'someone independent of both authors can');
    }

    #[Test]
    public function a_poster_who_cannot_see_compensation_is_blocked_and_shown_nothing_identifying(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();

        $this->assertRefused($november, PostingAttestorCannotReviewException::class, fn () => $this->postAs($november, $this->finance, new PostingAttestation($this->evidence($november), true)));

        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->finance, false));
        $this->assertSame(['before_posting', 'cannot_review', null, null, null],
            [$review['phase'], $review['blockedReason'], $review['evidenceVersion'], $review['details'], $review['attestation']]);
    }

    #[Test]
    public function acceptance_is_explicit_and_the_evidence_is_checked_on_the_server(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $version = $this->evidence($november);

        $this->assertRefused($november, PostingAttestationRequiredException::class, fn () => $this->postAs($november, $this->poster, new PostingAttestation($version, false)));
        $this->assertRefused($november, PostingEvidenceStaleException::class, fn () => $this->postAs($november, $this->poster, new PostingAttestation(str_repeat('0', 64), true)));
    }

    #[Test]
    public function the_review_is_purpose_limited_and_names_the_reversal(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();

        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->poster, true));

        $this->assertSame([null, $this->evidence($november)], [$review['blockedReason'], $review['evidenceVersion']]);
        $this->assertSame(1, $review['details']['changes']['total']);
        $this->assertSame(['key', 'employeeNumber', 'employee', 'effectiveFrom', 'structure'], array_keys($review['details']['changes']['data'][0]));
        $this->assertSame('2015-10', $review['details']['reversedRuns'][0]['periodMonth']);
        $this->assertStringNotContainsString('55000', json_encode($review));
    }

    #[Test]
    public function a_reversal_after_posting_is_not_blocked_and_is_recorded_for_reconciliation(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $this->postRun($november);
        $before = $this->row($november);

        $this->reverseRun($october);

        $this->assertSame($before, $this->row($november), 'a posted run is never reopened');
        $this->assertSame(0, $this->attestations($november));
        $audit = $this->audit('payroll.run.independence_changed', $november);
        $this->assertCount(1, $audit);
        $this->assertSame([$october->id, 'posted'], [$audit[0]['reversedRunId'], $audit[0]['status']]);
        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->poster, true));
        $this->assertSame('after_posting', $review['phase'], 'shown on the run');
        $this->assertNull($review['evidenceVersion'], 'nothing can be attested after posting');
    }

    #[Test]
    public function reading_the_evidence_is_audited_and_a_refused_reader_leaves_no_read_event(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $version = $this->evidence($november);

        $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->finance, false));
        $this->assertSame([], $this->audit('payroll.run.independence_reviewed', $november));

        $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->poster, true, 2));
        $audit = $this->audit('payroll.run.independence_reviewed', $november);
        $this->assertSame([[$version, 2]], array_map(fn ($a) => [$a['evidenceVersion'], $a['page']], $audit));
    }

    #[Test]
    public function an_attestation_covers_only_its_evidence_and_a_later_clearing_keeps_it_on_the_page(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $this->attest($november, $this->poster);
        $review = fn () => $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->poster, true));
        $this->assertSame('attested', $review()['phase']);

        // A later independent approval of the same change ends the exception, never the record of the attestation.
        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->checker);
        $this->assertSame('', $this->evidence($november));
        $this->assertSame(['attested', true], [$review()['phase'], $review()['attestation'] !== null]);

        // Reversing December reopens it, with new evidence the attestation never covered.
        $this->postRun($december);
        $this->reverseRun($december);
        $this->assertNotSame('', $this->evidence($november));
        $this->assertSame('after_posting', $review()['phase'], 'the exposure is shown, not hidden by the old attestation');
        $this->assertNotNull($review()['attestation'], 'and the attestation stays on record');
    }

    #[Test]
    public function a_reversal_that_discharged_nothing_is_never_named(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $this->assertCount(1, $this->audit('payroll.run.independence_changed', $november));

        // A run the author himself approved never discharged the change, so reversing it took nothing away.
        $this->attest($november, $this->poster);
        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->checker);
        $january = $this->calculatedRun('2016-01-01');
        $this->approve($january, $this->x);
        $this->postRun($january);
        $this->reverseRun($january);

        $this->assertCount(1, $this->audit('payroll.run.independence_changed', $november), 'one event, naming October');
        $review = $this->inSchool($this->school, fn () => app(PayrollPostingIndependence::class)->review($november->fresh(), $this->poster, true));
        $this->assertSame('attested', $review['phase'], 'reversing a run that discharged nothing changes no evidence');
    }

    #[Test]
    public function a_reversal_before_posting_is_recorded_too_and_only_affected_runs_are(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();

        $this->assertSame('approved', $this->audit('payroll.run.independence_changed', $november)[0]['status']);
    }

    #[Test]
    public function a_posted_run_cannot_be_posted_or_attested_twice(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $version = $this->evidence($november);
        $this->attest($november, $this->poster);

        try {
            $this->postAs($november, $this->poster, new PostingAttestation($version, true));
            $this->fail('posted twice');
        } catch (InvalidRunTransitionException) {
            $this->assertSame(1, $this->attestations($november));
            $this->assertSame(1, $this->postings($november));
        }
    }

    #[Test]
    public function an_unauthorized_actor_is_refused_before_any_of_this(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $nobody = $this->createUserWithCapabilities($this->school, [PayrollPostingIndependence::SENSITIVE_VIEW]);

        $this->assertRefused($november, AuthorizationException::class, fn () => $this->postAs($november, $nobody, new PostingAttestation($this->evidence($november), true)));
    }

    #[Test]
    public function a_correction_run_is_never_an_exception(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $this->attest($november, $this->poster);
        $correction = $this->inSchool($this->school, function () use ($november): PayrollRun {
            $runs = app(PayrollRunService::class);
            $correction = $runs->createCorrectionRun($november->fresh(), $november->fresh()->period, $this->preparer);
            $runs->recordCorrectionDelta($correction, $this->record->fresh(), [new CorrectionDeltaInput((string) DB::table('salary_components')->where('code', 'BASIC')->value('id'), '500.00', 'increase')], 'Missed allowance', $this->preparer);
            $runs->calculate($correction->fresh(), $this->preparer);

            return $correction->fresh();
        });
        $this->approve($correction, $this->checker);

        $this->assertSame('', $this->evidence($correction));
        $this->postRun($correction);
        $this->assertSame('posted', $this->row($correction)['status']);
    }

    /** A raw write, as the runtime role, through a savepoint so a refusal can be asserted. */
    private function assertRuntimeRefuses(callable $write, string $because): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        try {
            DB::transaction(fn () => $this->inSchool($this->school, $write));
            $this->fail('the runtime role was allowed to: '.$because);
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/payroll_runs_posting_independence_guard|payroll_posting_attestations_guard|permission denied/', $e->getMessage(), $because);
        }
    }

    #[Test]
    public function the_runtime_role_cannot_post_an_affected_run_or_forge_its_attestation(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $version = $this->evidence($november);
        $calculation = $this->row($november)['approved_calculation_id'];

        $this->assertRuntimeRefuses(fn () => DB::table('payroll_runs')->where('id', $november->id)->update(['status' => 'posted', 'posted_by_user_id' => $this->poster->id, 'posted_at' => now()]), 'post without an attestation');

        $forge = fn (array $overrides) => fn () => DB::table('payroll_posting_attestations')->insert($overrides + ['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'payroll_run_id' => $november->id,
            'payroll_run_calculation_id' => $calculation, 'payroll_run_posting_id' => (string) DB::table('payroll_run_postings')->value('id'), 'evidence_version' => $version, 'statement_code' => 'independence_changed_v1', 'attested_by_user_id' => $this->poster->id]);
        $this->assertRuntimeRefuses($forge([]), 'attest with no posting of its own');
        $this->assertRuntimeRefuses($forge(['evidence_version' => str_repeat('0', 64)]), 'attest stale evidence');
        $this->assertRuntimeRefuses($forge(['attested_by_user_id' => $this->x->id]), 'attest as the author');
        $this->assertSame('approved', $this->row($november)['status']);
    }

    #[Test]
    public function a_committed_attestation_cannot_be_changed_or_removed_or_reused(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $this->attest($november, $this->poster);
        $id = (string) $this->inSchool($this->school, fn () => DB::table('payroll_posting_attestations')->where('payroll_run_id', $november->id)->value('id'));

        $this->assertRuntimeRefuses(fn () => DB::table('payroll_posting_attestations')->where('id', $id)->update(['attested_by_user_id' => $this->x->id]), 'change the actor');
        $this->assertRuntimeRefuses(fn () => DB::table('payroll_posting_attestations')->where('id', $id)->update(['evidence_version' => str_repeat('0', 64)]), 'change the evidence');
        $this->assertRuntimeRefuses(fn () => DB::table('payroll_posting_attestations')->where('id', $id)->delete(), 'remove it');
        $this->assertSame(1, $this->attestations($november));
    }

    #[Test]
    public function an_attestation_from_one_run_never_satisfies_another(): void
    {
        $this->travelTo(Carbon::parse('2015-09-28 06:00:00'));
        $this->raise('2015-10-01', '55000.00');
        $october = $this->calculatedRun('2015-10-01');
        $this->approve($october, $this->checker);
        $this->postRun($october);
        $november = $this->calculatedRun('2015-11-01');
        $this->approve($november, $this->x);
        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->x);
        $this->reverseRun($october);

        $this->assertNotSame($this->evidence($november), $this->evidence($december), 'each exception has its own evidence');
        $this->assertRefused($december, PostingEvidenceStaleException::class, fn () => $this->postAs($december, $this->poster, new PostingAttestation($this->evidence($november), true)));
    }

    #[Test]
    public function a_later_independent_approval_of_the_change_clears_the_exception(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $december = $this->calculatedRun('2015-12-01');
        $this->approve($december, $this->checker);

        $this->assertSame('', $this->evidence($november), 'the change has a standing independent approval again');
        $this->postRun($november);
        $this->assertSame('posted', $this->row($november)['status']);
        $this->assertSame(0, $this->attestations($november));
    }

    #[Test]
    public function the_api_shows_the_evidence_and_requires_the_explicit_acceptance(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $version = $this->evidence($november);
        $token = $this->mfaToken($this->poster);
        $api = "/api/v1/schools/{$this->school->id}/payroll-runs/{$november->id}";

        $this->withToken($token)->getJson("{$api}/posting-independence")->assertOk()->assertJsonPath('data.phase', 'before_posting')->assertJsonPath('data.evidenceVersion', $version)
            ->assertHeader('Cache-Control', 'no-store, private');

        $body = fn () => ['mfa_code' => $this->freshMfaCode($this->poster)];
        $this->withToken($token)->withHeader('Idempotency-Key', 'hr-r1-c4-a')->postJson("{$api}/post", $body())->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_POSTING_ATTESTATION_REQUIRED');
        $this->withToken($token)->withHeader('Idempotency-Key', 'hr-r1-c4-b')->postJson("{$api}/post", $body() + ['attestation_version' => $version])->assertStatus(422);
        $this->withToken($token)->withHeader('Idempotency-Key', 'hr-r1-c4-c')->postJson("{$api}/post", $body() + ['attestation_version' => $version, 'attestation_accepted' => false])->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_POSTING_ATTESTATION_REQUIRED');
        $this->assertSame('approved', $this->row($november)['status']);

        $this->withToken($token)->withHeader('Idempotency-Key', 'hr-r1-c4-d')->postJson("{$api}/post", $body() + ['attestation_version' => $version, 'attestation_accepted' => true])->assertCreated();
        $this->assertSame('posted', $this->row($november)['status']);
        $this->assertSame(1, $this->attestations($november));
    }

    #[Test]
    public function another_school_never_reaches_the_evidence_or_the_posting(): void
    {
        $november = $this->novemberApprovedByItsAuthorThenOctoberReversed();
        $other = $this->createSchool();
        $outsider = $this->createUserWithCapabilities($other, ['payroll.runs.post', PayrollPostingIndependence::SENSITIVE_VIEW]);
        $token = $this->mfaToken($outsider);

        $this->withToken($token)->getJson("/api/v1/schools/{$other->id}/payroll-runs/{$november->id}/posting-independence")->assertNotFound();
        $this->withToken($token)->withHeader('Idempotency-Key', 'hr-r1-c4-x')->postJson("/api/v1/schools/{$other->id}/payroll-runs/{$november->id}/post", ['mfa_code' => $this->freshMfaCode($outsider), 'attestation_version' => str_repeat('a', 64), 'attestation_accepted' => true])->assertNotFound();
        $this->assertSame('approved', $this->row($november)['status']);
    }
}
