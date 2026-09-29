<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FEE.2 (ADR 0062 §9): one assessment run = one ACTIVE fee structure x
     * one of its `billing_period_key`s.
     *
     * - Lifecycle (§9.2): `draft -> previewed -> executing ->
     *   completed | completed_with_errors`, `previewed -> draft` (drift:
     *   a staff exclusion), `previewed -> previewed` (a fresh preview),
     *   `draft|previewed -> cancelled`. Completed, completed_with_errors and
     *   cancelled are terminal and immutable
     *   (`fees_validate_fee_assessment_run`). A suspended School pauses an
     *   executing run without a status change (§13).
     * - One OPEN run per (School, structure, period):
     *   `fee_assessment_runs_one_open_per_period`, a partial unique index
     *   -- never an application pre-check.
     * - A run can only be created for an ACTIVE structure and a billing
     *   period that exists on it (checked by the same trigger at insert).
     * - `configuration_version` / `previewed_configuration_version` implement
     *   the drift rule: execution claims the run only when they match.
     * - Totals are NUMERIC and derived from item facts; never floats.
     * - Runs are never deleted (`TenantRls::revokeDelete`).
     */
    public function up(): void
    {
        Schema::create('fee_assessment_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('fee_structure_id');
            $table->string('billing_period_key', 32);
            $table->string('status')->default('draft');
            $table->unsignedInteger('configuration_version')->default(1);
            $table->unsignedInteger('previewed_configuration_version')->nullable();

            $table->unsignedInteger('ready_count')->default(0);
            $table->decimal('ready_amount', 16, 2)->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('already_assessed_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('assessed_amount', 16, 2)->default(0);
            $table->char('currency', 3)->default('INR');

            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('previewed_at')->nullable();
            $table->foreignUuid('previewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('execution_started_at')->nullable();
            $table->foreignUuid('executed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'fee_structure_id']);

            $table->foreign(['fee_structure_id', 'school_id'], 'fee_assessment_runs_structure_fk')
                ->references(['id', 'school_id'])->on('fee_structures')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fee_assessment_runs ADD CONSTRAINT fee_assessment_runs_status_check CHECK (status IN ('draft', 'previewed', 'executing', 'completed', 'completed_with_errors', 'cancelled'))");
        DB::statement("ALTER TABLE fee_assessment_runs ADD CONSTRAINT fee_assessment_runs_period_key_format_check CHECK (billing_period_key ~ '^[A-Z0-9][A-Z0-9_-]{0,31}$')");
        DB::statement("ALTER TABLE fee_assessment_runs ADD CONSTRAINT fee_assessment_runs_currency_inr_only_check CHECK (currency = 'INR')");
        DB::statement('ALTER TABLE fee_assessment_runs ADD CONSTRAINT fee_assessment_runs_amounts_check CHECK (ready_amount >= 0 AND assessed_amount >= 0)');
        DB::statement(
            'ALTER TABLE fee_assessment_runs ADD CONSTRAINT fee_assessment_runs_shape_check CHECK ('.
            "(status <> 'previewed' OR (previewed_at IS NOT NULL AND COALESCE(previewed_configuration_version, 0) = configuration_version)) AND ".
            "(status NOT IN ('executing', 'completed', 'completed_with_errors') OR (execution_started_at IS NOT NULL AND COALESCE(previewed_configuration_version, 0) = configuration_version)) AND ".
            "(status NOT IN ('completed', 'completed_with_errors') OR completed_at IS NOT NULL) AND ".
            "((status = 'cancelled') = (cancelled_at IS NOT NULL)))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX fee_assessment_runs_one_open_per_period ON fee_assessment_runs '.
            "(school_id, fee_structure_id, billing_period_key) WHERE status IN ('draft', 'previewed', 'executing')"
        );

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fees_validate_fee_assessment_run() RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'an assessment run is always created as a draft'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT EXISTS (SELECT 1 FROM fee_structures WHERE id = NEW.fee_structure_id AND school_id = NEW.school_id AND status = 'active') THEN
                        RAISE EXCEPTION 'an assessment run needs an active fee structure'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM fee_structure_installments i
                        JOIN fee_structure_lines l ON l.id = i.fee_structure_line_id
                        WHERE l.fee_structure_id = NEW.fee_structure_id AND i.billing_period_key = NEW.billing_period_key
                    ) THEN
                        RAISE EXCEPTION 'billing period % does not exist on fee structure %', NEW.billing_period_key, NEW.fee_structure_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status IN ('completed', 'completed_with_errors', 'cancelled') THEN
                    RAISE EXCEPTION 'assessment run % is % and immutable', OLD.id, OLD.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT (
                    (OLD.status = NEW.status AND OLD.status IN ('draft', 'previewed', 'executing'))
                    OR (OLD.status = 'draft' AND NEW.status IN ('previewed', 'cancelled'))
                    OR (OLD.status = 'previewed' AND NEW.status IN ('draft', 'executing', 'cancelled'))
                    OR (OLD.status = 'executing' AND NEW.status IN ('completed', 'completed_with_errors'))
                ) THEN
                    RAISE EXCEPTION 'illegal assessment run transition % -> %', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.fee_structure_id IS DISTINCT FROM OLD.fee_structure_id
                    OR NEW.billing_period_key IS DISTINCT FROM OLD.billing_period_key
                    OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    OR NEW.configuration_version < OLD.configuration_version
                THEN
                    RAISE EXCEPTION 'assessment run % scope is immutable', OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement(
            'CREATE TRIGGER fee_assessment_runs_lifecycle_trigger '.
            'BEFORE INSERT OR UPDATE ON fee_assessment_runs '.
            'FOR EACH ROW EXECUTE FUNCTION fees_validate_fee_assessment_run()'
        );

        TenantRls::enable('fee_assessment_runs');
        TenantRls::revokeDelete('fee_assessment_runs');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fee_assessment_runs_lifecycle_trigger ON fee_assessment_runs');
        DB::statement('DROP FUNCTION IF EXISTS fees_validate_fee_assessment_run()');
        TenantRls::disable('fee_assessment_runs');
        Schema::dropIfExists('fee_assessment_runs');
    }
};
