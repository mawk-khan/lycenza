<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0G.5: the immutable join between one `payments` row and one
     * `charges` row it settles -- owned by `App\Domain\Payments`, which
     * references Fees' `charges(id, school_id)` composite key the 0G.4
     * migration deliberately added in anticipation of exactly this (see
     * that migration's docblock: "so a future 0G.5 `payment_allocations`
     * composite foreign key can reference (id, school_id) without a
     * later migration adding it retroactively"). This does NOT create a
     * Fees -> Payments dependency (DOMAIN-MAP.md: Payments depends on
     * Fees and Finance, never the reverse) -- a structural FK from a
     * Payments-owned table to a Fees-owned table is the SAME sanctioned
     * cross-module schema pattern rule 70 already established
     * (Section -> AcademicYear, etc.), not a PHP/Application-layer call
     * in either direction.
     *
     * Allocation semantics resolved for 0G.5 (FINANCE.md "Payment model":
     * "a single payment covering multiple charges (or a partial payment
     * against one charge)"):
     * - One Payment MAY allocate across multiple Charges.
     * - One Charge MAY receive allocations from multiple Payments over
     *   time (a Charge need not be fully settled by any single Payment).
     * - A Payment's allocations MUST sum to exactly `payments.amount`
     *   (`payment_allocations_payment_fully_allocated_check`, below) --
     *   0G.5's chosen resolution of the "overpayment / unallocated
     *   money" pre-code gate: rather than inventing unapplied-cash/
     *   customer-credit accounting (which FINANCE.md never defines),
     *   `PaymentProviderEventService::recordSettlement()` REQUIRES its
     *   caller to supply an allocation set that both fully consumes the
     *   settled amount and stays within each named Charge's remaining
     *   balance; if it cannot, the whole operation is rejected (no
     *   Payment, no allocation, no ledger posting, no unexplained money
     *   silently credited to Accounts Receivable). True overpayment/
     *   unapplied-cash accounting remains explicitly DEFERRED, alongside
     *   Refunds.
     * - A single Charge's cumulative allocations may never exceed its
     *   own `amount` (`payment_allocations_charge_not_overallocated_check`,
     *   below).
     *
     * Invariant A (Payment fully allocated) is a cross-row (SUM)
     * constraint no plain CHECK can express -- enforced via
     * `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED`, the EXACT
     * precedent `2026_08_31_090300_add_journal_entry_posting_invariants.php`
     * already established for `journal_entries_balanced_check`. A
     * DEFERRED check is CORRECT here because every `payment_allocations`
     * row belonging to one Payment is only ever inserted inside the
     * SAME transaction that creates that Payment (guaranteed
     * structurally by Invariant C below, not merely by convention) --
     * there is no other transaction that could race this SUM.
     *
     * Invariant B (Charge not over-allocated) is ALSO a cross-row SUM
     * constraint, but -- unlike Invariant A -- it must defend against a
     * genuinely concurrent SECOND transaction inserting a DIFFERENT
     * allocation against the SAME Charge at the same time (ADR 0031
     * "Alternatives considered" #4). A purely `DEFERRED` check alone
     * cannot close this: two concurrent transactions could each
     * evaluate the SUM before either commits, each seeing only
     * already-committed rows, and both pass. This migration therefore
     * enforces Invariant B via an IMMEDIATE (non-deferred) `BEFORE
     * INSERT` trigger (`payments_lock_and_validate_charge_allocation()`)
     * that takes a `SELECT ... FOR UPDATE` lock on the target Charge row
     * BEFORE computing the sum -- a second concurrent insert against the
     * same Charge genuinely blocks on that lock until the first
     * transaction commits or rolls back, then re-evaluates against the
     * now-final, committed total. This is the database-authoritative
     * mechanism, not merely a backstop -- `App\Domain\Fees\Application\ChargeService::lockChargeForAllocation()`'s
     * own `SELECT ... FOR UPDATE` (taken in ascending `charge_id` order
     * across multiple Charges in one settlement, per rule 33) is
     * REDUNDANT with this trigger for the Application code path
     * specifically, but the TRIGGER is what makes the guarantee hold
     * for every caller, including a raw or future Application path that
     * does not go through `lockChargeForAllocation()` first. The same
     * lock this trigger takes also serializes against a concurrent
     * Charge cancellation (`UPDATE charges SET cancelled_at = ...`,
     * which takes an equivalent row lock as part of the `UPDATE`
     * itself) -- see `charges_payment_allocation_guard_trigger` below.
     *
     * Invariant C (frozen allocation set, ADR 0031): a Payment's
     * allocation set may only be populated inside the SAME transaction
     * that created the Payment -- `payment_allocations_reject_post_commit_insert()`,
     * an IMMEDIATE `BEFORE INSERT` trigger, compares the target
     * Payment's `creation_txid` (see the `create_payments_table`
     * migration) against `pg_current_xact_id()`; a mismatch means a
     * LATER transaction is attempting to extend an already-committed
     * Payment's allocation set, rejected. This is the direct analog of
     * `journal_lines_reject_post_commit_insert()` one layer up, and is
     * what makes "the allocation set is frozen forever" true even
     * against a raw INSERT that stays within the Charge's remaining
     * capacity -- Invariant B alone would happily allow such an insert.
     *
     * Immutable forever, like `payments` (`TenantRls::makeAppendOnly()`) --
     * no reassignment/correction model exists in 0G.5 (deferred alongside
     * Refunds, per rule 28 of the 0G.5 brief). `makeAppendOnly()` alone
     * already revokes UPDATE/DELETE from the runtime role; Invariant C
     * above is what additionally closes the one remaining gap an
     * append-only table does NOT close on its own -- a later, otherwise
     * legitimate INSERT against an already-settled Payment.
     *
     * `charges_payment_allocation_guard_trigger` (this migration, NOT the
     * frozen 0G.4 `create_charges_table` migration -- rule 66/67 of the
     * 0G.5 brief): implements the 0G.5 Charge-cancellation-interlock
     * decision (rule 12/46) -- a Charge with ANY authoritative
     * `payment_allocations` row can never be cancelled, full stop (no
     * partial-cancellation carve-out, since compensating a cancellation
     * against already-settled money would require Refund modeling,
     * explicitly deferred). This is a PAYMENTS invariant expressed as a
     * trigger physically attached to Fees' `charges` table -- exactly
     * the same "structural DB constraint crossing a module boundary,
     * never a reverse PHP/Application dependency" pattern the composite
     * FK above already uses, just via a trigger instead of a FK because
     * "does at least one payment_allocations row exist for this charge"
     * is not expressible as a FK. `App\Domain\Fees\Application\ChargeService::cancel()`
     * is taught to catch this trigger's raised exception and translate
     * it to `App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException`
     * -- Fees' PHP code never reads `payment_allocations` directly, and
     * never depends on `App\Domain\Payments`' Application layer; only
     * this migration's SQL knows both tables. `SECURITY INVOKER`
     * (default, written explicitly), matching every other trigger in
     * this checkpoint's lineage -- runs under the calling role's own RLS
     * visibility.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payment_id');
            $table->uuid('charge_id');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index(['school_id', 'payment_id']);
            $table->index(['school_id', 'charge_id']);

            $table->foreign(['payment_id', 'school_id'], 'payment_allocations_payment_fk')
                ->references(['id', 'school_id'])->on('payments')
                ->restrictOnDelete();

            $table->foreign(['charge_id', 'school_id'], 'payment_allocations_charge_fk')
                ->references(['id', 'school_id'])->on('charges')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive_check '.
            'CHECK (amount > 0)'
        );

        DB::statement(
            'ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_currency_inr_only_check '.
            "CHECK (currency = 'INR')"
        );

        // Invariant A: a Payment's allocations must sum to EXACTLY its
        // own amount -- fires once per `payments` row at commit time
        // (mirrors `finance_check_journal_entry_balanced()` exactly).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_check_payment_fully_allocated() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_payment_amount numeric;
                v_allocated_total numeric;
            BEGIN
                SELECT amount INTO v_payment_amount FROM payments WHERE id = NEW.id;

                SELECT coalesce(sum(amount), 0) INTO v_allocated_total
                    FROM payment_allocations
                    WHERE payment_id = NEW.id;

                IF v_allocated_total <> v_payment_amount THEN
                    RAISE EXCEPTION
                        'payment % allocations (%) do not sum to its own amount (%)',
                        NEW.id, v_allocated_total, v_payment_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER payments_fully_allocated_check
                AFTER INSERT ON payments
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION payments_check_payment_fully_allocated();
        SQL);

        // Invariant B: a Charge's cumulative allocations must never
        // exceed its own amount, enforced under a shared Charge-row
        // lock -- see this migration's docblock ("Invariant B"). An
        // IMMEDIATE (non-deferred) BEFORE INSERT trigger, not a
        // CONSTRAINT TRIGGER: it must run, and take its lock, before
        // the insert itself is permitted, not merely before commit.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_lock_and_validate_charge_allocation() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_charge_amount numeric;
                v_cancelled_at timestamp;
                v_allocated_total numeric;
            BEGIN
                SELECT amount, cancelled_at INTO v_charge_amount, v_cancelled_at
                    FROM charges
                    WHERE id = NEW.charge_id
                    FOR UPDATE;

                IF NOT FOUND THEN
                    -- A nonexistent or cross-School charge_id is rejected
                    -- independently by payment_allocations_charge_fk
                    -- (Postgres FK/RI checks are not subject to RLS) --
                    -- nothing further to validate here.
                    RETURN NEW;
                END IF;

                IF v_cancelled_at IS NOT NULL THEN
                    RAISE EXCEPTION 'charge % is cancelled and cannot receive a new payment allocation', NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(sum(amount), 0) INTO v_allocated_total
                    FROM payment_allocations
                    WHERE charge_id = NEW.charge_id;

                IF v_allocated_total + NEW.amount > v_charge_amount THEN
                    RAISE EXCEPTION
                        'charge % allocations (%) would exceed its own amount (%)',
                        NEW.charge_id, v_allocated_total + NEW.amount, v_charge_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER payment_allocations_lock_and_validate_charge_trigger '.
            'BEFORE INSERT ON payment_allocations '.
            'FOR EACH ROW EXECUTE FUNCTION payments_lock_and_validate_charge_allocation()'
        );

        // Invariant C: a Payment's allocation set is frozen the instant
        // its owning transaction commits -- see this migration's
        // docblock ("Invariant C"). Mirrors
        // finance_reject_post_commit_line_insert() exactly, one layer
        // up (Payment/creation_txid instead of JournalEntry/posting_txid).
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_reject_post_commit_allocation_insert() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                v_creation_txid xid8;
            BEGIN
                SELECT creation_txid INTO v_creation_txid FROM payments WHERE id = NEW.payment_id;

                IF v_creation_txid IS NULL THEN
                    RAISE EXCEPTION
                        'payment % not found for payment_allocations insert',
                        NEW.payment_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF v_creation_txid <> pg_current_xact_id() THEN
                    RAISE EXCEPTION
                        'payment % allocation set is frozen; cannot insert an additional '
                        'payment_allocations row after the original settlement transaction '
                        'has committed',
                        NEW.payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER payment_allocations_reject_post_commit_insert '.
            'BEFORE INSERT ON payment_allocations '.
            'FOR EACH ROW EXECUTE FUNCTION payments_reject_post_commit_allocation_insert()'
        );

        TenantRls::enable('payment_allocations');
        TenantRls::makeAppendOnly('payment_allocations');

        // Charge cancellation interlock (this migration owns it -- see
        // this migration's docblock "charges_payment_allocation_guard_trigger").
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_reject_charge_cancellation_with_allocations() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NEW.cancelled_at IS NOT NULL AND OLD.cancelled_at IS NULL AND EXISTS (
                    SELECT 1 FROM payment_allocations WHERE charge_id = OLD.id
                ) THEN
                    RAISE EXCEPTION 'charge % cannot be cancelled: recognized payment allocations exist', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER charges_payment_allocation_guard_trigger '.
            'BEFORE UPDATE ON charges '.
            'FOR EACH ROW EXECUTE FUNCTION payments_reject_charge_cancellation_with_allocations()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS charges_payment_allocation_guard_trigger ON charges');
        DB::statement('DROP FUNCTION IF EXISTS payments_reject_charge_cancellation_with_allocations()');

        DB::statement('DROP TRIGGER IF EXISTS payment_allocations_reject_post_commit_insert ON payment_allocations');
        DB::statement('DROP FUNCTION IF EXISTS payments_reject_post_commit_allocation_insert()');
        DB::statement('DROP TRIGGER IF EXISTS payment_allocations_lock_and_validate_charge_trigger ON payment_allocations');
        DB::statement('DROP FUNCTION IF EXISTS payments_lock_and_validate_charge_allocation()');
        DB::statement('DROP TRIGGER IF EXISTS payments_fully_allocated_check ON payments');
        DB::statement('DROP FUNCTION IF EXISTS payments_check_payment_fully_allocated()');

        TenantRls::disable('payment_allocations');
        Schema::dropIfExists('payment_allocations');
    }
};
