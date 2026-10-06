<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPF.4 (ADR 0067 §17, D3, D4, D6): Library overdue fines -- a Library-owned
 * versioned fine policy, and one EVENT charge per overdue loan through FEE's
 * trusted event-charge seam. Library owns the rule and the evidence; FEE owns
 * the charge and the ledger; FEE never reads these tables (§4). No lost or
 * damaged charging, no daily accumulation, no refund.
 *
 * `library_fine_policies` -- immutable, numbered versions per School
 * (`library_fine_policies_one_version`). The School's highest version
 * governs; a change is a NEW version, so a version a fine used can never
 * change (insert-only for the runtime role). An `active` version carries the
 * FEE fee head (ledger destination), a daily rate, grace days and an
 * optional cap; a `disabled` version switches fines off and carries none.
 * Finance configuration (tenant lifetime).
 *
 * `library_fines` -- Finance evidence: one fine per loan and kind
 * (`library_fines_one_per_loan_kind`; v1 kind `overdue`), one per charge.
 * It snapshots the loan's due and check-in instants, the overdue and
 * chargeable days and the amount, and names the policy version, fee head,
 * academic year and the FEE charge. A trigger re-derives the amount from the
 * policy version and proves the loan, Student and charge agree.
 * Insert-only; E21-RH.7 anchor and delete guard.
 *
 * `library_fine_voids` -- the D4 void of an erroneously assessed fine: one
 * per fine, insert-only (the fine row itself never changes). Its charge must
 * be cancelled in the same transaction (deferred constraint trigger), and a
 * fine's charge cannot be cancelled any other way while the fine is unvoided
 * (`charges_library_fine_guard_trigger`, like
 * `charges_fee_assessment_guard_trigger`). Cancellation still refuses a
 * charge with payment allocations or live adjustments, so a paid or waived
 * fine cannot be voided. No refund.
 *
 * Rollback drops the three tables, their functions and the charges guard;
 * it restores no privilege and no retention bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_fine_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16); // active|disabled
            $table->uuid('fee_head_id')->nullable();
            $table->decimal('daily_rate', 12, 2)->nullable();
            $table->unsignedInteger('grace_days')->nullable();
            $table->decimal('max_amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'version'], 'library_fine_policies_one_version');

            $table->foreign(['fee_head_id', 'school_id'], 'library_fine_policies_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE library_fine_policies ADD CONSTRAINT library_fine_policies_shape_check CHECK (
                version >= 1 AND currency = 'INR' AND (
                    (status = 'active' AND fee_head_id IS NOT NULL AND daily_rate > 0 AND grace_days IS NOT NULL AND grace_days >= 0
                        AND (max_amount IS NULL OR max_amount > 0))
                    OR (status = 'disabled' AND fee_head_id IS NULL AND daily_rate IS NULL AND grace_days IS NULL AND max_amount IS NULL)))
            SQL);
        TenantRls::enable('library_fine_policies');
        TenantRls::makeAppendOnly('library_fine_policies');

        Schema::create('library_fines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('library_loan_id');
            $table->uuid('student_id');
            $table->string('kind', 16); // overdue
            $table->uuid('library_fine_policy_id');
            $table->uuid('fee_head_id');
            $table->uuid('academic_year_id');
            $table->timestamp('due_at');
            $table->timestamp('checked_in_at');
            $table->unsignedInteger('overdue_days');
            $table->unsignedInteger('chargeable_days');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->uuid('charge_id');
            $table->foreignUuid('assessed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'library_loan_id', 'kind'], 'library_fines_one_per_loan_kind');
            $table->unique(['charge_id'], 'library_fines_one_per_charge');
            $table->index(['school_id', 'student_id']);

            $table->foreign(['library_loan_id', 'school_id'], 'library_fines_loan_fk')
                ->references(['id', 'school_id'])->on('library_loans')->restrictOnDelete();
            $table->foreign(['student_id', 'school_id'], 'library_fines_student_fk')
                ->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['library_fine_policy_id', 'school_id'], 'library_fines_policy_fk')
                ->references(['id', 'school_id'])->on('library_fine_policies')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'library_fines_fee_head_fk')
                ->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'library_fines_academic_year_fk')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['charge_id', 'school_id'], 'library_fines_charge_fk')
                ->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE library_fines ADD CONSTRAINT library_fines_shape_check CHECK (
                kind IN ('overdue') AND currency = 'INR' AND amount > 0 AND chargeable_days >= 1
                AND overdue_days >= chargeable_days AND checked_in_at > due_at)
            SQL);

        Schema::create('library_fine_voids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('library_fine_id');
            $table->string('reason', 255);
            $table->foreignUuid('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['id', 'school_id']);
            $table->unique(['library_fine_id'], 'library_fine_voids_one_per_fine');

            $table->foreign(['library_fine_id', 'school_id'], 'library_fine_voids_fine_fk')
                ->references(['id', 'school_id'])->on('library_fines')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE library_fine_voids ADD CONSTRAINT library_fine_voids_reason_check CHECK (length(btrim(reason)) > 0)');

        DB::unprepared(<<<'SQL'
            -- A fine is derived, never asserted: the loan, the policy version and the charge must all agree.
            CREATE FUNCTION library_fines_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_policy record;
                v_overdue integer;
                v_chargeable integer;
                v_amount numeric(12, 2);
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.library_loans l
                     WHERE l.id = NEW.library_loan_id AND l.school_id = NEW.school_id AND l.student_id = NEW.student_id
                       AND l.status = 'returned' AND l.due_at = NEW.due_at AND l.checked_in_at = NEW.checked_in_at
                ) THEN
                    RAISE EXCEPTION 'library_fines: the fine does not match its returned loan' USING ERRCODE = 'check_violation';
                END IF;

                SELECT status, fee_head_id, daily_rate, grace_days, max_amount INTO v_policy
                  FROM public.library_fine_policies WHERE id = NEW.library_fine_policy_id AND school_id = NEW.school_id;
                IF v_policy.status IS DISTINCT FROM 'active' OR v_policy.fee_head_id IS DISTINCT FROM NEW.fee_head_id THEN
                    RAISE EXCEPTION 'library_fines: the policy version is not an active version for this fee head' USING ERRCODE = 'check_violation';
                END IF;
                v_overdue := ceil(extract(epoch FROM (NEW.checked_in_at - NEW.due_at)) / 86400)::integer;
                v_chargeable := greatest(v_overdue - v_policy.grace_days, 0);
                v_amount := v_chargeable * v_policy.daily_rate;
                IF v_policy.max_amount IS NOT NULL AND v_amount > v_policy.max_amount THEN
                    v_amount := v_policy.max_amount;
                END IF;
                IF NEW.overdue_days <> v_overdue OR NEW.chargeable_days <> v_chargeable OR NEW.amount <> v_amount THEN
                    RAISE EXCEPTION 'library_fines: the amount is not the policy version''s amount for this loan' USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM public.charges c JOIN public.fee_heads h ON h.id = NEW.fee_head_id AND h.school_id = NEW.school_id
                     WHERE c.id = NEW.charge_id AND c.school_id = NEW.school_id AND c.student_id = NEW.student_id
                       AND c.academic_year_id = NEW.academic_year_id AND c.amount = NEW.amount AND c.currency = NEW.currency
                       AND c.cancelled_at IS NULL
                       AND c.receivable_ledger_account_id = h.receivable_ledger_account_id
                       AND c.revenue_ledger_account_id = h.revenue_ledger_account_id
                ) THEN
                    RAISE EXCEPTION 'library_fines: the charge is not this fine''s Student, year, amount and fee head accounts' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION library_fines_guard() FROM PUBLIC;
            CREATE TRIGGER library_fines_guard_trigger BEFORE INSERT ON library_fines
                FOR EACH ROW EXECUTE FUNCTION library_fines_guard();

            -- D4: a void is recorded with the cancellation of its charge, in one transaction.
            CREATE FUNCTION library_fine_voids_require_cancelled_charge() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.library_fines f JOIN public.charges c ON c.id = f.charge_id AND c.school_id = f.school_id
                     WHERE f.id = NEW.library_fine_id AND f.school_id = NEW.school_id AND c.cancelled_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'library fine % was voided but its charge was not cancelled in the same transaction', NEW.library_fine_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$;
            REVOKE ALL ON FUNCTION library_fine_voids_require_cancelled_charge() FROM PUBLIC;
            CREATE CONSTRAINT TRIGGER library_fine_voids_require_cancelled_charge AFTER INSERT ON library_fine_voids
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION library_fine_voids_require_cancelled_charge();

            -- D4: a fine's charge is cancelled only through the Library void path (void first, then cancel).
            CREATE FUNCTION library_reject_cancelling_live_fine_charge() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NEW.cancelled_at IS NOT NULL AND OLD.cancelled_at IS NULL AND EXISTS (
                    SELECT 1 FROM public.library_fines f
                     WHERE f.charge_id = NEW.id AND f.school_id = NEW.school_id
                       AND NOT EXISTS (SELECT 1 FROM public.library_fine_voids v WHERE v.library_fine_id = f.id AND v.school_id = f.school_id)
                ) THEN
                    RAISE EXCEPTION 'charge % is a source event charge; void it at its source to cancel it', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION library_reject_cancelling_live_fine_charge() FROM PUBLIC;
            CREATE TRIGGER charges_library_fine_guard_trigger BEFORE UPDATE OF cancelled_at ON charges
                FOR EACH ROW EXECUTE FUNCTION library_reject_cancelling_live_fine_charge();

            -- E21-RH.7 (ADR 0066 §15): the database-recorded anchors (links tracked) and the retention delete guards.
            ALTER TABLE library_fines ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON library_fines FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('library_loan_id', 'student_id', 'library_fine_policy_id', 'fee_head_id', 'academic_year_id', 'charge_id');
            CREATE TRIGGER trg_retention_guard_library_fines AFTER DELETE ON library_fines
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            ALTER TABLE library_fine_voids ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON library_fine_voids FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('library_fine_id');
            CREATE TRIGGER trg_retention_guard_library_fine_voids AFTER DELETE ON library_fine_voids
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');
            SQL);

        TenantRls::enable('library_fines');
        TenantRls::makeAppendOnly('library_fines');
        TenantRls::enable('library_fine_voids');
        TenantRls::makeAppendOnly('library_fine_voids');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS charges_library_fine_guard_trigger ON charges;
            DROP FUNCTION IF EXISTS library_reject_cancelling_live_fine_charge();
            SQL);
        TenantRls::disable('library_fine_voids');
        Schema::dropIfExists('library_fine_voids');
        DB::unprepared('DROP FUNCTION IF EXISTS library_fine_voids_require_cancelled_charge()');
        TenantRls::disable('library_fines');
        Schema::dropIfExists('library_fines');
        DB::unprepared('DROP FUNCTION IF EXISTS library_fines_guard()');
        TenantRls::disable('library_fine_policies');
        Schema::dropIfExists('library_fine_policies');
    }
};
