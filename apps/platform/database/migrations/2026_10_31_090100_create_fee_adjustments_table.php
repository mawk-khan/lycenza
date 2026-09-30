<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.3 (ADR 0062 §14.1, §14.5, §14.7; owner decisions F2, G1):
     * `fee_adjustments`, the posted financial effect of an approved
     * concession on one charge. Posting is Dr the School's concession
     * `expense` account / Cr the charge's own receivable account through
     * `LedgerService::post()`; a charge's amount is never changed.
     *
     * - `debit_ledger_account_id` snapshots the concession account in force
     *   when posted (F2), so changing `fee_settings` never moves history.
     *   At insert it must be an ACTIVE `expense` account of the School and
     *   the credit account must be the charge's receivable account.
     * - At insert the concession must be `approved`, of the charge's
     *   Student and year, with the same category; a targeted concession
     *   names this charge; a standing one covers the charge's fee
     *   assessment (head NULL or equal, instalment period start inside the
     *   validity window) -- `fees_validate_fee_adjustment`.
     * - One live adjustment per (concession, charge)
     *   (`fee_adjustments_one_live_per_concession_charge`).
     * - Capacity (G1, amount <= current outstanding) is the Payments-owned
     *   guard (`2026_10_31_090200_...`, ADR 0062 §15), which fires first
     *   and takes the charge row lock.
     * - Immutable except the one-time cancellation triple
     *   (`cancelled_at`, `cancellation_journal_entry_id`,
     *   `cancelled_by_user_id`), whose journal entry must reverse this
     *   adjustment's own entry -- the `charges` precedent.
     * - §14.7: a charge with a live adjustment cannot be cancelled
     *   (`charges_fee_adjustment_guard_trigger`); cancel the adjustments
     *   first, each its own reversal.
     * - RLS forced, composite same-School FKs (money references pin
     *   currency), never deleted.
     */
    public function up(): void
    {
        Schema::create('fee_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('charge_id');
            $table->uuid('fee_concession_id');
            $table->uuid('fee_assessment_id')->nullable();
            $table->string('category');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->uuid('debit_ledger_account_id');
            $table->uuid('credit_ledger_account_id');
            $table->uuid('journal_entry_id');
            $table->foreignUuid('posted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancellation_journal_entry_id')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'journal_entry_id']);
            $table->unique(['school_id', 'cancellation_journal_entry_id']);
            $table->index(['school_id', 'created_at']);
            $table->index('charge_id');
            $table->index('fee_concession_id');
            $table->index('fee_assessment_id');
            $table->index('debit_ledger_account_id');
            $table->index('credit_ledger_account_id');

            $table->foreign(['charge_id', 'school_id'], 'fee_adjustments_charge_fk')->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
            $table->foreign(['fee_concession_id', 'school_id'], 'fee_adjustments_concession_fk')->references(['id', 'school_id'])->on('fee_concessions')->restrictOnDelete();
            $table->foreign(['fee_assessment_id', 'school_id'], 'fee_adjustments_assessment_fk')->references(['id', 'school_id'])->on('fee_assessments')->restrictOnDelete();
            $table->foreign(['debit_ledger_account_id', 'school_id', 'currency'], 'fee_adjustments_debit_account_fk')->references(['id', 'school_id', 'currency'])->on('ledger_accounts')->restrictOnDelete();
            $table->foreign(['credit_ledger_account_id', 'school_id', 'currency'], 'fee_adjustments_credit_account_fk')->references(['id', 'school_id', 'currency'])->on('ledger_accounts')->restrictOnDelete();
            $table->foreign(['journal_entry_id', 'school_id', 'currency'], 'fee_adjustments_journal_entry_fk')->references(['id', 'school_id', 'currency'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['cancellation_journal_entry_id', 'school_id', 'currency'], 'fee_adjustments_cancellation_journal_entry_fk')->references(['id', 'school_id', 'currency'])->on('journal_entries')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE fee_adjustments ADD CONSTRAINT fee_adjustments_amount_positive_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE fee_adjustments ADD CONSTRAINT fee_adjustments_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement("ALTER TABLE fee_adjustments ADD CONSTRAINT fee_adjustments_category_check CHECK (category IN ('concession', 'scholarship', 'waiver'))");
        DB::statement('ALTER TABLE fee_adjustments ADD CONSTRAINT fee_adjustments_distinct_accounts_check CHECK (debit_ledger_account_id <> credit_ledger_account_id)');
        DB::statement(
            'ALTER TABLE fee_adjustments ADD CONSTRAINT fee_adjustments_cancellation_pair_check '.
            'CHECK ((cancelled_at IS NULL) = (cancellation_journal_entry_id IS NULL) AND (cancelled_at IS NOT NULL OR cancelled_by_user_id IS NULL))'
        );
        DB::statement(
            'CREATE UNIQUE INDEX fee_adjustments_one_live_per_concession_charge '.
            'ON fee_adjustments (fee_concession_id, charge_id) WHERE cancelled_at IS NULL'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_fee_adjustment() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                c record;
                fc record;
                fa record;
                period_start date;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a fee adjustment is always posted live'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT id, student_id, academic_year_id, receivable_ledger_account_id, currency, cancelled_at INTO c
                        FROM charges WHERE id = NEW.charge_id AND school_id = NEW.school_id;
                    IF NOT FOUND OR c.cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'fee adjustment charge % must be an uncancelled charge of the same School', NEW.charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.credit_ledger_account_id IS DISTINCT FROM c.receivable_ledger_account_id OR NEW.currency IS DISTINCT FROM c.currency THEN
                        RAISE EXCEPTION 'a fee adjustment must credit its charge''s own receivable account'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM ledger_accounts
                        WHERE id = NEW.debit_ledger_account_id AND school_id = NEW.school_id AND type = 'expense' AND status = 'active'
                    ) THEN
                        RAISE EXCEPTION 'fee adjustment debit account % must be an active expense account of the same School', NEW.debit_ledger_account_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT * INTO fc FROM fee_concessions WHERE id = NEW.fee_concession_id AND school_id = NEW.school_id;
                    IF NOT FOUND OR fc.status <> 'approved' THEN
                        RAISE EXCEPTION 'fee adjustment needs an approved fee concession'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF fc.student_id <> c.student_id OR fc.academic_year_id <> c.academic_year_id OR fc.category <> NEW.category THEN
                        RAISE EXCEPTION 'fee adjustment concession must match its charge''s Student, year and category'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF fc.scope = 'targeted' AND (fc.charge_id <> NEW.charge_id OR NEW.fee_assessment_id IS NOT NULL) THEN
                        RAISE EXCEPTION 'a targeted concession adjusts only its own charge'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF fc.scope = 'standing' THEN
                        SELECT fee_head_id, fee_structure_installment_id, charge_id INTO fa
                            FROM fee_assessments WHERE id = NEW.fee_assessment_id AND school_id = NEW.school_id AND voided_at IS NULL;
                        IF NOT FOUND OR fa.charge_id <> NEW.charge_id THEN
                            RAISE EXCEPTION 'a standing concession adjusts only a live fee assessment of the charge'
                                USING ERRCODE = 'check_violation';
                        END IF;

                        SELECT period_starts_on INTO period_start FROM fee_structure_installments WHERE id = fa.fee_structure_installment_id;
                        IF (fc.fee_head_id IS NOT NULL AND fc.fee_head_id <> fa.fee_head_id)
                            OR period_start < fc.valid_from OR period_start > fc.valid_to THEN
                            RAISE EXCEPTION 'the standing concession does not cover this fee assessment'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.charge_id IS DISTINCT FROM OLD.charge_id
                    OR NEW.fee_concession_id IS DISTINCT FROM OLD.fee_concession_id
                    OR NEW.fee_assessment_id IS DISTINCT FROM OLD.fee_assessment_id
                    OR NEW.category IS DISTINCT FROM OLD.category
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.debit_ledger_account_id IS DISTINCT FROM OLD.debit_ledger_account_id
                    OR NEW.credit_ledger_account_id IS DISTINCT FROM OLD.credit_ledger_account_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.posted_by_user_id IS DISTINCT FROM OLD.posted_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'fee adjustment % posted fields are immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.cancelled_at IS NOT NULL AND (
                    NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
                    OR NEW.cancellation_journal_entry_id IS DISTINCT FROM OLD.cancellation_journal_entry_id
                    OR NEW.cancelled_by_user_id IS DISTINCT FROM OLD.cancelled_by_user_id
                ) THEN
                    RAISE EXCEPTION 'fee adjustment % cancellation is final and cannot be altered', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.cancellation_journal_entry_id IS NULL AND NEW.cancellation_journal_entry_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM journal_entries
                    WHERE id = NEW.cancellation_journal_entry_id AND reversal_of_journal_entry_id = OLD.journal_entry_id
                ) THEN
                    RAISE EXCEPTION 'fee adjustment % cancellation must reference the reversal of its own journal entry', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_adjustments_guard_trigger '.
            'BEFORE INSERT OR UPDATE ON fee_adjustments '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_fee_adjustment()'
        );

        // §14.7: never cancel a charge that still carries a live adjustment.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_reject_charge_cancel_with_adjustments() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF OLD.cancelled_at IS NULL AND NEW.cancelled_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM fee_adjustments WHERE charge_id = OLD.id AND cancelled_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'charge % has active fee adjustments; cancel them first', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER charges_fee_adjustment_guard_trigger '.
            'BEFORE UPDATE OF cancelled_at ON charges '.
            'FOR EACH ROW EXECUTE FUNCTION fees_reject_charge_cancel_with_adjustments()'
        );

        TenantRls::enable('fee_adjustments');
        TenantRls::revokeDelete('fee_adjustments');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS charges_fee_adjustment_guard_trigger ON charges');
        DB::statement('DROP FUNCTION IF EXISTS fees_reject_charge_cancel_with_adjustments()');
        DB::statement('DROP TRIGGER IF EXISTS fee_adjustments_guard_trigger ON fee_adjustments');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_fee_adjustment()');
        TenantRls::disable('fee_adjustments');
        Schema::dropIfExists('fee_adjustments');
    }
};
