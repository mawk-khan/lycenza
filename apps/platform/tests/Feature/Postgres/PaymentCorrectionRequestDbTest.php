<?php

namespace Tests\Feature\Postgres;

use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionRequestService;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrectionRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * ORG.8C (ADR 0076 §61): as the real runtime role, the database repeats the
 * Payment correction request rules on the RECORDED columns (it has no actor
 * context): the requester never decides (`payment_correction_requests_sod_check`);
 * an approval names the correction it executed, keyed by the request, on
 * the Payment generation it was requested on (`..._approval_guard`); a
 * recorded decider linked as a Guardian of any Student the Payment or the
 * request touches is refused (`..._beneficiary_guardian_guard`); terms are
 * immutable, a decision is final, a request is born pending and never
 * deleted; the installed objects are pinned.
 */
class PaymentCorrectionRequestDbTest extends TestCase
{
    use CreatesReceiptFixtures;

    private function runtime(School $school): Connection
    {
        $db = DB::connection('pgsql');
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $school->id]);

        return $db;
    }

    /** A raw runtime statement in a savepoint: the rows changed, or the refusal message. */
    private function raw(School $school, string $sql, array $bindings): int|string
    {
        $db = $this->runtime($school);
        try {
            return $db->transaction(function () use ($db, $sql, $bindings) {
                throw new \RuntimeException((string) $db->update($sql, $bindings));
            });
        } catch (QueryException $e) {
            return $e->getMessage();
        } catch (\RuntimeException $e) {
            return (int) $e->getMessage();
        }
    }

    private function decide(School $school, PaymentCorrectionRequest $request, string $status, string $deciderId, ?string $correctionId = null): int|string
    {
        return $this->raw($school, 'update payment_correction_requests set status = ?, decided_by_user_id = ?, decided_at = now(), payment_correction_id = ? where id = ?',
            [$status, $deciderId, $correctionId, $request->id]);
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->concessionWorld();
        $w['requester'] = $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payment_corrections.view', 'finance.payment_corrections.request']);
        // The trusted core (no request) stands in for "a correction exists".
        $w['core'] = $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.ledger.reverse']);

        return $w;
    }

    private function reversalRequest(array $w, string $paymentId): PaymentCorrectionRequest
    {
        return app(PaymentCorrectionRequestService::class)->request($w['school'], new PaymentCorrectionData(
            paymentId: $paymentId, kind: PaymentCorrectionData::REVERSAL, reason: 'Recorded twice by mistake', idempotencyKey: (string) Str::uuid(),
        ), $w['requester'])['request'];
    }

    /** Executes a reversal through the trusted core with $key; returns the correction id. */
    private function reverse(array $w, string $paymentId, string $key): string
    {
        return app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $paymentId, kind: PaymentCorrectionData::REVERSAL, reason: 'Recorded twice by mistake', idempotencyKey: $key,
        ), $w['core'])['correctionId'];
    }

    private function refund(array $w, string $paymentId, string $key): string
    {
        return app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $paymentId, kind: PaymentCorrectionData::REFUND, reason: 'Paid back in part', idempotencyKey: $key,
            chargeId: $w['charge']->id, amount: Money::of('10.00', 'INR'), accountId: $w['settlement']->id,
            method: ManualPaymentMethod::Cash, refundedOn: $this->today($w),
        ), $w['core'])['correctionId'];
    }

    #[Test]
    public function the_database_refuses_self_decision_and_an_unbound_or_stale_approval(): void
    {
        $w = $this->world();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');
        $outsider = $this->createUser();

        // The requester never decides.
        $paymentId = $this->pay($w, '100.00')->paymentId;
        $request = $this->reversalRequest($w, $paymentId);
        $this->assertStringContainsString('payment_correction_requests_sod_check', (string) $this->decide($w['school'], $request, 'rejected', $w['requester']->id));

        // An approval naming a correction it did not execute (another key) is refused.
        $unrelated = $this->refund($w, $this->pay($w, '100.00')->paymentId, (string) Str::uuid());
        $this->assertStringContainsString('names no correction it executed', (string) $this->decide($w['school'], $request, 'approved', $outsider->id, $unrelated));

        // A correction keyed by the request, but executed after ANOTHER correction of the Payment: stale.
        $stalePayment = $this->pay($w, '100.00')->paymentId;
        $stale = $this->reversalRequest($w, $stalePayment);
        $this->refund($w, $stalePayment, (string) Str::uuid());
        $executed = $this->reverse($w, $stalePayment, $stale->id);
        $this->assertStringContainsString('is stale', (string) $this->decide($w['school'], $stale, 'approved', $outsider->id, $executed));

        // Bound and current: a non-requester's approval stands; then it is final.
        $bound = $this->reverse($w, $paymentId, $request->id);
        $this->assertSame(1, $this->decide($w['school'], $request, 'approved', $outsider->id, $bound));
        $db = $this->runtime($w['school']);
        $db->update("update payment_correction_requests set status = 'approved', decided_by_user_id = ?, decided_at = now(), payment_correction_id = ? where id = ?", [$outsider->id, $bound, $request->id]);
        $this->assertStringContainsString('is approved and final', (string) $this->decide($w['school'], $request, 'rejected', $this->createUser()->id));
    }

    #[Test]
    public function a_recorded_decider_linked_as_a_guardian_of_any_affected_student_is_refused(): void
    {
        $w = $this->world();
        $sibling = $this->createStudent($w['school']);
        $siblingCharge = $this->assessCharge($w['school'], $sibling, $w['year'], $w['receivable'], $w['revenue'], '300.00', description: 'Sibling tuition');
        $paymentId = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['charge'], '60.00'], [$siblingCharge, '40.00']], '100.00')->paymentId;

        // A decider whose account was linked -- now revoked -- to the SIBLING's Guardian.
        $decider = $this->createUserWithCapabilities($w['school'], ['finance.payment_corrections.approve']);
        $guardian = $this->createGuardian($w['school']);
        $this->createStudentGuardianRelationship($sibling, $guardian);
        $membership = $this->inSchool($w['school'], fn () => SchoolMembership::query()->where('user_id', $decider->id)->where('school_id', $w['school']->id)->firstOrFail());
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->linkGuardian($w['school'], $guardian, $membership, $w['recorder']));
        $this->inSchool($w['school'], fn () => app(AccountLinkService::class)->unlinkGuardian($w['school'], $guardian, $w['recorder']));

        $request = $this->reversalRequest($w, $paymentId);
        $this->assertStringContainsString('must be decided by someone else', (string) $this->decide($w['school'], $request, 'rejected', $decider->id));
        $executed = $this->reverse($w, $paymentId, $request->id);
        $this->assertStringContainsString('must be decided by someone else', (string) $this->decide($w['school'], $request, 'approved', $decider->id, $executed));

        $this->assertSame(1, $this->decide($w['school'], $request, 'approved', $this->createUser()->id, $executed), 'an unrelated decider stands');
        $this->assertEqualsCanonicalizing([$w['student']->id, $sibling->id], array_map(
            fn ($r) => $r->s,
            $this->runtime($w['school'])->select('select s from payment_correction_request_student_ids(?, ?) s', [$w['school']->id, $request->id]),
        ));
    }

    #[Test]
    public function terms_are_immutable_a_request_is_born_pending_and_never_deleted(): void
    {
        $w = $this->world();
        $request = $this->reversalRequest($w, $this->pay($w, '100.00')->paymentId);
        $other = $this->createUser();

        foreach ([
            'reason' => 'A rewritten reason', 'requested_by_user_id' => $other->id, 'prior_correction_count' => 7,
            'payment_id' => $this->pay($w, '100.00')->paymentId, 'idempotency_key' => (string) Str::uuid(),
        ] as $column => $value) {
            $this->assertStringContainsString('is immutable', (string) $this->raw($w['school'], "update payment_correction_requests set {$column} = ? where id = ?", [$value, $request->id]), $column);
        }
        $this->assertStringContainsString('always requested as pending', (string) $this->raw($w['school'],
            "insert into payment_correction_requests (id, school_id, payment_id, kind, currency, reason, prior_correction_count, status, requested_by_user_id, decided_by_user_id, decided_at, withdrawn_at, idempotency_key, created_at, updated_at)
             values (?, ?, ?, 'reversal', 'INR', 'Born decided raw', 0, 'withdrawn', ?, null, null, now(), ?, now(), now())",
            [(string) Str::uuid(), $w['school']->id, $request->payment_id, $w['requester']->id, (string) Str::uuid()]));
        $this->assertStringContainsString('reason_check', (string) $this->raw($w['school'],
            "insert into payment_correction_requests (id, school_id, payment_id, kind, currency, reason, prior_correction_count, status, requested_by_user_id, idempotency_key, created_at, updated_at)
             values (?, ?, ?, 'reversal', 'INR', 'no', 0, 'pending', ?, ?, now(), now())",
            [(string) Str::uuid(), $w['school']->id, $request->payment_id, $w['requester']->id, (string) Str::uuid()]));
        $this->assertStringContainsString('permission denied', (string) $this->raw($w['school'], 'delete from payment_correction_requests where id = ?', [$request->id]));

        // Only the requester withdraws (recorded withdrawer), and a withdrawal records one.
        $this->assertStringContainsString('withdrawer_check', (string) $this->raw($w['school'], "update payment_correction_requests set status = 'withdrawn', withdrawn_at = now(), withdrawn_by_user_id = ? where id = ?", [$this->createUser()->id, $request->id]));
        $this->assertStringContainsString('status_shape_check', (string) $this->raw($w['school'], "update payment_correction_requests set status = 'withdrawn', withdrawn_at = now() where id = ?", [$request->id]));
        $this->assertSame(1, $this->raw($w['school'], "update payment_correction_requests set status = 'withdrawn', withdrawn_at = now(), withdrawn_by_user_id = ? where id = ?", [$w['requester']->id, $request->id]));
    }

    #[Test]
    public function the_installed_objects_are_pinned_and_another_school_sees_nothing(): void
    {
        $w = $this->world();
        $request = $this->reversalRequest($w, $this->pay($w, '100.00')->paymentId);

        foreach (['payment_correction_requests_guard', 'payment_correction_requests_bind_approval', 'payment_correction_request_student_ids', 'payment_correction_requests_refuse_beneficiary_guardian'] as $function) {
            $fn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, has_function_privilege('public', p.oid, 'EXECUTE') as public_exec from pg_proc p where p.proname = ?", [$function]);
            $this->assertFalse($fn->prosecdef, "{$function} is SECURITY INVOKER");
            $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', $fn->config);
            $this->assertFalse($fn->public_exec);
        }
        foreach (['payment_correction_requests_guard', 'payment_correction_requests_approval_guard', 'payment_correction_requests_beneficiary_guardian_guard'] as $trigger) {
            $this->assertSame('O', DB::selectOne('select tgenabled from pg_trigger where tgname = ?', [$trigger])->tgenabled, $trigger);
        }
        foreach (['payment_correction_requests_sod_check', 'payment_correction_requests_withdrawer_check', 'payment_correction_requests_kind_shape_check', 'payment_correction_requests_status_shape_check', 'payment_correction_requests_reason_check'] as $check) {
            $this->assertNotNull(DB::selectOne('select 1 as ok from pg_constraint where conname = ?', [$check]), $check);
        }
        $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'public.payment_correction_requests'::regclass");
        $this->assertTrue($rls->relrowsecurity && $rls->relforcerowsecurity);

        $other = $this->createSchool();
        $db = $this->runtime($other);
        $this->assertSame(0, (int) $db->selectOne('select count(*) as c from payment_correction_requests')->c, 'another School sees no request');
        $this->assertSame([], $db->select('select s from payment_correction_request_student_ids(?, ?) s', [$w['school']->id, $request->id]), 'nor its Students');
        $this->assertSame(0, $this->raw($other, "update payment_correction_requests set status = 'withdrawn', withdrawn_at = now(), withdrawn_by_user_id = requested_by_user_id where id = ?", [$request->id]));
    }
}
