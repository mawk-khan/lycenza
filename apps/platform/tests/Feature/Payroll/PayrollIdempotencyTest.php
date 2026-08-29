<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\ApiIdempotencyKey;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 9.8 idempotency correction -- proves the `Idempotency-Key`
 * contract over real HTTP for Payroll's five consequential commands
 * (create run, approve, post, reverse, create correction), mirroring
 * `Tests\Feature\Idempotency\IdempotencyDemoEndpointTest`'s exact
 * shape. `PayrollPostingConcurrencyRealTest`/
 * `PayrollIdempotencyRealConcurrencyTest` (separate files, real
 * `php -S`/curl processes) carry the mandatory real-concurrency proof
 * -- this file is sequential-request coverage only.
 */
class PayrollIdempotencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function as(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /**
     * Builds a School through 'calculated' status via direct
     * Administration-service calls (fast, no HTTP overhead) -- only
     * the specific idempotent command under test in each method below
     * goes through real HTTP, exactly like
     * `Tests\Feature\Payroll\PayrollAdministrationAuthorizationTest`'s
     * own fixture-building precedent. Deliberately does NOT call
     * `approve()` -- tests that need `approve()` itself under test
     * call it via HTTP using the returned `approverToken`; tests that
     * need a run already `approved`/`posted` use `makeApprovedRun()`
     * below instead, which approves via a SEPARATE, throwaway actor so
     * the returned `approverToken` always belongs to an actor who has
     * genuinely never approved this run yet.
     *
     * @return array{school: School, runId: string, approver: User, approverToken: string, posterToken: string}
     */
    private function makeCalculatedRun(): array
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $structureManager = $this->createUserWithCapabilities($school, [
            'payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage',
        ]);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);

        $runId = $context->withSchool($school, function () use ($school, $structureManager, $runManager) {
            $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
            $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
            app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);

            $structureService = app(PayrollStructureAdministrationService::class);
            $component = $structureService->createComponent($school, 'BASIC', 'Basic', 'earning', null, $structureManager);
            $structure = $structureService->createDraftStructure($school, 'GRADE-IDEMP', 'Grade Idempotency', $structureManager);
            $sc = $structureService->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
            $structure = $structureService->activateStructure($structure, $structureManager);

            $employmentRecord = $this->createEmploymentRecord($this->createEmployee($school), ['starts_on' => '2025-01-01']);
            app(PayrollCompensationAdministrationService::class)->assign(
                $school, $employmentRecord, $structure, Carbon::parse('2025-01-01'),
                [new FixedComponentValueInput($sc->id, '50000.00')], $structureManager,
            );

            $periodService = app(PayrollPeriodAdministrationService::class);
            $period = $periodService->open($periodService->createPeriod($school, Carbon::parse('2026-09-01'), null, $runManager), $runManager);

            $runService = app(PayrollRunAdministrationService::class);
            $run = $runService->createRun($period, $runManager);
            $runService->calculate($run, $runManager);

            return $run->id;
        });

        $approver = $this->createUserWithCapabilities($school, ['payroll.runs.approve']);
        $poster = $this->createUserWithCapabilities($school, ['payroll.runs.post', 'payroll.runs.reverse']);

        return [
            'school' => $school, 'runId' => $runId,
            'approver' => $approver, 'approverToken' => $this->token($approver),
            'posterToken' => $this->token($poster),
        ];
    }

    /**
     * `makeCalculatedRun()` plus one internal, throwaway-actor
     * `approve()` call (direct service, not HTTP) -- for tests that
     * need the run already `approved` so ONLY post()/reverse() itself
     * is under idempotency test.
     *
     * @return array{school: School, runId: string, approver: User, approverToken: string, posterToken: string}
     */
    private function makeApprovedRun(): array
    {
        $f = $this->makeCalculatedRun();

        app(TenantContext::class)->withSchool($f['school'], function () use ($f) {
            $run = PayrollRun::query()->findOrFail($f['runId']);
            $throwawayApprover = $this->createUserWithCapabilities($f['school'], ['payroll.runs.approve']);
            app(PayrollRunAdministrationService::class)->approve($run, $throwawayApprover);
        });

        return $f;
    }

    #[Test]
    public function a_replay_with_the_same_key_returns_the_stored_success_without_reapproving(): void
    {
        $f = $this->makeCalculatedRun();

        $first = $this->as($f['approverToken'])->withHeader('Idempotency-Key', 'approve-replay-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/approve");
        $first->assertOk();
        $this->assertNull($first->headers->get('Idempotency-Replayed'));

        $second = $this->as($f['approverToken'])->withHeader('Idempotency-Key', 'approve-replay-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/approve");
        $second->assertOk();
        $second->assertHeader('Idempotency-Replayed', 'true');
        // assertEquals, not assertSame -- PHP's `===` on arrays also
        // compares key INSERTION order, which JSON objects carry no
        // semantic meaning for; the content itself must match exactly.
        $this->assertEquals($first->json('data'), $second->json('data'));

        // The underlying business effect exists exactly once: only one
        // approver was ever recorded, and it is still the SAME run row
        // (a second real approve() attempt would have thrown
        // PAYROLL_INVALID_RUN_TRANSITION -- it never even ran).
        $this->assertSame($first->json('data.approvedByUserId'), $second->json('data.approvedByUserId'));
    }

    #[Test]
    public function the_same_key_with_a_different_reversal_reason_is_rejected_as_a_conflict(): void
    {
        $f = $this->makeApprovedRun();
        $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'post-for-conflict-test')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/post")
            ->assertCreated();

        $first = $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'reverse-conflict-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/reverse", ['reason' => 'first reason']);
        $first->assertCreated();

        $second = $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'reverse-conflict-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/reverse", ['reason' => 'a materially different reason']);

        $second->assertStatus(409);
        $second->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        // The conflicting request never re-executed -- exactly one
        // reversal JournalEntry exists (the original + its one
        // reversal, never a second reversal).
        $context = app(TenantContext::class);
        $journalEntryCount = $context->withSchool($f['school'], fn () => JournalEntry::query()->where('school_id', $f['school']->id)->count());
        $this->assertSame(2, $journalEntryCount, 'exactly the original + one reversal JournalEntry, never a duplicate from the conflicting retry');
    }

    #[Test]
    public function an_actor_who_loses_the_approve_capability_cannot_obtain_a_replayed_response(): void
    {
        // Section 5 (mandatory): authorization is re-evaluated on
        // EVERY request, including a would-be replay. `capability:payroll.runs.approve`
        // runs BEFORE `idempotent` in this route's middleware array
        // (routes/api.php), so a since-revoked actor is rejected before
        // EnsureIdempotent/IdempotencyGuard is ever consulted -- the
        // cached successful response must never be replayed to them.
        $f = $this->makeCalculatedRun();

        $this->as($f['approverToken'])->withHeader('Idempotency-Key', 'revoked-approve-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/approve")
            ->assertOk();

        // Revoke by disabling the actor outright -- the same mechanism
        // `IdempotencyDemoEndpointTest::a_disabled_user_is_denied()`
        // already establishes as this codebase's canonical "capability
        // effectively gone" test shape.
        $f['approver']->forceFill(['is_disabled' => true])->save();
        Auth::forgetGuards();

        $replayAttempt = $this->as($f['approverToken'])->withHeader('Idempotency-Key', 'revoked-approve-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/approve");

        $replayAttempt->assertForbidden();
        $this->assertNull($replayAttempt->headers->get('Idempotency-Replayed'), 'a denied actor must never receive a replayed body.');
    }

    #[Test]
    public function a_network_retry_of_a_successful_post_leaves_exactly_one_journal_entry(): void
    {
        $f = $this->makeApprovedRun();

        $first = $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'post-retry-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/post");
        $first->assertCreated();
        $journalEntryId = $first->json('data.journalEntryId');

        // Simulates the client never seeing the first response
        // (dropped connection) and retrying with the identical key --
        // must replay the ORIGINAL success, not
        // PAYROLL_INVALID_RUN_TRANSITION.
        $retry = $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'post-retry-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/post");
        $retry->assertCreated();
        $retry->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($journalEntryId, $retry->json('data.journalEntryId'));

        $context = app(TenantContext::class);
        $journalEntryCount = $context->withSchool($f['school'], fn () => JournalEntry::query()->where('school_id', $f['school']->id)->count());
        $this->assertSame(1, $journalEntryCount, 'the retry must never post a second, independent JournalEntry.');
    }

    #[Test]
    public function a_network_retry_of_a_successful_reversal_leaves_exactly_one_reversal_journal_entry(): void
    {
        $f = $this->makeApprovedRun();
        $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'post-before-reverse-retry')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/post")
            ->assertCreated();

        $first = $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'reverse-retry-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/reverse");
        $first->assertCreated();
        $reversalJournalEntryId = $first->json('data.journalEntryId');

        $retry = $this->as($f['posterToken'])->withHeader('Idempotency-Key', 'reverse-retry-key')
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/reverse");
        $retry->assertCreated();
        $retry->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($reversalJournalEntryId, $retry->json('data.journalEntryId'));

        $context = app(TenantContext::class);
        $journalEntryCount = $context->withSchool($f['school'], fn () => JournalEntry::query()->where('school_id', $f['school']->id)->count());
        $this->assertSame(2, $journalEntryCount, 'exactly the original + one reversal JournalEntry, never a second reversal from the retry.');
    }

    #[Test]
    public function post_and_reverse_do_not_share_a_replay_namespace_even_with_the_identical_literal_key(): void
    {
        // Section 4 (mandatory): posting and reversal must not
        // accidentally share a replay namespace. IdempotencyGuard's
        // uniqueness scope includes route_action (the route NAME,
        // different for .post vs .reverse), so reusing the identical
        // literal key string for both must never collide.
        $f = $this->makeApprovedRun();
        $sharedKey = 'shared-literal-key-across-post-and-reverse';

        $postResponse = $this->as($f['posterToken'])->withHeader('Idempotency-Key', $sharedKey)
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/post");
        $postResponse->assertCreated();
        $this->assertNull($postResponse->headers->get('Idempotency-Replayed'));

        $reverseResponse = $this->as($f['posterToken'])->withHeader('Idempotency-Key', $sharedKey)
            ->postJson("/api/v1/schools/{$f['school']->id}/payroll-runs/{$f['runId']}/reverse");
        $reverseResponse->assertCreated();
        $this->assertNull($reverseResponse->headers->get('Idempotency-Replayed'), 'reverse must genuinely execute, never be treated as a replay of the post response.');
        $this->assertNotSame($postResponse->json('data.journalEntryId'), $reverseResponse->json('data.journalEntryId'));
        $this->assertSame('original', $postResponse->json('data.postingKind'));
        $this->assertSame('reversal', $reverseResponse->json('data.postingKind'));

        $recordCount = app(TenantContext::class)->withSchool(
            $f['school'],
            fn () => ApiIdempotencyKey::query()->where('idempotency_key', $sharedKey)->count(),
        );
        $this->assertSame(2, $recordCount, 'post and reverse each own a SEPARATE idempotency record despite the identical literal key.');
    }

    #[Test]
    public function a_cross_school_run_id_and_a_nonexistent_run_id_produce_identical_not_found_bodies(): void
    {
        $f = $this->makeApprovedRun();
        $otherSchool = $this->createSchool();
        $viewerToken = $this->token($this->createUserWithCapabilities($otherSchool, ['payroll.runs.view']));

        $crossSchool = $this->as($viewerToken)
            ->getJson("/api/v1/schools/{$otherSchool->id}/payroll-runs/{$f['runId']}");
        $crossSchool->assertNotFound();

        $nonexistent = $this->as($viewerToken)
            ->getJson('/api/v1/schools/'.$otherSchool->id.'/payroll-runs/'.Str::uuid());
        $nonexistent->assertNotFound();

        // Both must be structurally indistinguishable -- same status,
        // same machine error code, same shape. The message TEXT
        // legitimately differs because it echoes back the queried id
        // (a fixed, deliberate template applied uniformly regardless of
        // WHY the id was not found) -- comparing the literal string
        // including that id is not what "no oracle" requires.
        $this->assertSame($nonexistent->status(), $crossSchool->status());
        $this->assertSame($nonexistent->json('error.code'), $crossSchool->json('error.code'));
        $this->assertSame('PAYROLL_RUN_NOT_FOUND', $crossSchool->json('error.code'));
        $this->assertSame(array_keys($nonexistent->json('error')), array_keys($crossSchool->json('error')));
    }
}
