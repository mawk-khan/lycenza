<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRX.5 (ADR 0065 §26.8): Payroll's snapshot of the HRX absence EVIDENCE it
 * consumed for one regular-run result -- contract version, fingerprint,
 * completeness, period and coverage, exact integer half-day unit counts and
 * the per-half evidence. Evidence only: no amount, no "non-payable"
 * quantity, no NCP (HRX-L4 open).
 *
 * - One row per payroll run result, through a composite foreign key ON
 *   DELETE CASCADE: it is replaced with its result while the run is
 *   `draft`/`calculated` (calculate() replaces the whole result set), and
 *   it leaves with its result under E21.3F payroll retention.
 * - Frozen like results: no runtime UPDATE or DELETE (append-only by
 *   privilege); a trigger refuses insert, update and (cascade) delete once
 *   the run is `approved`/`posted`, except inside the E21.3F retention
 *   function (`payroll_retention_delete_allowed()`).
 * - No foreign key to an Employee, Leave or Staff Attendance row: it never
 *   cascades from HRX.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The result's identity, so the snapshot's run and employment are the result's own.
        DB::statement('CREATE UNIQUE INDEX payroll_run_results_identity_key ON payroll_run_results (id, school_id, payroll_run_id, employment_record_id)');

        Schema::create('payroll_run_hrx_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_run_result_id');
            $table->uuid('payroll_run_id');
            $table->uuid('employment_record_id');
            $table->string('contract_version', 40);
            $table->char('fingerprint', 64);
            $table->string('completeness', 20);
            $table->jsonb('incomplete_reasons');
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->date('covered_from')->nullable();
            $table->date('covered_to')->nullable();
            $table->integer('required_working_half_units')->nullable();
            $table->integer('approved_paid_leave_half_units');
            $table->integer('approved_unpaid_leave_half_units');
            $table->integer('recorded_absence_half_units');
            $table->integer('recorded_presence_half_units');
            $table->integer('unresolved_working_half_units');
            $table->jsonb('evidence');
            $table->foreignUuid('captured_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('captured_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique('payroll_run_result_id', 'payroll_run_hrx_inputs_one_per_result');
            $table->index(['school_id', 'payroll_run_id'], 'payroll_run_hrx_inputs_run_idx');
            $table->foreign(['payroll_run_result_id', 'school_id', 'payroll_run_id', 'employment_record_id'], 'payroll_run_hrx_inputs_result_fk')
                ->references(['id', 'school_id', 'payroll_run_id', 'employment_record_id'])->on('payroll_run_results')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE payroll_run_hrx_inputs ADD CONSTRAINT payroll_run_hrx_inputs_shape_check CHECK ('
            ."contract_version = 'hrx_payroll_input.v1' AND fingerprint ~ '^[0-9a-f]{64}$' "
            ."AND completeness IN ('complete', 'input_incomplete') "
            ."AND jsonb_typeof(incomplete_reasons) = 'array' AND jsonb_typeof(evidence) = 'array' "
            ."AND ((completeness = 'complete') = (incomplete_reasons = '[]'::jsonb)) "
            .'AND period_starts_on <= period_ends_on '
            .'AND ((covered_from IS NULL) = (covered_to IS NULL)) '
            .'AND (covered_from IS NULL OR (covered_from <= covered_to AND covered_from >= period_starts_on AND covered_to <= period_ends_on)) '
            .'AND (required_working_half_units IS NULL OR required_working_half_units >= 0) '
            .'AND approved_paid_leave_half_units >= 0 AND approved_unpaid_leave_half_units >= 0 '
            .'AND recorded_absence_half_units >= 0 AND recorded_presence_half_units >= 0 AND unresolved_working_half_units >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION payroll_run_hrx_inputs_freeze() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_run_id uuid := COALESCE(NEW.payroll_run_id, OLD.payroll_run_id);
                v_status text;
                v_kind text;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'payroll_run_hrx_inputs: a captured HRX input never changes (payroll_hrx_input_immutable)';
                END IF;
                SELECT status, run_kind INTO v_status, v_kind FROM public.payroll_runs WHERE id = v_run_id;
                IF TG_OP = 'INSERT' AND v_kind IS DISTINCT FROM 'regular' THEN
                    RAISE EXCEPTION 'payroll_run_hrx_inputs: HRX input is captured for regular runs only (payroll_hrx_input_invalid)';
                END IF;
                IF v_status IN ('approved', 'posted') AND NOT public.payroll_retention_delete_allowed() THEN
                    RAISE EXCEPTION 'payroll_run_hrx_inputs: run % is % -- its HRX input is frozen (payroll_hrx_input_frozen)', v_run_id, v_status;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$;
            CREATE TRIGGER trg_payroll_run_hrx_inputs_freeze BEFORE INSERT OR UPDATE OR DELETE ON payroll_run_hrx_inputs
                FOR EACH ROW EXECUTE FUNCTION payroll_run_hrx_inputs_freeze();
            SQL);

        TenantRls::enable('payroll_run_hrx_inputs');
        TenantRls::makeAppendOnly('payroll_run_hrx_inputs');
    }

    public function down(): void
    {
        if (DB::table('payroll_run_hrx_inputs')->exists()) {
            throw new RuntimeException('Refusing to roll back: payroll_run_hrx_inputs holds Payroll evidence (HRX.5). Remove it through its retention path first.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_payroll_run_hrx_inputs_freeze ON payroll_run_hrx_inputs;
            DROP FUNCTION IF EXISTS payroll_run_hrx_inputs_freeze();
            SQL);
        TenantRls::disable('payroll_run_hrx_inputs');
        Schema::dropIfExists('payroll_run_hrx_inputs');
        DB::statement('DROP INDEX IF EXISTS payroll_run_results_identity_key');
    }
};
