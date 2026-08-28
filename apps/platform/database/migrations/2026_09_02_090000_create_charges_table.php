<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0G.4 (docs/modules/FINANCE.md "Fees / invoices scope",
     * "Financial subject vs. payer"): the one receivable obligation
     * entity for this checkpoint. Owned by the new `App\Domain\Fees`
     * module (DOMAIN-MAP.md's separate "Fees" row -- depends on
     * Students/SIS, Academic Structure, Finance -- never the reverse),
     * NOT `App\Domain\Finance`, so Finance's own committed dependency
     * position ("no dependency on ... Students/SIS", FINANCE.md
     * "Dependency position") stays true.
     *
     * Scope resolved for 0G.4 (recorded here since FINANCE.md left all
     * three open as explicit "0G.4 design questions"):
     * - No separate `invoices`/`invoice_lines` entity -- `charges`
     *   alone is the receivables unit (FINANCE.md "Invoice decision").
     * - No fee-definition/template catalog -- every charge carries its
     *   own amount/description/account mapping directly; FINANCE.md's
     *   Core Entities table never names a template entity for 0G.4.
     * - Financial subject is Student only (`student_id`, NOT NULL) --
     *   FINANCE.md's own "near-certain first case"; Employee/Applicant
     *   exclusive-arc columns are added only when a future checkpoint
     *   actually needs them (Payroll/Admissions boundaries, untouched
     *   here), never speculatively pre-added.
     *
     * Recognition point: IMMEDIATE. A `charges` row is created and its
     * corresponding `journal_entries` posting happen inside ONE
     * database transaction (`App\Domain\Fees\Application\ChargeService::assess()`)
     * -- there is no draft/mutable-amount state, matching ADR 0030's
     * "once it exists, it is a posted fact" philosophy applied one
     * layer up. `journal_entry_id` is therefore NOT NULL: a charge
     * without a posting can never exist.
     *
     * Correction (closure review): every recognized financial/identity
     * field (`id`, `school_id`, `student_id`, `academic_year_id`,
     * `description`, `amount`, `currency`, `due_date`,
     * `receivable_ledger_account_id`, `revenue_ledger_account_id`,
     * `journal_entry_id`, `created_at`) is now DATABASE-immutable after
     * insert -- `fees_reject_recognized_charge_mutation()` (a `BEFORE
     * UPDATE ... FOR EACH ROW` trigger, below) rejects any `UPDATE`
     * that changes one of them, comparing OLD vs. NEW with `IS DISTINCT
     * FROM`. An Application service simply lacking an `update()` method
     * was judged insufficient (a raw/future caller could otherwise
     * alter financial facts without touching the ledger) -- the
     * database, not `ChargeService`, is now authoritative. The ONE
     * legitimate mutation is the cancellation pair
     * (`cancelled_at`/`cancellation_journal_entry_id`, plus the
     * ordinary `updated_at` timestamp Eloquent's query-builder `update()`
     * always touches), and even that is allowed only:
     *   (a) once (the trigger also rejects any further change once
     *       `cancelled_at` is already non-null -- no "uncancel", no
     *       repointing to a different reversal), and
     *   (b) when semantically true -- the trigger additionally requires
     *       `cancellation_journal_entry_id` to name a `journal_entries`
     *       row whose `reversal_of_journal_entry_id` actually equals
     *       THIS charge's own `journal_entry_id`, not merely a same-
     *       School/same-currency journal entry (which the composite
     *       foreign key alone would accept). This is a genuine
     *       cross-row invariant a `CHECK` constraint cannot express, so
     *       a trigger is the appropriate mechanism, exactly as
     *       ADR 0030's own deferred balance-check trigger already
     *       established the precedent for.
     *
     * The trigger function is `LANGUAGE plpgsql SECURITY INVOKER`
     * (the default -- written explicitly for clarity) -- it queries
     * `journal_entries` under the CALLING role's own RLS visibility,
     * never elevated privilege. `App\Domain\Fees\Application\ChargeService::cancel()`
     * already wraps its entire transaction in
     * `TenantContext::withSchool()`, so the RLS session GUC is correctly
     * set for the trigger's subquery at UPDATE time.
     *
     * `unique(['school_id', 'journal_entry_id'])`/
     * `unique(['school_id', 'cancellation_journal_entry_id'])`
     * (closure review, section 9/10): one assessed charge creates one
     * dedicated ledger posting, and one cancellation reversal belongs
     * to one charge -- 0G.4 never shares a posting across multiple
     * `charges` rows (`ChargeService::assess()`/`cancel()` always call
     * `LedgerService::post()`/`reverseById()` fresh, once, per charge).
     * A plain (non-partial) unique index is correct for the nullable
     * `cancellation_journal_entry_id` column -- PostgreSQL's ordinary
     * unique-constraint semantics already treat multiple `NULL`s as
     * non-conflicting, so uncancelled charges never collide.
     *
     * `TenantRls::revokeDelete('charges')` (closure review, section 4):
     * narrower than `TenantRls::makeAppendOnly()` (which would also
     * revoke the one legitimate UPDATE path this table has) -- a
     * recognized charge must never be hard-deleted while its journal
     * posting remains, but IS allowed its one narrow UPDATE. A cascade
     * delete via `schools.id` `ON DELETE CASCADE` is unaffected (see
     * `TenantRls::revokeDelete()`'s own docblock).
     *
     * Composite foreign keys (rule 10): `student_id`/`academic_year_id`
     * prove same-School membership structurally against
     * `students`/`academic_years` (both already carry a
     * `unique(['id','school_id'])` from their own checkpoints) --
     * `App\Domain\Fees` never reads either module's Eloquent model
     * directly (CLAUDE.md rule 4); a cross-School or nonexistent id is
     * caught by these constraints alone, not an application
     * pre-check. `receivable_ledger_account_id`/`revenue_ledger_account_id`/
     * `journal_entry_id`/`cancellation_journal_entry_id` additionally
     * pin `currency`, mirroring `journal_lines`' own
     * `(id, school_id, currency)` pattern against `ledger_accounts`/
     * `journal_entries` -- Fees never reads Finance's `LedgerAccount`/
     * `JournalEntry` Eloquent models directly either; `App\Domain\Fees\Application\ChargeService`
     * only ever calls `App\Domain\Finance\Application\LedgerService`'s
     * public methods.
     *
     * `charges_distinct_accounts_check` rejects a charge naming the
     * SAME ledger account as both receivable and revenue side -- a
     * structurally nonsensical two-line journal entry (debiting and
     * crediting one account nets to nothing) that `LedgerService`
     * itself does not reject (it has no opinion on which accounts a
     * caller names).
     *
     * `unique(['id', 'school_id'])` is added now, unused by any child
     * table yet, following `students`'/`academic_years`' own
     * established precedent -- so a future 0G.5 `payment_allocations`
     * composite foreign key can reference `(id, school_id)` without a
     * later migration adding it retroactively.
     *
     * Account-type semantic validation (e.g. "receivable account must
     * be `asset`, revenue account must be `income`") is NOT enforced
     * here or at the Application layer -- FINANCE.md does not settle
     * this rule for 0G.4 (rule 13's "do not invent accounting
     * restrictions silently"), and `LedgerService::post()` itself has
     * no opinion on account type vs. posting side for any caller. This
     * mirrors 0G.2/0G.3's own explicitly-deferred "account-status-
     * blocks-posting" decision -- both remain open questions for a
     * future checkpoint, not resolved by 0G.4.
     */
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('academic_year_id');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->date('due_date')->nullable();
            $table->uuid('receivable_ledger_account_id');
            $table->uuid('revenue_ledger_account_id');
            $table->uuid('journal_entry_id');
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancellation_journal_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'journal_entry_id']);
            $table->unique(['school_id', 'cancellation_journal_entry_id']);
            $table->index('school_id');
            $table->index(['school_id', 'student_id']);
            $table->index(['school_id', 'academic_year_id']);
            $table->index(['school_id', 'created_at']);

            $table->foreign(['student_id', 'school_id'], 'charges_student_fk')
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();

            $table->foreign(['academic_year_id', 'school_id'], 'charges_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['receivable_ledger_account_id', 'school_id', 'currency'], 'charges_receivable_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();

            $table->foreign(['revenue_ledger_account_id', 'school_id', 'currency'], 'charges_revenue_account_fk')
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();

            $table->foreign(['journal_entry_id', 'school_id', 'currency'], 'charges_journal_entry_fk')
                ->references(['id', 'school_id', 'currency'])->on('journal_entries')
                ->restrictOnDelete();

            $table->foreign(['cancellation_journal_entry_id', 'school_id', 'currency'], 'charges_cancellation_journal_entry_fk')
                ->references(['id', 'school_id', 'currency'])->on('journal_entries')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE charges ADD CONSTRAINT charges_amount_positive_check '.
            'CHECK (amount > 0)'
        );

        DB::statement(
            'ALTER TABLE charges ADD CONSTRAINT charges_currency_inr_only_check '.
            "CHECK (currency = 'INR')"
        );

        DB::statement(
            'ALTER TABLE charges ADD CONSTRAINT charges_distinct_accounts_check '.
            'CHECK (receivable_ledger_account_id <> revenue_ledger_account_id)'
        );

        // Both NULL (never cancelled) or both set together (cancelled)
        // -- never one without the other. The trigger below layers
        // "immutable once set" and "semantically true" on top of this.
        DB::statement(
            'ALTER TABLE charges ADD CONSTRAINT charges_cancellation_pair_check '.
            'CHECK ((cancelled_at IS NULL) = (cancellation_journal_entry_id IS NULL))'
        );

        // Database-authoritative recognized-field immutability +
        // semantic cancellation-link validation -- see this migration's
        // docblock ("Correction (closure review)") for the full
        // rationale. SECURITY INVOKER (the default; written explicitly)
        // so the journal_entries subquery below runs under the calling
        // role's own RLS visibility, never elevated.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_reject_recognized_charge_mutation() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.receivable_ledger_account_id IS DISTINCT FROM OLD.receivable_ledger_account_id
                    OR NEW.revenue_ledger_account_id IS DISTINCT FROM OLD.revenue_ledger_account_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'charge % recognized financial fields are immutable after creation', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.cancelled_at IS NOT NULL AND (
                    NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
                    OR NEW.cancellation_journal_entry_id IS DISTINCT FROM OLD.cancellation_journal_entry_id
                ) THEN
                    RAISE EXCEPTION 'charge % cancellation is final and cannot be altered', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.cancellation_journal_entry_id IS NULL AND NEW.cancellation_journal_entry_id IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM journal_entries
                        WHERE id = NEW.cancellation_journal_entry_id
                          AND school_id = NEW.school_id
                          AND reversal_of_journal_entry_id = NEW.journal_entry_id
                    ) THEN
                        RAISE EXCEPTION 'cancellation_journal_entry_id % is not the reversal of charge %''s recognition journal %',
                            NEW.cancellation_journal_entry_id, NEW.id, NEW.journal_entry_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER charges_immutability_trigger '.
            'BEFORE UPDATE ON charges '.
            'FOR EACH ROW EXECUTE FUNCTION fees_reject_recognized_charge_mutation()'
        );

        TenantRls::enable('charges');
        TenantRls::revokeDelete('charges');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS charges_immutability_trigger ON charges');
        DB::statement('DROP FUNCTION IF EXISTS fees_reject_recognized_charge_mutation()');
        TenantRls::disable('charges');
        Schema::dropIfExists('charges');
    }
};
