<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Partial-period policy" / "Run kinds,
     * correction model, and posting") -- explicit, human-entered
     * amounts, in exactly two distinguished modes that are never
     * conflated:
     *
     * - `manual_override`: the AUTHORITATIVE, absolute component
     *   amount for a partial-period EmploymentRecord in a REGULAR run
     *   (a run cannot move to `calculated` while any affected
     *   EmploymentRecord lacks one -- Checkpoint 9.3). No automatic
     *   proration is ever computed; this is the human correction that
     *   satisfies the fail-closed partial-period gate.
     * - `correction_delta`: a signed DELTA effect (`amount` always
     *   positive, `effect` carries the direction) belonging to a
     *   CORRECTION run -- this is what a correction run's results are
     *   built from, in place of ever re-running the automatic
     *   calculation kernel (no retro-pay engine).
     *
     * `mode` must match the parent run's `run_kind` (regular <->
     * manual_override, correction <-> correction_delta) -- a
     * cross-table check, enforced by the trigger below since it is
     * not expressible as a plain CHECK.
     *
     * `reason` and `actor_user_id` are mandatory for both modes --
     * every adjustment is a deliberate, attributable human decision,
     * never a silent system default.
     */
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_run_id');
            $table->uuid('employment_record_id');
            $table->uuid('salary_component_id');
            $table->string('mode'); // manual_override|correction_delta
            $table->decimal('amount', 14, 2);
            $table->string('effect')->nullable(); // increase|decrease -- correction_delta only
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('school_id');
            $table->index(['payroll_run_id', 'employment_record_id']);

            $table->foreign(['payroll_run_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_runs')
                ->cascadeOnDelete();
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->restrictOnDelete();
            $table->foreign(['salary_component_id', 'school_id'])
                ->references(['id', 'school_id'])->on('salary_components')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_mode_check CHECK (mode IN ('manual_override', 'correction_delta'))");
        DB::statement("ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_effect_check CHECK (effect IS NULL OR effect IN ('increase', 'decrease'))");
        DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_mode_effect_shape_check CHECK ((mode = \'correction_delta\') = (effect IS NOT NULL))');
        DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_reason_check CHECK (length(trim(reason)) > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_adjustments_validate_mode_matches_run_kind() RETURNS trigger AS $$
            DECLARE
                parent_kind text;
            BEGIN
                SELECT run_kind INTO parent_kind FROM payroll_runs WHERE id = NEW.payroll_run_id;

                IF NEW.mode = 'manual_override' AND parent_kind IS DISTINCT FROM 'regular' THEN
                    RAISE EXCEPTION 'payroll_adjustments: manual_override requires a regular run, got %.', parent_kind;
                END IF;

                IF NEW.mode = 'correction_delta' AND parent_kind IS DISTINCT FROM 'correction' THEN
                    RAISE EXCEPTION 'payroll_adjustments: correction_delta requires a correction run, got %.', parent_kind;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_adjustments_validate_mode
                BEFORE INSERT OR UPDATE ON payroll_adjustments
                FOR EACH ROW
                EXECUTE FUNCTION payroll_adjustments_validate_mode_matches_run_kind();
        SQL);

        TenantRls::enable('payroll_adjustments');
        TenantRls::makeAppendOnly('payroll_adjustments');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_adjustments');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_adjustments_validate_mode ON payroll_adjustments');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_adjustments_validate_mode_matches_run_kind()');
        Schema::dropIfExists('payroll_adjustments');
    }
};
