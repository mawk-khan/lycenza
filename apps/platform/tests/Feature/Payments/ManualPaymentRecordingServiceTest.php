<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Domain\Payments\Application\Exceptions\AllocationDoesNotSumToPaymentAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeAllocationExceedsChargeAmountException;
use App\Domain\Payments\Application\Exceptions\ChargeIsCancelledException;
use App\Domain\Payments\Application\Exceptions\InvalidManualPaymentException;
use App\Domain\Payments\Application\Exceptions\InvalidSettlementDataException;
use App\Domain\Payments\Application\Exceptions\ManualPaymentIdempotencyConflictException;
use App\Domain\Payments\Application\ManualPaymentOutcome;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment): the manual/offline
 * payment ingress -- authorization, the input contract, the shared
 * settled-payment core's invariants, provenance, idempotency, audit,
 * outbox, and that no provider evidence is ever manufactured.
 */
class ManualPaymentRecordingServiceTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures;

    /**
     * @return array{0: School, 1: User, 2: object, 3: object, 4: object}
     *                                                                    [school, recorder, settlement account, charge (1000.00), second charge (500.00)]
     */
    private function setUpSchool(): array
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $settlement = $this->createLedgerAccount($school, ['type' => 'asset']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '1000.00');
        $second = $this->assessCharge($school, $student, $year, $receivable, $revenue, '500.00');

        return [$school, $this->createPaymentRecorder($school), $settlement, $charge, $second];
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    // --- Recording ---------------------------------------------------------

    #[Test]
    public function an_authorized_user_records_a_cash_payment_with_manual_provenance(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        $key = (string) Str::uuid();

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '400.00']], '400.00', ManualPaymentMethod::Cash, occurredOn: '2026-09-01', idempotencyKey: $key);

        $this->assertSame(ManualPaymentOutcome::Recorded, $result->outcome);
        $payment = $this->findPayment($school, $result->paymentId);
        $this->assertSame('manual', $payment->source);
        $this->assertSame('cash', $payment->method);
        $this->assertNull($payment->manual_reference);
        $this->assertSame($recorder->id, $payment->recorded_by_user_id);
        $this->assertSame($key, $payment->idempotency_key);
        $this->assertNull($payment->provider);
        $this->assertNull($payment->provider_payment_reference);
        $this->assertNull($payment->provider_event_id);
        $this->assertSame('400.00', $payment->amount);
        $this->assertSame('INR', $payment->currency);
        $this->assertSame($settlement->id, $payment->settlement_ledger_account_id);
        $this->assertCount(1, $payment->allocations);
        $this->assertSame('400.00', $payment->allocations->first()->amount);
    }

    #[Test]
    public function manual_recording_never_creates_a_payment_provider_event(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '100.00']], '100.00');

        $this->assertSame(0, $this->inSchool($school, fn () => PaymentProviderEvent::query()->count()));
    }

    #[Test]
    public function the_service_source_never_references_provider_ingestion(): void
    {
        // Code only -- the docblock deliberately names the provider
        // boundary it is NOT.
        $source = (string) file_get_contents(app_path('Domain/Payments/Application/ManualPaymentRecordingService.php'));
        $code = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all($source),
        ));

        $this->assertStringNotContainsString('PaymentProviderEvent', $code);
        $this->assertStringNotContainsString('NormalizedProviderEvent', $code);
        $this->assertStringNotContainsString('PaymentProvenance::provider', $code);
        $this->assertStringContainsString('PaymentProvenance::manual', $code);
    }

    #[Test]
    public function the_ledger_posting_debits_the_chosen_asset_account_and_credits_each_charge_receivable(): void
    {
        [$school, $recorder, $settlement, $charge, $second] = $this->setUpSchool();

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '300.00'], [$second, '200.00']], '500.00', ManualPaymentMethod::BankTransfer, 'UTR123456789');

        $payment = $this->findPayment($school, $result->paymentId);
        $lines = $this->inSchool($school, fn () => JournalLine::query()->where('journal_entry_id', $payment->journal_entry_id)->get());

        $this->assertCount(3, $lines);
        $debit = $lines->firstWhere('debit_amount', '!=', null);
        $this->assertSame($settlement->id, $debit->ledger_account_id);
        $this->assertSame('500.00', $debit->debit_amount);
        $this->assertSame(['200.00', '300.00'], $lines->whereNotNull('credit_amount')->pluck('credit_amount')->sort()->values()->all());
        $this->assertSame([$charge->receivable_ledger_account_id], $lines->whereNotNull('credit_amount')->pluck('ledger_account_id')->unique()->values()->all());
        $this->assertSame('Offline payment recorded: Bank transfer', $this->inSchool($school, fn () => JournalEntry::query()->findOrFail($payment->journal_entry_id)->description));
    }

    #[Test]
    public function one_payment_may_cover_several_charges(): void
    {
        [$school, $recorder, $settlement, $charge, $second] = $this->setUpSchool();

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '1000.00'], [$second, '500.00']], '1500.00', ManualPaymentMethod::Cheque, '004512');

        $payment = $this->findPayment($school, $result->paymentId);
        $this->assertEqualsCanonicalizing(['1000.00', '500.00'], $payment->allocations->pluck('amount')->all());
        $this->assertSame('004512', $payment->manual_reference);
    }

    #[Test]
    public function occurred_at_is_the_start_of_the_school_local_day_and_recorded_at_is_server_time(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        $this->travelTo(Carbon::parse('2026-09-28 12:00:00', 'UTC'));

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', occurredOn: '2026-09-20');

        $payment = $this->findPayment($school, $result->paymentId);
        // Asia/Kolkata (the School default) is UTC+05:30.
        $this->assertSame('2026-09-19 18:30:00', $payment->settled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-28 12:00:00', $payment->created_at->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function today_in_the_school_timezone_is_accepted_but_tomorrow_is_refused(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        // 20:00 UTC on 2026-09-28 is already 2026-09-29 in Asia/Kolkata.
        $this->travelTo(Carbon::parse('2026-09-28 20:00:00', 'UTC'));

        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', occurredOn: '2026-09-29');

        try {
            $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', occurredOn: '2026-09-30');
            $this->fail('A future received date must be refused.');
        } catch (InvalidManualPaymentException $e) {
            $this->assertSame('occurred_on', $e->field);
        }
    }

    #[Test]
    public function an_old_received_date_is_accepted_because_there_is_no_accounting_period_contract(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', occurredOn: '2019-04-01');

        $this->assertSame(ManualPaymentOutcome::Recorded, $result->outcome);
    }

    #[Test]
    public function an_invalid_calendar_date_is_refused(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $this->expectException(InvalidManualPaymentException::class);
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', occurredOn: '2026-02-30');
    }

    #[Test]
    public function audit_records_the_manual_recording_with_minimal_metadata_and_the_actor(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '250.00']], '250.00', ManualPaymentMethod::Cheque, 'CHQ-77', '2026-09-10');

        $events = $this->inSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.recorded_manually')->get());
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame($recorder->id, $event->actor_user_id);
        $this->assertSame($result->paymentId, $event->subject_id);
        $this->assertEquals([
            'method' => 'cheque',
            'currency' => 'INR',
            'occurredOn' => '2026-09-10',
            'allocationCount' => 1,
            'hasReference' => true,
        ], $event->metadata);
        $this->assertStringNotContainsString('CHQ-77', json_encode($event->metadata));
        $this->assertSame(0, $this->inSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.settled')->count()), 'Manual recording must not write the provider settlement audit event.');
    }

    #[Test]
    public function the_payment_settled_outbox_event_is_dispatched_unchanged(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '250.00']], '250.00', reference: 'REF1');

        $rows = DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'payment.settled.v1')->get();
        $this->assertCount(1, $rows);
        $payload = $rows->first()->payload;
        $this->assertSame($result->paymentId, $payload['paymentId']);
        $this->assertSame(1, $payload['allocationCount']);
        $this->assertStringNotContainsString('REF1', json_encode($payload));
    }

    #[Test]
    public function the_read_model_exposes_manual_provenance_but_never_the_idempotency_key(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        $key = (string) Str::uuid();

        $result = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', ManualPaymentMethod::BankTransfer, 'UTR1', idempotencyKey: $key);
        $detail = app(PaymentReadService::class)->getPaymentDetail($school, $result->paymentId, $recorder);

        $this->assertSame('manual', $detail->source);
        $this->assertSame('bank_transfer', $detail->method);
        $this->assertSame('UTR1', $detail->manualReference);
        $this->assertSame($recorder->id, $detail->recordedByUserId);
        $this->assertNull($detail->provider);
        $this->assertStringNotContainsString($key, json_encode(get_object_vars($detail)));
    }

    // --- Idempotency -------------------------------------------------------

    #[Test]
    public function the_same_key_and_content_replays_the_existing_payment_without_a_second_effect(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        $key = (string) Str::uuid();

        $first = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '1000.00']], '1000.00', ManualPaymentMethod::Cash, 'R1', '2026-09-01', $key);
        $second = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '1000.00']], '1000.00', ManualPaymentMethod::Cash, 'R1', '2026-09-01', $key);

        $this->assertSame(ManualPaymentOutcome::DuplicateReplay, $second->outcome);
        $this->assertSame($first->paymentId, $second->paymentId);
        $this->assertSame(1, $this->inSchool($school, fn () => Payment::query()->count()));
        $this->assertSame(1, $this->inSchool($school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.recorded_manually')->count()));
    }

    #[Test]
    public function the_same_key_with_different_content_fails_closed(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        $key = (string) Str::uuid();
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '100.00']], '100.00', idempotencyKey: $key);

        foreach ([
            fn () => $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '200.00']], '200.00', idempotencyKey: $key),
            fn () => $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '100.00']], '100.00', ManualPaymentMethod::Cheque, idempotencyKey: $key),
            fn () => $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '100.00']], '100.00', reference: 'X1', idempotencyKey: $key),
            fn () => $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '100.00']], '100.00', occurredOn: '2026-01-01', idempotencyKey: $key),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A reused key with different content must fail closed.');
            } catch (ManualPaymentIdempotencyConflictException) {
                // expected
            }
        }

        $this->assertSame(1, $this->inSchool($school, fn () => Payment::query()->count()));
    }

    #[Test]
    public function the_same_key_from_another_user_fails_closed_rather_than_replaying(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        $other = $this->createPaymentRecorder($school);
        $key = (string) Str::uuid();
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '100.00']], '100.00', idempotencyKey: $key);

        $this->expectException(ManualPaymentIdempotencyConflictException::class);
        $this->recordManualPayment($school, $other, $settlement->id, [[$charge, '100.00']], '100.00', idempotencyKey: $key);
    }

    #[Test]
    public function the_same_key_in_two_schools_records_two_independent_payments(): void
    {
        [$schoolA, $recorderA, $settlementA, $chargeA] = $this->setUpSchool();
        [$schoolB, $recorderB, $settlementB, $chargeB] = $this->setUpSchool();
        $key = (string) Str::uuid();

        $a = $this->recordManualPayment($schoolA, $recorderA, $settlementA->id, [[$chargeA, '100.00']], '100.00', idempotencyKey: $key);
        $b = $this->recordManualPayment($schoolB, $recorderB, $settlementB->id, [[$chargeB, '100.00']], '100.00', idempotencyKey: $key);

        $this->assertSame(ManualPaymentOutcome::Recorded, $b->outcome);
        $this->assertNotSame($a->paymentId, $b->paymentId);
    }

    #[Test]
    public function a_non_uuid_key_is_refused(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $this->expectException(InvalidManualPaymentException::class);
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', idempotencyKey: 'not-a-uuid');
    }

    // --- Allocation / amount invariants ----------------------------------

    #[Test]
    public function allocations_must_sum_exactly_to_the_amount(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $this->expectException(AllocationDoesNotSumToPaymentAmountException::class);
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '90.00']], '100.00');
    }

    #[Test]
    public function a_charge_cannot_be_over_allocated_across_payments(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '700.00']], '700.00');

        try {
            $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '400.00']], '400.00');
            $this->fail('Over-allocation must be refused.');
        } catch (ChargeAllocationExceedsChargeAmountException) {
            // expected
        }

        $this->assertSame('700.00', (string) $this->inSchool($school, fn () => PaymentAllocation::query()->where('charge_id', $charge->id)->sum('amount')));
    }

    #[Test]
    public function a_cancelled_charge_cannot_receive_a_manual_payment(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        app(ChargeService::class)->cancel($school, $charge->id);

        $this->expectException(ChargeIsCancelledException::class);
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00');
    }

    #[Test]
    public function a_charge_of_another_school_is_indistinguishable_from_a_missing_one(): void
    {
        [$school, $recorder, $settlement] = $this->setUpSchool();
        [, , , $foreignCharge] = $this->setUpSchool();

        $this->expectException(ChargeNotFoundException::class);
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$foreignCharge, '10.00']], '10.00');
    }

    #[Test]
    public function zero_negative_and_non_inr_amounts_are_refused_by_the_shared_core(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $this->expectException(InvalidSettlementDataException::class);
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '0.00']], '0.00');
    }

    #[Test]
    public function the_same_charge_twice_in_one_payment_is_refused(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        $this->expectException(InvalidSettlementDataException::class);
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00'], [$charge, '10.00']], '20.00');
    }

    #[Test]
    public function the_settlement_account_must_be_an_active_asset_account_of_the_school(): void
    {
        [$school, $recorder, , $charge] = $this->setUpSchool();
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $inactive = $this->createLedgerAccount($school, ['type' => 'asset', 'status' => 'inactive']);
        [$other] = $this->setUpSchool();
        $foreign = $this->createLedgerAccount($other, ['type' => 'asset']);

        foreach ([$income->id, $inactive->id, $foreign->id, (string) Str::uuid(), 'not-a-uuid'] as $accountId) {
            try {
                $this->recordManualPayment($school, $recorder, $accountId, [[$charge, '10.00']], '10.00');
                $this->fail("Account {$accountId} must be refused.");
            } catch (InvalidManualPaymentException $e) {
                $this->assertSame('settlement_ledger_account_id', $e->field);
            }
        }

        $this->assertSame(0, $this->inSchool($school, fn () => Payment::query()->count()));
    }

    #[Test]
    public function the_reference_is_bounded_and_narrow(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();

        foreach ([str_repeat('A', 65), ' leading', 'trailing ', 'semi;colon', 'new'.PHP_EOL.'line', '4111-1111-1111-1111<script>', ''] as $reference) {
            try {
                $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', reference: $reference);
                $this->fail("Reference '{$reference}' must be refused.");
            } catch (InvalidManualPaymentException $e) {
                $this->assertSame('reference', $e->field);
            }
        }

        $ok = $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00', reference: str_repeat('A', 64));
        $this->assertSame(ManualPaymentOutcome::Recorded, $ok->outcome);
    }

    // --- Authorization / lifecycle ---------------------------------------

    #[Test]
    public function a_user_without_the_record_capability_is_refused_before_anything_is_written(): void
    {
        [$school, , $settlement, $charge] = $this->setUpSchool();

        foreach (['principal', null] as $roleKey) {
            $user = $this->createUser();
            $membership = $this->createMembership($user, $school);
            if ($roleKey !== null) {
                $this->assignSchoolRole($membership, $roleKey);
            }

            try {
                $this->recordManualPayment($school, $user, $settlement->id, [[$charge, '10.00']], '10.00');
                $this->fail('Recording without finance.payments.record must be refused.');
            } catch (AuthorizationException) {
                // expected
            }
        }

        $viewer = $this->createUserWithCapabilities($school, ['finance.payments.view', 'finance.charges.manage', 'finance.ledger.post']);
        try {
            $this->recordManualPayment($school, $viewer, $settlement->id, [[$charge, '10.00']], '10.00');
            $this->fail('Viewing payments or managing charges must not imply recording.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, $this->inSchool($school, fn () => Payment::query()->count()));
    }

    #[Test]
    public function a_recorder_of_school_a_cannot_record_in_school_b(): void
    {
        [, $recorderA] = $this->setUpSchool();
        [$schoolB, , $settlementB, $chargeB] = $this->setUpSchool();

        $this->expectException(AuthorizationException::class);
        $this->recordManualPayment($schoolB, $recorderA, $settlementB->id, [[$chargeB, '10.00']], '10.00');
    }

    #[Test]
    public function a_suspended_school_refuses_manual_recording(): void
    {
        [$school, $recorder, $settlement, $charge] = $this->setUpSchool();
        DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);

        try {
            $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '10.00']], '10.00');
            $this->fail('A suspended School must refuse manual recording.');
        } catch (SchoolNotOperationalException) {
            // expected
        }

        $this->assertSame(0, $this->inSchool($school, fn () => Payment::query()->count()));
    }

    #[Test]
    public function outstanding_charges_show_what_is_already_allocated(): void
    {
        [$school, $recorder, $settlement, $charge, $second] = $this->setUpSchool();
        $this->recordManualPayment($school, $recorder, $settlement->id, [[$charge, '250.00']], '250.00');
        $studentId = $charge->student_id;

        $rows = app(ManualPaymentRecordingService::class)->outstandingChargesForStudent($school, $studentId, $recorder);

        $byId = collect($rows)->keyBy('chargeId');
        $this->assertSame('250.00', $byId[$charge->id]->allocated);
        $this->assertSame('750.00', $byId[$charge->id]->outstanding);
        $this->assertSame('0.00', $byId[$second->id]->allocated);
        $this->assertSame('500.00', $byId[$second->id]->outstanding);
    }
}
