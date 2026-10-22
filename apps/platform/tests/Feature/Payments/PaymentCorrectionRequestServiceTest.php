<?php

namespace Tests\Feature\Payments;

use App\Domain\Guardians\Application\Exceptions\GuardianSelfAdministrationException;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRequestRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRequestStaleException;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionRequestService;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionRequest;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolNotOperationalException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * ORG.8C (ADR 0076 §61): the Payment correction request workflow below the
 * controller. A reallocation, reversal or recorded refund is requested, then
 * approved or rejected by someone else; approval executes it at once through
 * PaymentCorrectionService. Owner decision OPTION 1: nobody linked as a
 * Guardian of ANY Student the Payment or the request touches -- through any
 * Guardian identity their account was ever linked to, active or revoked --
 * approves or rejects it, for every kind. The decider's authority is read
 * fresh at decision time, a request executes only against the Payment
 * generation it was made on, and nothing is ever approved without its
 * correction.
 */
class PaymentCorrectionRequestServiceTest extends TestCase
{
    use CreatesReceiptFixtures;

    private const array ALL = ['finance.payments.view', 'finance.payment_corrections.view', 'finance.payment_corrections.request', 'finance.payment_corrections.approve'];

    private function requests(): PaymentCorrectionRequestService
    {
        return app(PaymentCorrectionRequestService::class);
    }

    /** @return array<string, mixed> the receipt world plus a requester, an approver and a second Student charge */
    private function world(): array
    {
        $w = $this->concessionWorld();
        $w['requester'] = $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payment_corrections.view', 'finance.payment_corrections.request']);
        $w['approver'] = $this->createUserWithCapabilities($w['school'], self::ALL);
        $w['other'] = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Transport');

        return $w;
    }

    private function data(array $w, string $paymentId, string $kind, array $overrides = []): PaymentCorrectionData
    {
        return new PaymentCorrectionData(...[
            'paymentId' => $paymentId,
            'kind' => $kind,
            'reason' => 'Entered against the wrong charge',
            'idempotencyKey' => (string) Str::uuid(),
            'chargeId' => $kind === PaymentCorrectionData::REVERSAL ? null : $w['charge']->id,
            'toChargeId' => $kind === PaymentCorrectionData::REALLOCATION ? $w['other']->id : null,
            'amount' => $kind === PaymentCorrectionData::REVERSAL ? null : Money::of('50.00', 'INR'),
            'accountId' => $kind === PaymentCorrectionData::REFUND ? $w['settlement']->id : null,
            'method' => $kind === PaymentCorrectionData::REFUND ? ManualPaymentMethod::Cash : null,
            'refundedOn' => $kind === PaymentCorrectionData::REFUND ? $this->today($w) : null,
            ...$overrides,
        ]);
    }

    private function request(array $w, string $kind, ?User $actor = null, ?string $paymentId = null, array $overrides = []): PaymentCorrectionRequest
    {
        // Small payments: many fit on the world's one 1000.00 charge.
        $paymentId ??= $this->pay($w, '100.00')->paymentId;

        return $this->requests()->request($w['school'], $this->data($w, $paymentId, $kind, $overrides), $actor ?? $w['requester'])['request'];
    }

    /** Links $user's account (their membership) to Guardian identity $guardian. */
    private function linkAccount(array $w, User $user, Guardian $guardian): void
    {
        $membership = $this->inSchool($w['school'], fn () => SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $w['school']->id)->firstOrFail());
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->linkGuardian($w['school'], $guardian, $membership, $w['recorder']));
    }

    /** @return array{0: User, 1: Guardian} an approver who is a Guardian of $student (default: the world's Student) */
    private function guardianApprover(array $w, $student = null): array
    {
        $approver = $this->createUserWithCapabilities($w['school'], [...self::ALL, 'students.manage', 'guardians.manage']);
        $guardian = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($student ?? $w['student'], $guardian);
        $this->linkAccount($w, $approver, $guardian);

        return [$approver, $guardian];
    }

    private function corrections(array $w): int
    {
        return $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count());
    }

    private function assertConflicted(array $w, User $decider, PaymentCorrectionRequest $request): void
    {
        $before = $this->corrections($w);
        foreach (['approve', 'reject'] as $decision) {
            try {
                $this->requests()->{$decision}($w['school'], $request->id, $decider);
                $this->fail("A Guardian of an affected Student must not {$decision} a {$request->kind}.");
            } catch (PaymentCorrectionRequestRefusedException $e) {
                $this->assertSame([403, 'PAYMENT_CORRECTION_DECISION_CONFLICT'], [$e->getStatusCode(), $e->errorCode()]);
                $this->assertStringNotContainsStringIgnoringCase('guardian', $e->getMessage(), 'no relationship oracle');
            }
        }
        $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($w['school'], fn () => $request->refresh()->status));
        $this->assertSame($before, $this->corrections($w), 'no correction');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'like', 'payment_correction_request.%')->where('event_type', '!=', 'payment_correction_request.requested')->count()));
    }

    /** @return array<string, mixed> */
    private function lastAudit(array $w, string $event): array
    {
        return $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', $event)->latest('id')->firstOrFail()->metadata);
    }

    #[Test]
    public function each_kind_is_requested_then_executed_by_another_persons_approval(): void
    {
        $w = $this->world();

        foreach ([PaymentCorrectionData::REALLOCATION, PaymentCorrectionData::REFUND, PaymentCorrectionData::REVERSAL] as $kind) {
            $request = $this->request($w, $kind);
            $this->assertSame([PaymentCorrectionRequest::PENDING, 0], [$request->status, $request->prior_correction_count]);
            $this->assertNull(PaymentCorrection::query()->where('idempotency_key', $request->id)->first(), 'requesting moves nothing');

            $approved = $this->requests()->approve($w['school'], $request->id, $w['approver']);
            $correction = $this->inSchool($w['school'], fn () => PaymentCorrection::query()->findOrFail($approved->payment_correction_id));

            $this->assertSame([PaymentCorrectionRequest::APPROVED, $w['approver']->id], [$approved->status, $approved->decided_by_user_id]);
            $this->assertSame([$kind, $request->payment_id, $request->id, $w['approver']->id, $request->reason],
                [$correction->kind, $correction->payment_id, $correction->idempotency_key, $correction->corrected_by_user_id, $correction->reason]);
            $this->assertNotNull($correction->journal_entry_id, 'the canonical executor posted its own journal entry');
        }
    }

    #[Test]
    public function a_guardian_of_the_student_neither_approves_nor_rejects_any_kind(): void
    {
        $w = $this->world();
        [$approver] = $this->guardianApprover($w);

        foreach ([PaymentCorrectionData::REALLOCATION, PaymentCorrectionData::REVERSAL, PaymentCorrectionData::REFUND] as $kind) {
            $this->assertConflicted($w, $approver, $this->request($w, $kind));
        }
    }

    #[Test]
    public function a_revoked_account_link_and_any_of_several_guardians_still_conflict(): void
    {
        $w = $this->world();
        [$approver, $guardian] = $this->guardianApprover($w);
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->unlinkGuardian($w['school'], $guardian, $w['recorder']));
        $this->linkAccount($w, $approver, $this->createGuardian($w['school']));

        // Revoked link to the Student's Guardian + an active link to an unrelated one: still conflicted.
        $this->assertConflicted($w, $approver, $this->request($w, PaymentCorrectionData::REFUND));

        // A Student with two Guardians: the approver linked to the second one only is conflicted too.
        $other = $this->createUserWithCapabilities($w['school'], self::ALL);
        $second = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($w['student'], $second);
        $this->linkAccount($w, $other, $second);
        $this->assertConflicted($w, $other, $this->request($w, PaymentCorrectionData::REVERSAL));
    }

    #[Test]
    public function a_guardian_of_any_student_the_payment_touches_is_conflicted_even_when_the_request_names_another(): void
    {
        $w = $this->world();
        $sibling = $this->createStudent($w['school']);
        $siblingCharge = $this->assessCharge($w['school'], $sibling, $w['year'], $w['receivable'], $w['revenue'], '300.00', description: 'Sibling tuition');
        // One family payment across two Students' charges.
        $paymentId = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '200.00'], [$siblingCharge, '100.00']], '300.00')->paymentId;
        [$approver] = $this->guardianApprover($w, $sibling);

        // The refund names the first Student's charge only; the Payment also touches the sibling.
        $this->assertConflicted($w, $approver, $this->request($w, PaymentCorrectionData::REFUND, paymentId: $paymentId));
        $this->assertConflicted($w, $approver, $this->request($w, PaymentCorrectionData::REVERSAL, paymentId: $paymentId));
        $this->assertSame(
            collect([$w['student']->id, $sibling->id])->sort()->values()->all(),
            collect($this->requests()->affectedStudentIds($w['school'], $this->request($w, PaymentCorrectionData::REALLOCATION, paymentId: $paymentId)))->sort()->values()->all(),
        );
    }

    #[Test]
    public function the_guardian_of_an_unrelated_student_decides_and_a_guardian_may_request(): void
    {
        $w = $this->world();
        [$unrelated] = $this->guardianApprover($w, $this->createStudent($w['school']));
        $this->assertSame(PaymentCorrectionRequest::APPROVED, $this->requests()->approve($w['school'], $this->request($w, PaymentCorrectionData::REVERSAL)->id, $unrelated)->status);

        [$guardianRequester] = $this->guardianApprover($w);
        $request = $this->request($w, PaymentCorrectionData::REFUND, $guardianRequester);
        $this->assertSame(PaymentCorrectionRequest::PENDING, $request->status, 'requesting is not a decision');
        $this->assertSame(PaymentCorrectionRequest::REJECTED, $this->requests()->reject($w['school'], $request->id, $w['approver'])->status);
    }

    #[Test]
    public function the_guardian_cannot_unlink_decide_and_relink(): void
    {
        $w = $this->world();
        [$approver, $guardian] = $this->guardianApprover($w);
        $request = $this->request($w, PaymentCorrectionData::REVERSAL);

        $this->expectException(GuardianSelfAdministrationException::class);
        try {
            app(StudentGuardianRelationshipService::class)->unlink(
                $this->inSchool($w['school'], fn () => StudentGuardianRelationship::query()->where('guardian_id', $guardian->id)->firstOrFail()),
                $approver,
            );
        } finally {
            $this->assertConflicted($w, $approver, $request);
        }
    }

    #[Test]
    public function the_requester_never_decides_and_only_the_requester_withdraws(): void
    {
        $w = $this->world();
        $request = $this->request($w, PaymentCorrectionData::REVERSAL, $w['approver']);

        foreach (['approve', 'reject'] as $decision) {
            try {
                $this->requests()->{$decision}($w['school'], $request->id, $w['approver']);
                $this->fail('The requester never decides their own request.');
            } catch (PaymentCorrectionRequestRefusedException $e) {
                $this->assertSame('PAYMENT_CORRECTION_SELF_DECISION', $e->errorCode());
            }
        }
        try {
            $this->requests()->withdraw($w['school'], $request->id, $this->createUserWithCapabilities($w['school'], self::ALL));
            $this->fail('Only the requester withdraws.');
        } catch (PaymentCorrectionRequestRefusedException $e) {
            $this->assertSame('PAYMENT_CORRECTION_NOT_REQUESTER', $e->errorCode());
        }

        $withdrawn = $this->requests()->withdraw($w['school'], $request->id, $w['approver']);
        $this->assertSame([PaymentCorrectionRequest::WITHDRAWN, $w['approver']->id], [$withdrawn->status, $withdrawn->withdrawn_by_user_id]);
        $this->assertNull($withdrawn->decided_by_user_id);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->expectException(PaymentCorrectionRequestRefusedException::class);
        $this->requests()->approve($w['school'], $request->id, $w['approver']);
    }

    #[Test]
    public function authority_is_read_fresh_at_decision_time(): void
    {
        $w = $this->world();
        $refused = function (callable $decision, string $exception) use ($w): void {
            $request = $this->request($w, PaymentCorrectionData::REVERSAL);
            try {
                $decision($request);
                $this->fail("Expected {$exception}.");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e);
            }
            $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($w['school'], fn () => $request->refresh()->status));
        };

        // Without the approve capability at all (a requester), below the controller.
        $refused(fn ($r) => $this->requests()->approve($w['school'], $r->id, $w['requester']), AuthorizationException::class);

        // A grant revoked behind the warm 60 s cache.
        $revoked = $this->createUserWithCapabilities($w['school'], self::ALL);
        $this->assertTrue(Gate::forUser($revoked)->allows('capability', [PaymentCorrectionRequestService::APPROVE, $w['school']]));
        $this->inSchool($w['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $revoked->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $refused(fn ($r) => $this->requests()->approve($w['school'], $r->id, $revoked), AuthorizationException::class);

        // A disabled User (stale model).
        DB::table('users')->where('id', $w['approver']->id)->update(['is_disabled' => true]);
        $refused(fn ($r) => $this->requests()->reject($w['school'], $r->id, $w['approver']), AuthorizationException::class);
        DB::table('users')->where('id', $w['approver']->id)->update(['is_disabled' => false]);
        $w['approver'] = $w['approver']->fresh();

        // The Fees module disabled behind the cache.
        $this->assertTrue(Gate::forUser($w['approver'])->allows('capability', [PaymentCorrectionRequestService::APPROVE, $w['school']]));
        $pending = $this->request($w, PaymentCorrectionData::REVERSAL);
        $this->inSchool($w['school'], fn () => DB::table('school_module_entitlements')->where('school_id', $w['school']->id)->where('module_key', 'fees')->update(['enabled' => false]));
        try {
            $this->requests()->approve($w['school'], $pending->id, $w['approver']);
            $this->fail('A disabled module refuses the decision.');
        } catch (AuthorizationException) {
            $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($w['school'], fn () => $pending->refresh()->status));
        }
        $this->inSchool($w['school'], fn () => DB::table('school_module_entitlements')->where('school_id', $w['school']->id)->where('module_key', 'fees')->update(['enabled' => true]));

        // A suspended School.
        DB::table('schools')->where('id', $w['school']->id)->update(['status' => 'suspended']);
        $this->expectException(SchoolNotOperationalException::class);
        $this->requests()->approve($w['school'], $pending->id, $w['approver']);
    }

    #[Test]
    public function a_request_executes_only_against_the_payment_generation_it_was_made_on(): void
    {
        $w = $this->world();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $first = $this->request($w, PaymentCorrectionData::REFUND, paymentId: $paymentId);
        $second = $this->request($w, PaymentCorrectionData::REALLOCATION, paymentId: $paymentId);
        $third = $this->request($w, PaymentCorrectionData::REFUND, paymentId: $paymentId, overrides: ['amount' => Money::of('50.00', 'INR')]);

        $this->requests()->approve($w['school'], $first->id, $w['approver']);
        foreach ([$second, $third] as $stale) {
            try {
                $this->requests()->approve($w['school'], $stale->id, $w['approver']);
                $this->fail('A request made before another correction executed is stale.');
            } catch (PaymentCorrectionRequestStaleException $e) {
                $this->assertSame([409, 'PAYMENT_CORRECTION_REQUEST_STALE'], [$e->getStatusCode(), $e->errorCode()]);
            }
            $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($w['school'], fn () => $stale->refresh()->status));
        }
        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->where('payment_id', $paymentId)->count()));

        // A requester who still saw the uncorrected Payment is refused; one who saw it corrected binds it.
        try {
            $this->requests()->request($w['school'], $this->data($w, $paymentId, PaymentCorrectionData::REVERSAL), $w['requester'], seenCorrections: 0);
            $this->fail('A request never binds a Payment state its requester did not see.');
        } catch (PaymentCorrectionRequestStaleException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $this->requests()->request($w['school'], $this->data($w, $paymentId, PaymentCorrectionData::REVERSAL), $w['requester'], seenCorrections: 1)['request']->prior_correction_count);

        // A request made after it carries the new generation and executes.
        $fresh = $this->request($w, PaymentCorrectionData::REFUND, paymentId: $paymentId, overrides: ['amount' => Money::of('50.00', 'INR')]);
        $this->assertSame(1, $fresh->prior_correction_count);
        $this->assertSame(PaymentCorrectionRequest::APPROVED, $this->requests()->approve($w['school'], $fresh->id, $w['approver'])->status);

        // A stale request can still be rejected (rejecting moves nothing).
        $this->assertSame(PaymentCorrectionRequest::REJECTED, $this->requests()->reject($w['school'], $second->id, $w['approver'])->status);
    }

    #[Test]
    public function an_executor_refusal_rolls_the_whole_approval_back(): void
    {
        $w = $this->world();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $request = $this->request($w, PaymentCorrectionData::REFUND, paymentId: $paymentId, overrides: ['amount' => Money::of('600.00', 'INR')]);

        try {
            $this->requests()->approve($w['school'], $request->id, $w['approver']);
            $this->fail('The executor refuses a refund above what the Payment has on the charge.');
        } catch (PaymentCorrectionRefusedException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([PaymentCorrectionRequest::PENDING, null, null], $this->inSchool($w['school'], fn () => [$request->refresh()->status, $request->decided_by_user_id, $request->payment_correction_id]));
        $this->assertSame(0, $this->corrections($w));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'payment_correction_request.approved')->count()));
    }

    #[Test]
    public function a_request_is_idempotent_per_requester_and_terms(): void
    {
        $w = $this->world();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $data = $this->data($w, $paymentId, PaymentCorrectionData::REVERSAL);

        $first = $this->requests()->request($w['school'], $data, $w['requester']);
        $again = $this->requests()->request($w['school'], $data, $w['requester']);
        $this->assertSame([false, true, $first['request']->id], [$first['replayed'], $again['replayed'], $again['request']->id]);

        $refund = $this->data($w, $paymentId, PaymentCorrectionData::REFUND, ['reference' => 'CHQ-1']);
        $this->requests()->request($w['school'], $refund, $w['requester']);
        foreach ([
            [$data, $w['approver']],
            [$this->data($w, $paymentId, PaymentCorrectionData::REVERSAL, ['idempotencyKey' => $data->idempotencyKey, 'reason' => 'A different reason entirely']), $w['requester']],
            // A corrected refund reference is a different request, never a silent replay.
            [$this->data($w, $paymentId, PaymentCorrectionData::REFUND, ['idempotencyKey' => $refund->idempotencyKey, 'reference' => 'CHQ-2']), $w['requester']],
        ] as [$conflicting, $actor]) {
            try {
                $this->requests()->request($w['school'], $conflicting, $actor);
                $this->fail('The same key with another requester or other terms fails closed.');
            } catch (PaymentCorrectionRequestRefusedException $e) {
                $this->assertSame('PAYMENT_CORRECTION_REQUEST_KEY_CONFLICT', $e->errorCode());
            }
        }
        $this->assertSame(2, $this->inSchool($w['school'], fn () => PaymentCorrectionRequest::query()->count()));

        // Another School reusing the literal key is its own request.
        $other = $this->world();
        $this->requests()->request($other['school'], $this->data($other, $this->pay($other, '100.00')->paymentId, PaymentCorrectionData::REVERSAL, ['idempotencyKey' => $data->idempotencyKey]), $other['requester']);
        $this->assertSame(1, $this->inSchool($other['school'], fn () => PaymentCorrectionRequest::query()->count()));
    }

    #[Test]
    public function a_bad_or_unauthorized_request_writes_nothing(): void
    {
        $w = $this->world();
        $paymentId = $this->pay($w, '500.00')->paymentId;

        foreach ([
            [fn () => $this->requests()->request($w['school'], $this->data($w, $paymentId, PaymentCorrectionData::REVERSAL), $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.ledger.reverse'])), AuthorizationException::class],
            [fn () => $this->requests()->request($w['school'], $this->data($w, $paymentId, PaymentCorrectionData::REVERSAL, ['reason' => 'no']), $w['requester']), PaymentCorrectionRefusedException::class],
            [fn () => $this->requests()->request($w['school'], $this->data($w, $paymentId, PaymentCorrectionData::REFUND, ['method' => null]), $w['requester']), PaymentCorrectionRefusedException::class],
            [fn () => $this->requests()->request($w['school'], $this->data($w, $paymentId, PaymentCorrectionData::REFUND, ['amount' => Money::of('0.00', 'INR')]), $w['requester']), PaymentCorrectionRefusedException::class],
        ] as [$attempt, $exception]) {
            try {
                $attempt();
                $this->fail("Expected {$exception}.");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e);
            }
        }
        $this->assertSame(0, $this->inSchool($w['school'], fn () => PaymentCorrectionRequest::query()->count()));
    }

    #[Test]
    public function the_audit_events_carry_approval_provenance_and_nothing_sensitive(): void
    {
        $w = $this->world();
        $approved = $this->request($w, PaymentCorrectionData::REFUND, overrides: ['amount' => Money::of('43.21', 'INR'), 'reference' => 'NEFT-SECRET']);
        $this->requests()->approve($w['school'], $approved->id, $w['approver'], new FreshMfaProof($w['approver']->id, CarbonImmutable::now()));
        $rejected = $this->request($w, PaymentCorrectionData::REVERSAL);
        $this->requests()->reject($w['school'], $rejected->id, $w['approver']);
        $withdrawn = $this->request($w, PaymentCorrectionData::REVERSAL);
        $this->requests()->withdraw($w['school'], $withdrawn->id, $w['requester']);

        $requested = $this->lastAudit($w, 'payment_correction_request.requested');
        $this->assertSame(['payment_corrections', 'requested', ['requester' => [$w['requester']->id]]],
            [$requested['approvalWorkflow'], $requested['approvalDecision'], $requested['participants']]);
        $approval = $this->lastAudit($w, 'payment_correction_request.approved');
        $this->assertSame([$approved->id, 'approved', $w['approver']->id, 'school', true, 'refund'],
            [$approval['paymentCorrectionRequestId'], $approval['approvalDecision'], $approval['decidedByUserId'], $approval['decisionScope'], $approval['freshMfa'], $approval['kind']]);
        $this->assertNotNull($approval['paymentCorrectionId']);
        $rejection = $this->lastAudit($w, 'payment_correction_request.rejected');
        $this->assertSame(['rejected', false], [$rejection['approvalDecision'], $rejection['freshMfa']]);
        $withdrawal = $this->lastAudit($w, 'payment_correction_request.withdrawn');
        $this->assertSame($withdrawn->id, $withdrawal['paymentCorrectionRequestId']);

        foreach ([$requested, $approval, $rejection, $withdrawal] as $metadata) {
            $encoded = (string) json_encode($metadata);
            foreach (['43.21', 'NEFT-SECRET', 'Entered against the wrong charge', $w['student']->id, $w['charge']->id] as $secret) {
                $this->assertStringNotContainsString($secret, $encoded, 'no amount, reference, reason, Student or charge');
            }
        }
    }

    #[Test]
    public function the_keys_stay_whole_school_and_the_executor_has_one_caller(): void
    {
        foreach ([PaymentCorrectionRequestService::VIEW, PaymentCorrectionRequestService::REQUEST, PaymentCorrectionRequestService::APPROVE] as $key) {
            $this->assertFalse(CampusScopeCatalogue::isAdopted($key), "{$key} is not Campus-adopted");
        }

        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), strlen(app_path()) + 1);
            if (str_contains($source, '->executeApproved(')) {
                $callers['executeApproved'][] = $relative;
            }
            if (preg_match('/use App\\\\Domain\\\\Payments\\\\Application\\\\PaymentCorrectionService;|PaymentCorrectionService \$/', $source) === 1) {
                $callers['service'][] = $relative;
            }
        }
        $correctionRoutes = collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'correction') && str_contains($route->uri(), 'payment'));

        // ORG.8C: the one product path to a correction is an approved request.
        $this->assertSame(['Domain/Payments/Application/PaymentCorrectionRequestService.php'], $callers['executeApproved']);
        $this->assertSame(['Domain/Payments/Application/PaymentCorrectionRequestService.php'], $callers['service'], 'no controller, job or command holds the correction core');
        $this->assertNotEmpty($correctionRoutes);
        foreach ($correctionRoutes as $route) {
            $this->assertStringStartsWith('App\Http\Controllers\App\Finance\PaymentCorrectionRequestController@', $route->getActionName(), "{$route->uri()} goes through the request workflow");
        }
        $this->assertFileDoesNotExist(app_path('Http/Controllers/App/Finance/PaymentController.php'), 'the direct-correction controller is gone');
        $this->assertSame(PaymentCorrectionService::class, (new \ReflectionMethod(PaymentCorrectionRequestService::class, '__construct'))->getParameters()[0]->getType()?->getName());
    }
}
