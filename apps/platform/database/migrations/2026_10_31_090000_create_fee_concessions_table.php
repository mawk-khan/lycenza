<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.3 (ADR 0062 §14, owner decisions F, G1, M): `fee_concessions`, the
     * approvable concession REQUEST. It is never a financial posting; its
     * effect is a separate `fee_adjustments` row posted only after approval.
     *
     * - Scope (§14.1): `targeted` = one charge (fixed amount only);
     *   `standing` = Student x AcademicYear x fee head (NULL = every head) x
     *   inclusive validity window inside the year, applied when FEE.2
     *   assessment creates matching charges.
     * - Value: `fixed` (NUMERIC(14,2) > 0) or `percentage` (0 < p <= 100).
     * - Category (M): closed catalogue `concession | scholarship | waiver`.
     *   There is deliberately no note / free-text reason column.
     * - Lifecycle (F): `pending -> approved | rejected | withdrawn`;
     *   `approved -> revoked` for standing concessions only; everything else
     *   is final (`fees_validate_fee_concession`).
     * - Separation of duties (F): `fee_concessions_sod_check` -- the decider
     *   is never the requester (the payroll_runs_sod_check precedent).
     * - Requests carry a server-issued idempotency key
     *   (`fee_concessions_idempotency_unique`).
     * - A targeted concession's charge must be the same Student's, same
     *   year's charge; a standing window must lie inside its year.
     * - RLS forced, composite same-School FKs, never deleted.
     */
    public function up(): void
    {
        Schema::create('fee_concessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('academic_year_id');
            $table->string('category');
            $table->string('scope');
            $table->uuid('charge_id')->nullable();
            $table->uuid('fee_head_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('kind');
            $table->decimal('fixed_amount', 14, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->string('status')->default('pending');
            $table->uuid('idempotency_key');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'idempotency_key'], 'fee_concessions_idempotency_unique');
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'student_id', 'academic_year_id']);
            $table->index('charge_id');
            $table->index('fee_head_id');

            $table->foreign(['student_id', 'school_id'], 'fee_concessions_student_fk')->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'fee_concessions_academic_year_fk')->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['charge_id', 'school_id'], 'fee_concessions_charge_fk')->references(['id', 'school_id'])->on('charges')->restrictOnDelete();
            $table->foreign(['fee_head_id', 'school_id'], 'fee_concessions_fee_head_fk')->references(['id', 'school_id'])->on('fee_heads')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fee_concessions ADD CONSTRAINT fee_concessions_category_check CHECK (category IN ('concession', 'scholarship', 'waiver'))");
        DB::statement("ALTER TABLE fee_concessions ADD CONSTRAINT fee_concessions_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'revoked'))");
        DB::statement("ALTER TABLE fee_concessions ADD CONSTRAINT fee_concessions_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE fee_concessions ADD CONSTRAINT fee_concessions_sod_check CHECK (decided_by_user_id IS NULL OR decided_by_user_id <> requested_by_user_id)');
        DB::statement(
            'ALTER TABLE fee_concessions ADD CONSTRAINT fee_concessions_value_shape_check CHECK ('.
            "(kind = 'fixed' AND fixed_amount IS NOT NULL AND fixed_amount > 0 AND percentage IS NULL) OR ".
            "(kind = 'percentage' AND percentage IS NOT NULL AND percentage > 0 AND percentage <= 100 AND fixed_amount IS NULL))"
        );
        DB::statement(
            'ALTER TABLE fee_concessions ADD CONSTRAINT fee_concessions_scope_shape_check CHECK ('.
            "(scope = 'targeted' AND charge_id IS NOT NULL AND fee_head_id IS NULL AND valid_from IS NULL AND valid_to IS NULL AND kind = 'fixed') OR ".
            "(scope = 'standing' AND charge_id IS NULL AND valid_from IS NOT NULL AND valid_to IS NOT NULL AND valid_from <= valid_to))"
        );
        DB::statement(
            'ALTER TABLE fee_concessions ADD CONSTRAINT fee_concessions_status_shape_check CHECK ('.
            "(status = 'pending' AND decided_by_user_id IS NULL AND decided_at IS NULL AND withdrawn_at IS NULL AND revoked_at IS NULL) OR ".
            "(status IN ('approved', 'rejected') AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND withdrawn_at IS NULL AND revoked_at IS NULL) OR ".
            "(status = 'withdrawn' AND decided_by_user_id IS NULL AND withdrawn_at IS NOT NULL AND revoked_at IS NULL) OR ".
            "(status = 'revoked' AND scope = 'standing' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND revoked_by_user_id IS NOT NULL AND revoked_at IS NOT NULL))"
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_fee_concession() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            DECLARE
                year_starts date;
                year_ends date;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'pending' THEN
                        RAISE EXCEPTION 'a fee concession is always requested as pending'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.scope = 'targeted' AND NOT EXISTS (
                        SELECT 1 FROM charges
                        WHERE id = NEW.charge_id AND school_id = NEW.school_id AND student_id = NEW.student_id
                          AND academic_year_id = NEW.academic_year_id AND cancelled_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'a targeted concession needs an uncancelled charge of the same Student and academic year'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.scope = 'standing' THEN
                        SELECT starts_on, ends_on INTO year_starts, year_ends FROM academic_years WHERE id = NEW.academic_year_id;
                        IF NEW.valid_from < year_starts OR NEW.valid_to > year_ends THEN
                            RAISE EXCEPTION 'a standing concession window must lie inside its academic year (% to %)', year_starts, year_ends
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.student_id IS DISTINCT FROM OLD.student_id
                    OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id
                    OR NEW.category IS DISTINCT FROM OLD.category
                    OR NEW.scope IS DISTINCT FROM OLD.scope
                    OR NEW.charge_id IS DISTINCT FROM OLD.charge_id
                    OR NEW.fee_head_id IS DISTINCT FROM OLD.fee_head_id
                    OR NEW.valid_from IS DISTINCT FROM OLD.valid_from
                    OR NEW.valid_to IS DISTINCT FROM OLD.valid_to
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.fixed_amount IS DISTINCT FROM OLD.fixed_amount
                    OR NEW.percentage IS DISTINCT FROM OLD.percentage
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.requested_by_user_id IS DISTINCT FROM OLD.requested_by_user_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'fee concession % request is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT (
                    OLD.status = NEW.status
                    OR (OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected', 'withdrawn'))
                    OR (OLD.status = 'approved' AND NEW.status = 'revoked' AND OLD.scope = 'standing')
                ) THEN
                    RAISE EXCEPTION 'illegal fee concession transition % -> %', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status <> 'pending' AND (
                    NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id
                    OR NEW.decided_at IS DISTINCT FROM OLD.decided_at
                    OR NEW.withdrawn_at IS DISTINCT FROM OLD.withdrawn_at
                ) THEN
                    RAISE EXCEPTION 'fee concession % decision is final', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF OLD.status IN ('rejected', 'withdrawn', 'revoked') AND (
                    NEW.revoked_at IS DISTINCT FROM OLD.revoked_at
                    OR NEW.revoked_by_user_id IS DISTINCT FROM OLD.revoked_by_user_id
                ) THEN
                    RAISE EXCEPTION 'fee concession % is % and final', OLD.id, OLD.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_concessions_guard_trigger '.
            'BEFORE INSERT OR UPDATE ON fee_concessions '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_fee_concession()'
        );

        TenantRls::enable('fee_concessions');
        TenantRls::revokeDelete('fee_concessions');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_concessions_guard_trigger ON fee_concessions');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_fee_concession()');
        TenantRls::disable('fee_concessions');
        Schema::dropIfExists('fee_concessions');
    }
};
