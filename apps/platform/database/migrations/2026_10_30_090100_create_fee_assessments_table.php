<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.2 (ADR 0062 §11): `fee_assessments`, the durable link between a
     * fee structure's instalment and the ONE charge it produced for a
     * Student.
     *
     * - The idempotency invariant is structural:
     *   `fee_assessments_one_live_per_period` -- at most one LIVE
     *   (un-voided) assessment per School x Student x AcademicYear x fee
     *   head x billing period, whichever run, structure, successor, campus
     *   override or enrollment produced it. The executor inserts it in the
     *   same transaction as the charge; a duplicate attempt fails on this
     *   index and rolls the charge and its journal entry back. There is no
     *   check-then-insert anywhere (rule 30).
     * - The linked charge must belong to the same School, Student and
     *   AcademicYear and be uncancelled when linked
     *   (`fees_validate_fee_assessment`).
     * - Append-only except the one-time void (§11.3): voiding sets
     *   `voided_at`/`voided_by_user_id`/`void_reason` once, and the charge
     *   must be cancelled in the same transaction
     *   (`fee_assessments_void_requires_cancelled_charge`, deferred to
     *   commit). Conversely a fee-assessed charge cannot be cancelled while
     *   its assessment is live (`charges_fee_assessment_guard_trigger`),
     *   so a live assessment never points at a cancelled charge and a
     *   voided one always does. The void path voids first, then cancels.
     * - Never deleted (`TenantRls::revokeDelete`).
     */
    public function up(): void
    {
        Schema::create('fee_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('academic_year_id');
            $table->uuid('fee_head_id');
            $table->string('billing_period_key', 32);
            $table->uuid('fee_structure_id');
            $table->uuid('fee_structure_line_id');
            $table->uuid('fee_structure_installment_id');
            $table->uuid('student_enrollment_id');
            $table->uuid('fee_assessment_run_id')->nullable();
            $table->uuid('charge_id');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique('charge_id');
            $table->index(['school_id', 'student_id']);
            $table->index('fee_assessment_run_id');

            $table->foreign(['student_id', 'school_id'], 'fee_assessments_student_fk')->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'fee_assessments_academic_year_fk')->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'fee_assessments_fee_head_fk')->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
            $table->foreign(['fee_structure_id', 'school_id'], 'fee_assessments_structure_fk')->references(['id', 'school_id'])->on('fee_structures')->restrictOnDelete();
            $table->foreign(['fee_structure_line_id', 'school_id'], 'fee_assessments_line_fk')->references(['id', 'school_id'])->on('fee_structure_lines')->restrictOnDelete();
            $table->foreign(['fee_structure_installment_id', 'school_id'], 'fee_assessments_installment_fk')->references(['id', 'school_id'])->on('fee_structure_installments')->restrictOnDelete();
            $table->foreign(['student_enrollment_id', 'school_id'], 'fee_assessments_enrollment_fk')->references(['id', 'school_id'])->on('student_enrollments')->restrictOnDelete();
            $table->foreign(['fee_assessment_run_id', 'school_id'], 'fee_assessments_run_fk')->references(['id', 'school_id'])->on('fee_assessment_runs')->restrictOnDelete();
            $table->foreign(['charge_id', 'school_id'], 'fee_assessments_charge_fk')->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fee_assessments ADD CONSTRAINT fee_assessments_period_key_format_check CHECK (billing_period_key ~ '^[A-Z0-9][A-Z0-9_-]{0,31}$')");
        DB::statement('ALTER TABLE fee_assessments ADD CONSTRAINT fee_assessments_void_shape_check CHECK (voided_at IS NOT NULL OR (voided_by_user_id IS NULL AND void_reason IS NULL))');
        DB::statement(
            'CREATE UNIQUE INDEX fee_assessments_one_live_per_period ON fee_assessments '.
            '(school_id, student_id, academic_year_id, fee_head_id, billing_period_key) WHERE voided_at IS NULL'
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_fee_assessment() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a fee assessment is always created live'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM charges
                        WHERE id = NEW.charge_id AND school_id = NEW.school_id AND student_id = NEW.student_id
                          AND academic_year_id = NEW.academic_year_id AND cancelled_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'fee assessment charge % must be an uncancelled charge of the same Student and academic year', NEW.charge_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.fee_head_id IS DISTINCT FROM OLD.fee_head_id
                    OR NEW.billing_period_key IS DISTINCT FROM OLD.billing_period_key
                    OR NEW.fee_structure_id IS DISTINCT FROM OLD.fee_structure_id
                    OR NEW.fee_structure_line_id IS DISTINCT FROM OLD.fee_structure_line_id
                    OR NEW.fee_structure_installment_id IS DISTINCT FROM OLD.fee_structure_installment_id
                    OR NEW.student_enrollment_id IS DISTINCT FROM OLD.student_enrollment_id
                    OR NEW.fee_assessment_run_id IS DISTINCT FROM OLD.fee_assessment_run_id
                    OR NEW.charge_id IS DISTINCT FROM OLD.charge_id
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'fee assessment % is immutable except its one-time void', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL AND (
                    NEW.voided_at IS DISTINCT FROM OLD.voided_at
                    OR NEW.voided_by_user_id IS DISTINCT FROM OLD.voided_by_user_id
                    OR NEW.void_reason IS DISTINCT FROM OLD.void_reason
                ) THEN
                    RAISE EXCEPTION 'fee assessment % is voided and final', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_assessments_guard_trigger '.
            'BEFORE INSERT OR UPDATE ON fee_assessments '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_fee_assessment()'
        );

        // A voided assessment's charge must be cancelled by commit.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_require_cancelled_charge_for_void() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NEW.voided_at IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM charges WHERE id = NEW.charge_id AND cancelled_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'fee assessment % was voided but its charge % was not cancelled in the same transaction', NEW.id, NEW.charge_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE CONSTRAINT TRIGGER fee_assessments_void_requires_cancelled_charge '.
            'AFTER UPDATE OF voided_at ON fee_assessments '.
            'DEFERRABLE INITIALLY DEFERRED '.
            'FOR EACH ROW EXECUTE FUNCTION fees_require_cancelled_charge_for_void()'
        );

        // A fee-assessed charge is cancelled only through the void path.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_reject_cancelling_live_assessed_charge() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF NEW.cancelled_at IS NOT NULL AND OLD.cancelled_at IS NULL AND EXISTS (
                    SELECT 1 FROM fee_assessments WHERE charge_id = NEW.id AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'charge % is fee-assessed; void its fee assessment to cancel it', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER charges_fee_assessment_guard_trigger '.
            'BEFORE UPDATE OF cancelled_at ON charges '.
            'FOR EACH ROW EXECUTE FUNCTION fees_reject_cancelling_live_assessed_charge()'
        );

        TenantRls::enable('fee_assessments');
        TenantRls::revokeDelete('fee_assessments');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS charges_fee_assessment_guard_trigger ON charges');
        DB::statement('DROP FUNCTION IF EXISTS fees_reject_cancelling_live_assessed_charge()');
        DB::statement('DROP TRIGGER IF EXISTS fee_assessments_void_requires_cancelled_charge ON fee_assessments');
        DB::statement('DROP FUNCTION IF EXISTS fees_require_cancelled_charge_for_void()');
        DB::statement('DROP TRIGGER IF EXISTS fee_assessments_guard_trigger ON fee_assessments');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_fee_assessment()');
        TenantRls::disable('fee_assessments');
        Schema::dropIfExists('fee_assessments');
    }
};
