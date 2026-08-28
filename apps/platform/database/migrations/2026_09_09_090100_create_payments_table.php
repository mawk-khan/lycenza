<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0G.5: the School's normalized, immutable record of money
     * settled via a payment provider -- owned by `App\Domain\Payments`.
     * Created ONLY at settlement (docs/modules/FINANCE.md "Payment
     * model": "the gateway event itself never becomes a ledger row; it
     * is the trigger for one") -- there is no `pending`/`failed` status
     * on this table and no draft state, a deliberate refinement of
     * FINANCE.md's 0G.0-era conceptual sketch (which floated a mutable
     * pending/settled/failed status column) toward the SAME "immediate
     * recognition, no draft" philosophy 0G.4 already established for
     * `charges` (ADR 0030's "once it exists, it is a posted fact" one
     * layer up again) -- see `docs/modules/FINANCE.md` "0G.5 as-built"
     * for the explicit deviation and rationale. A `payments` row can
     * therefore never be un-recognized: there is no legitimate UPDATE
     * path at all (unlike `charges`' cancellation pair) -- Refunds are
     * explicitly deferred (FINANCE.md's own `finance.refunds.manage`
     * conceptual capability, not implemented here), so
     * `TenantRls::makeAppendOnly()` applies, not the narrower
     * `revokeDelete()` `charges` uses.
     *
     * `provider_event_id` is a structural FK to the exact
     * `payment_provider_events` row whose processing created this
     * `Payment` (rule "no description-only linkage") -- one provider
     * event creates at most one Payment
     * (`unique(['school_id','provider_event_id'])`), and one Payment is
     * always traceable to the ONE event that recognized it.
     *
     * `unique(['school_id','provider','provider_payment_reference'])`
     * (FINANCE.md "Payment model", section 23 of the 0G.5 brief): keeps
     * "provider event identity" (payment_provider_events' own key)
     * structurally separate from "provider transaction identity" -- a
     * provider may emit several events for one underlying transaction
     * over its lifecycle, but at most one Payment may ever be recognized
     * per (School, provider, transaction). A second settlement event
     * for the same transaction (even under a different
     * provider_event_id) is rejected by this constraint, never silently
     * creates a second Payment.
     *
     * `unique(['school_id','journal_entry_id'])`: one Payment <-> one
     * settlement JournalEntry, structurally, mirroring `charges`' own
     * `journal_entry_id` uniqueness exactly.
     *
     * `settlement_ledger_account_id` is an EXPLICIT input to
     * `PaymentProviderEventService::recordSettlement()` (section 11 of
     * the 0G.5 brief: "explicit Payment input", never a hardcoded/
     * magic-looked-up account) -- the same pattern `charges.receivable_ledger_account_id`/
     * `revenue_ledger_account_id` already established for 0G.4. No
     * School Finance configuration table is introduced to source it
     * (CLAUDE.md rule 2: no speculative infrastructure for a need that
     * does not yet exist) -- the trusted caller supplies which asset
     * account (e.g. a School's own "Cash"/"Bank" ledger account) the
     * settled money lands in.
     *
     * `unique(['id', 'school_id'])` follows precedent for
     * `payment_allocations`' own composite FK (next migration).
     *
     * `creation_txid` (ADR 0031, correction after initial closure
     * review): the database-authoritative source of truth for "which
     * transaction originally created this Payment" -- the DIRECT
     * analog of `journal_entries.posting_txid`
     * (`2026_08_31_090300_add_journal_entry_posting_invariants.php`),
     * same `xid8` type, same unconditional-overwrite mechanism
     * (`finance_payments_set_creation_txid()`, an unconditional
     * `BEFORE INSERT` trigger -- `NEW.creation_txid := pg_current_xact_id()`
     * runs for every insert regardless of any caller-supplied value, so
     * it can never be spoofed). `payment_allocations`' own
     * `BEFORE INSERT` trigger (next migration) compares its target
     * Payment's `creation_txid` against the CURRENT transaction's id to
     * decide whether an allocation insert is still part of the
     * original settlement transaction -- see that migration's docblock.
     * Never exposed through `PaymentResult`/`PaymentSummary`/
     * `PaymentDetail`/any audit metadata/any outbox payload -- purely an
     * internal database freeze mechanism, per ADR 0031.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_payment_reference');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->uuid('settlement_ledger_account_id');
            $table->uuid('journal_entry_id');
            $table->uuid('provider_event_id');
            $table->timestamp('settled_at');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'provider', 'provider_payment_reference'], 'payments_provider_reference_unique');
            $table->unique(['school_id', 'journal_entry_id'], 'payments_journal_entry_unique');
            $table->unique(['school_id', 'provider_event_id'], 'payments_provider_event_unique');
            $table->index('school_id');
            $table->index(['school_id', 'created_at']);

            $table->foreign(['settlement_ledger_account_id', 'school_id', 'currency'], 'payments_settlement_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();

            $table->foreign(['journal_entry_id', 'school_id', 'currency'], 'payments_journal_entry_fk')
                ->references(['id', 'school_id', 'currency'])->on('journal_entries')
                ->restrictOnDelete();

            $table->foreign(['provider_event_id', 'school_id'], 'payments_provider_event_fk')
                ->references(['id', 'school_id'])->on('payment_provider_events')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check '.
            'CHECK (amount > 0)'
        );

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_currency_inr_only_check '.
            "CHECK (currency = 'INR')"
        );

        // ADR 0031 "Frozen allocation set" -- see this migration's
        // docblock ("creation_txid"). Mirrors
        // journal_entries.posting_txid's exact column shape.
        DB::statement(
            'ALTER TABLE payments ADD COLUMN creation_txid xid8 NOT NULL DEFAULT pg_current_xact_id()'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION finance_payments_set_creation_txid() RETURNS trigger AS $$
            BEGIN
                NEW.creation_txid := pg_current_xact_id();

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(
            'CREATE TRIGGER payments_set_creation_txid '.
            'BEFORE INSERT ON payments '.
            'FOR EACH ROW EXECUTE FUNCTION finance_payments_set_creation_txid()'
        );

        TenantRls::enable('payments');
        TenantRls::makeAppendOnly('payments');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payments_set_creation_txid ON payments');
        DB::statement('DROP FUNCTION IF EXISTS finance_payments_set_creation_txid()');
        TenantRls::disable('payments');
        Schema::dropIfExists('payments');
    }
};
