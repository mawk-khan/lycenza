<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0G.5 (docs/modules/FINANCE.md "Payment-callback idempotency"):
     * the durable ingress-history/idempotency table for inbound payment-
     * provider callbacks -- owned by the NEW `App\Domain\Payments` module
     * (DOMAIN-MAP.md's separate "Payments" row -- depends on Fees,
     * Finance -- never the reverse), NOT `App\Domain\Finance` or
     * `App\Domain\Fees`.
     *
     * Deliberately NOT the same table as `webhook_deliveries` (rule 35:
     * inbound and outbound webhook delivery are never conflated) --
     * `(school_id, provider, provider_event_id)` is the direct analog of
     * `webhook_deliveries`' `(webhook_endpoint_id, event_id)` uniqueness,
     * for the opposite traffic direction. Atomic claim is a plain INSERT
     * relying on this unique index, caught by
     * `Illuminate\Database\UniqueConstraintViolationException` in
     * `App\Domain\Payments\Application\PaymentProviderEventService` --
     * the same structural pattern `App\Support\Idempotency\IdempotencyGuard::claim()`
     * uses (rule 30: never check-then-insert), applied to a dedicated
     * table because the scoping key is different (a provider's own
     * event id, not a client-supplied `Idempotency-Key`). This is NOT a
     * second idempotency framework -- `IdempotencyGuard` itself is never
     * reused directly because it is bound to HTTP request/response replay
     * semantics (`ApiIdempotencyKey.response_body` etc.) that make no
     * sense for a trusted internal provider-event ingestion boundary.
     *
     * `provider_payment_reference` is deliberately a SEPARATE column from
     * `provider_event_id` (FINANCE.md "Payment model": "Keep provider
     * event identity separate from provider transaction identity") -- a
     * provider may emit several distinct events for the same underlying
     * payment/transaction over its lifecycle; this table's uniqueness is
     * keyed on event identity, while `payments.provider_payment_reference`
     * (see that migration) is separately keyed on transaction identity.
     *
     * NO `payment_id`/`processed_at`/`failure_reason` column exists here,
     * deliberately -- this table is a PURE immutable ingress-identity
     * record with no legitimate update path at all
     * (`TenantRls::makeAppendOnly()`, not the narrower `revokeDelete()`
     * `charges` needed). The relationship "this event produced that
     * Payment" is fully represented by the OTHER side of the FK --
     * `payments.provider_event_id` (see that migration) -- because
     * `App\Domain\Payments\Application\PaymentProviderEventService::recordSettlement()`
     * inserts this event row and its resulting `payments` row inside ONE
     * atomic transaction: either both are created together (this event
     * really did produce exactly this Payment) or neither is persisted
     * at all (a mid-transaction failure rolls back the event row too, so
     * the SAME provider_event_id remains genuinely retryable -- rule 42's
     * "first attempt transactionally fails -> zero business effect ->
     * retry succeeds once", satisfied by construction, no release/
     * recovery mechanism needed). Adding mutable processing-state columns
     * here with no code path that would ever need them would be exactly
     * the kind of speculative half-used state CLAUDE.md rule 2 and the
     * 0G.5 brief's "do not create a vague mutable status machine"
     * instruction both forbid.
     *
     * 0G.5 scope decision (recorded here since nothing upstream settles
     * it): only ONE normalized `event_type` is actually accepted by
     * `PaymentProviderEventService::recordSettlement()` -- a fixed
     * settlement/success type; anything else is REJECTED outright
     * (`App\Domain\Payments\Application\Exceptions\UnsupportedProviderEventTypeException`)
     * before any row is written, never persisted as a no-op history
     * record. Non-settlement provider callback types (pending/authorized/
     * failed) are NOT modeled in 0G.5 -- FINANCE.md's own conceptual
     * sketch never concretely resolves a business need for tracking them
     * yet, and inventing a multi-type lifecycle now would be speculative.
     * This column still exists (so a later checkpoint can widen
     * acceptance without a schema change) but is effectively constant
     * today; see `docs/modules/FINANCE.md` "0G.5 as-built" for the
     * explicit deferral.
     *
     * `unique(['id', 'school_id'])` follows `charges`'/`students`'/
     * `academic_years`' own established precedent -- `payments.provider_event_id`
     * (see that migration) references it via a composite FK.
     */
    public function up(): void
    {
        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_event_id');
            $table->string('event_type');
            $table->string('provider_payment_reference');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->timestamp('occurred_at');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'provider', 'provider_event_id'], 'payment_provider_events_event_unique');
            $table->index('school_id');
            $table->index(['school_id', 'provider', 'provider_payment_reference']);
        });

        DB::statement(
            'ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_events_amount_positive_check '.
            'CHECK (amount > 0)'
        );

        DB::statement(
            'ALTER TABLE payment_provider_events ADD CONSTRAINT payment_provider_events_currency_inr_only_check '.
            "CHECK (currency = 'INR')"
        );

        TenantRls::enable('payment_provider_events');
        TenantRls::makeAppendOnly('payment_provider_events');
    }

    public function down(): void
    {
        TenantRls::disable('payment_provider_events');
        Schema::dropIfExists('payment_provider_events');
    }
};
