<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\AdjustmentExceedsOutstandingException;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeHasActiveAdjustmentsException;
use App\Domain\Fees\Application\Exceptions\ConcessionDecisionConflictException;
use App\Domain\Fees\Application\Exceptions\FeeAdjustmentAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\FeeConcessionIllegalTransitionException;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Guardians\Application\Exceptions\GuardianSelfAdministrationException;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Models\School;
use App\Models\SchoolMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * FEE.3 (ADR 0062 §12 proof 6, §14, §15): real two-process races against
 * real PostgreSQL with forced, verified overlap (the contender is observed
 * blocked on the holder's uncommitted work before the holder commits).
 * Never SQLite, never a sequential simulation. The charge is 1000.00.
 *
 * 1. A payment and a concession competing for the final capacity, in both
 *    orders -- the second is refused, never reduced.
 * 2. Two approvals of the same concession -> one decision, one adjustment.
 * 3. Two concessions on the same charge competing for the final capacity.
 * 4. Revocation vs. a standing application, in both orders; two
 *    cancellations of one adjustment -> one reversal.
 * 5. Charge cancellation vs. an approval, in both orders.
 * 6. ORG.8A (ADR 0076 §58): approval vs. rejection and approval vs. the
 *    requester's withdrawal, in both orders -> one terminal decision, and an
 *    adjustment only when the approval won; a revocation of the approver's
 *    grants committed first -> the waiting approval re-reads current
 *    authority and is refused.
 * 7. ORG.8A-R1 (ADR 0076 §59): an independent administrator's unlink of a
 *    Guardian approver's relationship, held open, leaves the committed
 *    relationship in force (the approver is still refused); once it commits
 *    the current-relationship contract applies. The approver's own unlink is
 *    refused outright, so no race of theirs can lift the conflict.
 */
class FeeConcessionConcurrencyTest extends TestCase
{
    use CreatesFeeConcessionFixtures, ForcesConcurrentOverlap;

    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        // Committed data: fixture actors' `test.capability_grant.*` roles
        // outlive the School (the FeeAssessmentConcurrencyTest precedent).
        DB::connection('pgsql_admin')->table('roles')
            ->where('key', 'like', 'test.capability_grant.%')
            ->whereIn('id', $this->ownedRoleIds())
            ->delete();

        parent::tearDown();
    }

    private function world(): array
    {
        $w = $this->concessionWorld();
        $this->school = $w['school'];
        Queue::fake();

        return $w;
    }

    private function script(string $name): string
    {
        return __DIR__."/../../Support/{$name}.php";
    }

    /** @return list<string> */
    private function concession(array $w, string ...$args): array
    {
        return ['php', $this->script('race-fee-concession'), $args[0], $w['school']->id, ...array_slice($args, 1)];
    }

    /** @return list<string> */
    private function payment(array $w, string $amount): array
    {
        return ['php', $this->script('race-manual-payment'), 'record', $w['school']->id, $w['recorder']->id, $w['settlement']->id, (string) Str::uuid(), $amount, "{$w['charge']->id}:{$amount}"];
    }

    private function allocated(array $w): int
    {
        return $this->inSchool($w['school'], fn () => PaymentAllocation::query()->where('charge_id', $w['charge']->id)->count());
    }

    #[Test]
    public function a_payment_holding_the_charge_makes_a_competing_concession_fail_closed(): void
    {
        $w = $this->world();
        $concession = $this->requestTargeted($w, '300.00');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->payment($w, '800.00'),
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
        );

        $this->assertStringStartsWith('recorded:', $holder);
        $this->assertSame('rejected:'.AdjustmentExceedsOutstandingException::class, $contender);
        $this->assertCount(0, $this->adjustmentsOf($w));
        $this->assertSame(FeeConcession::STATUS_PENDING, $this->inSchool($w['school'], fn () => $concession->refresh()->status), 'The refused approval rolled back.');
    }

    #[Test]
    public function a_concession_holding_the_charge_makes_a_competing_payment_fail_closed(): void
    {
        $w = $this->world();
        $concession = $this->requestTargeted($w, '300.00');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
            $this->payment($w, '800.00'),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('error:'.ChargeAllocationExceedsChargeAmountException::class, $contender);
        $this->assertSame(0, $this->allocated($w));
        $this->assertSame('300.00', $this->adjustmentsOf($w)->sole()->amount);
    }

    #[Test]
    public function two_approvals_of_one_concession_make_one_decision_and_one_adjustment(): void
    {
        $w = $this->world();
        $second = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.approve']);
        $concession = $this->requestTargeted($w, '100.00');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
            $this->concession($w, 'approve', $second->id, $concession->id),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:'.FeeConcessionIllegalTransitionException::class, $contender);
        $this->assertCount(1, $this->adjustmentsOf($w));
        $this->assertSame($w['checker']->id, $this->inSchool($w['school'], fn () => $concession->refresh()->decided_by_user_id));
    }

    #[Test]
    public function two_concessions_competing_for_the_final_capacity_have_one_winner(): void
    {
        $w = $this->world();
        $first = $this->requestTargeted($w, '600.00');
        $second = $this->requestTargeted($w, '600.00');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'approve', $w['checker']->id, $first->id),
            $this->concession($w, 'approve', $w['checker']->id, $second->id),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:'.AdjustmentExceedsOutstandingException::class, $contender);
        $this->assertSame([$first->id], $this->adjustmentsOf($w)->pluck('fee_concession_id')->all());
        $this->assertSame(FeeConcession::STATUS_PENDING, $this->inSchool($w['school'], fn () => $second->refresh()->status));
    }

    #[Test]
    public function a_revocation_committed_first_stops_a_racing_standing_application(): void
    {
        $w = $this->world();
        $enrollment = $this->enroll($w);
        $standing = $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_FIXED, '100.00');
        $run = $this->previewedRun($w);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $item = $this->items($w, $run)->sole();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'revoke', $w['checker']->id, $standing->id),
            $this->concession($w, 'execute-item', $run->id, $item->id),
        );

        $this->assertSame('revoked', $holder);
        $this->assertSame('succeeded', $contender, 'The item waited on the concession, then found it revoked.');
        $this->assertCount(0, $this->adjustmentsOf($w));
    }

    #[Test]
    public function a_standing_application_committed_first_is_kept_when_the_revocation_follows(): void
    {
        $w = $this->world();
        $enrollment = $this->enroll($w);
        $standing = $this->approvedStanding($w, $enrollment->student_id, FeeConcession::KIND_FIXED, '100.00');
        $run = $this->previewedRun($w);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);
        $item = $this->items($w, $run)->sole();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'execute-item', $run->id, $item->id),
            $this->concession($w, 'revoke', $w['checker']->id, $standing->id),
        );

        $this->assertSame('succeeded', $holder);
        $this->assertSame('revoked', $contender);
        $this->assertCount(1, $this->adjustmentsOf($w)->whereNull('cancelled_at'), 'Revocation stops future application only.');
    }

    #[Test]
    public function two_cancellations_of_one_adjustment_make_one_reversal(): void
    {
        $w = $this->world();
        $concession = $this->requestTargeted($w, '100.00');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        $adjustment = $this->adjustmentsOf($w)->sole();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'cancel-adjustment', $w['checker']->id, $adjustment->id),
            $this->concession($w, 'cancel-adjustment', $w['checker']->id, $adjustment->id),
        );

        $this->assertSame('cancelled', $holder);
        $this->assertSame('rejected:'.FeeAdjustmentAlreadyCancelledException::class, $contender);
        $this->assertNotNull($this->adjustmentsOf($w)->sole()->cancellation_journal_entry_id);
    }

    #[Test]
    public function a_charge_cancellation_committed_first_refuses_a_racing_approval(): void
    {
        $w = $this->world();
        $concession = $this->requestTargeted($w, '100.00');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'cancel-charge', $w['charge']->id),
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
        );

        $this->assertSame('cancelled', $holder);
        $this->assertSame('rejected:'.ChargeAlreadyCancelledException::class, $contender);
        $this->assertCount(0, $this->adjustmentsOf($w));
        $this->assertSame(FeeConcession::STATUS_PENDING, $this->inSchool($w['school'], fn () => $concession->refresh()->status));
    }

    #[Test]
    public function an_approval_committed_first_refuses_a_racing_charge_cancellation(): void
    {
        $w = $this->world();
        $concession = $this->requestTargeted($w, '100.00');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
            $this->concession($w, 'cancel-charge', $w['charge']->id),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:'.ChargeHasActiveAdjustmentsException::class, $contender);
        $this->assertNull($this->inSchool($w['school'], fn () => $w['charge']->refresh()->cancelled_at));
    }

    /** @return array{0: array, 1: FeeConcession} */
    private function pending(): array
    {
        $w = $this->world();

        return [$w, $this->requestTargeted($w, '100.00')];
    }

    private function statusOf(array $w, FeeConcession $concession): string
    {
        return $this->inSchool($w['school'], fn () => $concession->refresh()->status);
    }

    #[Test]
    public function an_approval_committed_first_makes_a_racing_rejection_a_conflict(): void
    {
        [$w, $concession] = $this->pending();
        $second = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.approve']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
            $this->concession($w, 'reject', $second->id, $concession->id),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:'.FeeConcessionIllegalTransitionException::class, $contender);
        $this->assertSame(FeeConcession::STATUS_APPROVED, $this->statusOf($w, $concession));
        $this->assertCount(1, $this->adjustmentsOf($w));
    }

    #[Test]
    public function a_rejection_committed_first_makes_a_racing_approval_a_conflict_with_no_adjustment(): void
    {
        [$w, $concession] = $this->pending();
        $second = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.approve']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'reject', $second->id, $concession->id),
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
        );

        $this->assertSame('rejected', $holder);
        $this->assertSame('rejected:'.FeeConcessionIllegalTransitionException::class, $contender);
        $this->assertSame(FeeConcession::STATUS_REJECTED, $this->statusOf($w, $concession));
        $this->assertCount(0, $this->adjustmentsOf($w));
    }

    #[Test]
    public function a_withdrawal_committed_first_makes_a_racing_approval_a_conflict(): void
    {
        [$w, $concession] = $this->pending();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'withdraw', $w['maker']->id, $concession->id),
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
        );

        $this->assertSame('withdrawn', $holder);
        $this->assertSame('rejected:'.FeeConcessionIllegalTransitionException::class, $contender);
        $this->assertCount(0, $this->adjustmentsOf($w));
    }

    #[Test]
    public function an_approval_committed_first_makes_a_racing_withdrawal_a_conflict(): void
    {
        [$w, $concession] = $this->pending();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
            $this->concession($w, 'withdraw', $w['maker']->id, $concession->id),
        );

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:'.FeeConcessionIllegalTransitionException::class, $contender);
        $this->assertCount(1, $this->adjustmentsOf($w));
    }

    #[Test]
    public function a_revocation_of_the_approvers_grants_committed_first_refuses_the_waiting_approval(): void
    {
        [$w, $concession] = $this->pending();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->concession($w, 'revoke-grants', $w['checker']->id),
            $this->concession($w, 'approve', $w['checker']->id, $concession->id),
        );

        $this->assertNotSame('0', $holder, 'the approver held a grant to revoke');
        $this->assertSame('rejected:'.AuthorizationException::class, $contender, 'current authority, read after the revocation committed');
        $this->assertSame(FeeConcession::STATUS_PENDING, $this->statusOf($w, $concession));
        $this->assertCount(0, $this->adjustmentsOf($w));
    }

    private function runOp(array $command, array $env = []): string
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(120);
        $process->run();

        return $process->getOutput();
    }

    #[Test]
    public function an_independent_unlink_held_open_still_conflicts_and_once_committed_the_current_contract_applies(): void
    {
        [$w, $concession] = $this->pending();
        $approver = $this->createUserWithCapabilities($w['school'], ['finance.fee_concessions.approve', 'students.manage', 'guardians.manage']);
        $admin = $this->createUserWithCapabilities($w['school'], ['students.manage', 'guardians.manage']);
        $guardian = $this->createGuardian($w['school']);
        $relationship = $this->createStudentGuardianRelationship($w['student'], $guardian);
        $membership = $this->inSchool($w['school'], fn () => SchoolMembership::query()->where('user_id', $approver->id)->where('school_id', $w['school']->id)->firstOrFail());
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->linkGuardian($w['school'], $guardian, $membership, $w['recorder']));

        $this->assertSame('rejected:'.GuardianSelfAdministrationException::class, $this->runOp($this->concession($w, 'unlink-relationship', $approver->id, $relationship->id)), 'the approver cannot lift their own conflict');

        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $holder = new Process($this->concession($w, 'unlink-relationship', $admin->id, $relationship->id), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(120);
        $holder->start();
        $deadline = microtime(true) + 90;
        while (! file_exists($dir.'/acted')) {
            $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'the unlink reached its held delete: '.$holder->getOutput().$holder->getErrorOutput());
            usleep(2_000);
        }

        $during = $this->runOp($this->concession($w, 'approve', $approver->id, $concession->id));
        touch($dir.'/release');
        $holder->wait();

        $this->assertSame('rejected:'.ConcessionDecisionConflictException::class, $during, 'an uncommitted unlink never reads as "not related"');
        $this->assertSame('unlinked', $holder->getOutput());
        $this->assertSame('approved', $this->runOp($this->concession($w, 'approve', $approver->id, $concession->id)), 'after a legitimate unlink commits, the current relationship decides');
        $this->assertCount(1, $this->adjustmentsOf($w));
    }
}
