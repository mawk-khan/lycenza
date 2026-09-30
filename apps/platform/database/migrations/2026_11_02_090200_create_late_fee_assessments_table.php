<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.5 (ADR 0062 §16.3; owner decision H): Payments-owned
     * `late_fee_assessments`, the durable link source charge -> late-fee
     * rule -> late-fee charge -> run.
     *
     * - `late_fee_assessments_one_live_per_rule`:
     *   UNIQUE (school_id, source_charge_id, fee_late_fee_rule_id)
     *   WHERE voided_at IS NULL -- the authoritative "one live late fee per
     *   source charge per rule" guarantee (no existence pre-check decides).
     * - At insert (`payments_validate_late_fee_assessment`):
     *   - the source is a live structure-generated charge (a live
     *     `fee_assessments` row) in the rule's scope, uncancelled, and not
     *     itself a late fee -- so a late fee never attracts a late fee;
     *   - the late-fee charge is a new, uncancelled charge of the same
     *     Student and year, on the rule's late-fee head accounts, due on
     *     the run's evaluation date, with no fee assessment of its own;
     *   - the run is executing, for this rule; the rule is active.
     * - Immutable except the one-time void (§11.3), which must cancel the
     *   late-fee charge in the same transaction (deferred check). Voiding
     *   frees the key for one deliberate later assessment.
     * - `charges_late_fee_guard_trigger`: a live late-fee charge is
     *   cancelled only through the void, and a charge that is the SOURCE of
     *   a live late fee cannot be cancelled until that late fee is voided.
     * - RLS forced; never deleted.
     */
    public function up(): void
    {
        Schema::create('late_fee_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('source_charge_id');
            $table->uuid('fee_late_fee_rule_id');
            $table->uuid('charge_id');
            $table->uuid('late_fee_run_id');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique('charge_id', 'late_fee_assessments_charge_unique');
            $table->index('source_charge_id');
            $table->index('fee_late_fee_rule_id');
            $table->index('late_fee_run_id');

            $table->foreign(['source_charge_id', 'school_id'], 'late_fee_assessments_source_charge_fk')->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
            $table->foreign(['charge_id', 'school_id'], 'late_fee_assessments_charge_fk')->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
            $table->foreign(['fee_late_fee_rule_id', 'school_id'], 'late_fee_assessments_rule_fk')->references(['id', 'school_id'])->on('fee_late_fee_rules')->restrictOnDelete();
            $table->foreign(['late_fee_run_id', 'school_id'], 'late_fee_assessments_run_fk')->references(['id', 'school_id'])->on('late_fee_runs')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE late_fee_assessments ADD CONSTRAINT late_fee_assessments_distinct_charges_check CHECK (source_charge_id <> charge_id)');
        DB::statement('ALTER TABLE late_fee_assessments ADD CONSTRAINT late_fee_assessments_void_shape_check CHECK (voided_at IS NOT NULL OR (voided_by_user_id IS NULL AND void_reason IS NULL))');
        DB::statement(
            'CREATE UNIQUE INDEX late_fee_assessments_one_live_per_rule ON late_fee_assessments '.
            '(school_id, source_charge_id, fee_late_fee_rule_id) WHERE voided_at IS NULL'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_validate_late_fee_assessment() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                src record;
                fee record;
                rule record;
                head record;
                run record;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a late-fee assessment is always created live'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT * INTO rule FROM fee_late_fee_rules WHERE id = NEW.fee_late_fee_rule_id AND school_id = NEW.school_id;
                    IF NOT FOUND OR rule.status <> 'active' THEN
                        RAISE EXCEPTION 'a late fee needs an active late-fee rule'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT * INTO run FROM late_fee_runs WHERE id = NEW.late_fee_run_id AND school_id = NEW.school_id;
                    IF NOT FOUND OR run.status <> 'executing' OR run.fee_late_fee_rule_id <> NEW.fee_late_fee_rule_id THEN
                        RAISE EXCEPTION 'a late fee needs an executing run of the same rule'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT c.* INTO src FROM charges c WHERE c.id = NEW.source_charge_id AND c.school_id = NEW.school_id;
                    IF NOT FOUND OR src.cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'late fee source charge % must be an uncancelled charge of the same School', NEW.source_charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF EXISTS (SELECT 1 FROM late_fee_assessments WHERE charge_id = NEW.source_charge_id) THEN
                        RAISE EXCEPTION 'a late fee never attracts a late fee (source charge % is a late fee)', NEW.source_charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM fee_assessments fa
                        WHERE fa.charge_id = NEW.source_charge_id AND fa.voided_at IS NULL
                          AND fa.fee_structure_id = rule.fee_structure_id
                          AND (rule.fee_head_id IS NULL OR fa.fee_head_id = rule.fee_head_id)
                    ) THEN
                        RAISE EXCEPTION 'late fee source charge % is not a live structure charge in the rule''s scope', NEW.source_charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT c.* INTO fee FROM charges c WHERE c.id = NEW.charge_id AND c.school_id = NEW.school_id;
                    SELECT h.* INTO head FROM fee_heads h WHERE h.id = rule.late_fee_head_id;
                    IF fee.id IS NULL OR fee.cancelled_at IS NOT NULL
                        OR fee.student_id <> src.student_id OR fee.academic_year_id <> src.academic_year_id
                        OR fee.receivable_ledger_account_id <> head.receivable_ledger_account_id
                        OR fee.revenue_ledger_account_id <> head.revenue_ledger_account_id
                        OR fee.due_date IS DISTINCT FROM run.evaluation_date
                        OR EXISTS (SELECT 1 FROM fee_assessments WHERE charge_id = NEW.charge_id)
                    THEN
                        RAISE EXCEPTION 'late-fee charge % must be a new charge of the source''s Student and year on the late-fee head, due on the evaluation date', NEW.charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.source_charge_id IS DISTINCT FROM OLD.source_charge_id
                    OR NEW.fee_late_fee_rule_id IS DISTINCT FROM OLD.fee_late_fee_rule_id
                    OR NEW.charge_id IS DISTINCT FROM OLD.charge_id
                    OR NEW.late_fee_run_id IS DISTINCT FROM OLD.late_fee_run_id
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'late-fee assessment % is immutable except its one-time void', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL AND (
                    NEW.voided_at IS DISTINCT FROM OLD.voided_at
                    OR NEW.voided_by_user_id IS DISTINCT FROM OLD.voided_by_user_id
                    OR NEW.void_reason IS DISTINCT FROM OLD.void_reason
                ) THEN
                    RAISE EXCEPTION 'late-fee assessment % is voided and final', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER late_fee_assessments_guard_trigger '.
            'BEFORE INSERT OR UPDATE ON late_fee_assessments '.
            'FOR EACH ROW EXECUTE FUNCTION payments_validate_late_fee_assessment()'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_require_cancelled_late_fee_charge_for_void() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NEW.voided_at IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM charges WHERE id = NEW.charge_id AND cancelled_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'late-fee assessment % was voided but its charge % was not cancelled in the same transaction', NEW.id, NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE CONSTRAINT TRIGGER late_fee_assessments_void_requires_cancelled_charge '.
            'AFTER UPDATE OF voided_at ON late_fee_assessments '.
            'DEFERRABLE INITIALLY DEFERRED '.
            'FOR EACH ROW EXECUTE FUNCTION payments_require_cancelled_late_fee_charge_for_void()'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payments_reject_cancelling_late_fee_charges() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NEW.cancelled_at IS NOT NULL AND OLD.cancelled_at IS NULL THEN
                    IF EXISTS (SELECT 1 FROM late_fee_assessments WHERE charge_id = NEW.id AND voided_at IS NULL) THEN
                        RAISE EXCEPTION 'charge % is a late fee; void its late-fee assessment to cancel it', NEW.id
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF EXISTS (SELECT 1 FROM late_fee_assessments WHERE source_charge_id = NEW.id AND voided_at IS NULL) THEN
                        RAISE EXCEPTION 'charge % has a live late fee; void the late fee first', NEW.id
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER charges_late_fee_guard_trigger '.
            'BEFORE UPDATE OF cancelled_at ON charges '.
            'FOR EACH ROW EXECUTE FUNCTION payments_reject_cancelling_late_fee_charges()'
        );

        TenantRls::enable('late_fee_assessments');
        TenantRls::revokeDelete('late_fee_assessments');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS charges_late_fee_guard_trigger ON charges');
        DB::statement('DROP FUNCTION IF EXISTS payments_reject_cancelling_late_fee_charges()');
        DB::statement('DROP TRIGGER IF EXISTS late_fee_assessments_void_requires_cancelled_charge ON late_fee_assessments');
        DB::statement('DROP FUNCTION IF EXISTS payments_require_cancelled_late_fee_charge_for_void()');
        DB::statement('DROP TRIGGER IF EXISTS late_fee_assessments_guard_trigger ON late_fee_assessments');
        DB::statement('DROP FUNCTION IF EXISTS payments_validate_late_fee_assessment()');
        TenantRls::disable('late_fee_assessments');
        Schema::dropIfExists('late_fee_assessments');
    }
};
