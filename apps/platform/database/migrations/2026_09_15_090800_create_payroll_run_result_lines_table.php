<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Deduction accounting" / "Run kinds,
     * correction model, and posting") -- earning/deduction/statutory
     * line items backing one `payroll_run_results` row.
     *
     * `amount` is always non-negative -- sign/direction is carried by
     * `salary_components.type` (earning|deduction) combined with
     * `effect` (increase|decrease) here, NEVER by a negative literal
     * (mirrors `journal_lines`' own debit_amount/credit_amount
     * reasoning, ADR 0030: "eliminating a sign-based side encoding").
     * `effect` is `increase` for every regular-run line (the only
     * meaningful value there) and `increase`|`decrease` for a
     * correction run's delta lines, mirroring `payroll_adjustments`'
     * own `increase`|`decrease` vocabulary for its correction_delta
     * mode -- the posting algorithm (Checkpoint 9.5) combines `type`
     * and `effect` to choose the correct debit/credit direction:
     * earning+increase -> debit expense, earning+decrease -> credit
     * expense, deduction+increase -> credit liability,
     * deduction+decrease -> debit liability.
     *
     * `resolved_ledger_account_id` snapshots the deduction's Finance
     * destination (from `salary_components.liability_ledger_account_id`
     * at the time of calculation, per ADR 0032) so a later
     * reconfiguration of that mapping can never retroactively alter
     * what an already-approved run will post. Null for earning lines
     * (which post in aggregate to the single configured
     * salary-expense account, never per-component).
     *
     * Frozen the instant the parent run (via its `payroll_run_results`
     * parent) reaches `approved` or later -- same boundary as
     * `payroll_run_results` itself.
     */
    public function up(): void
    {
        Schema::create('payroll_run_result_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_run_result_id');
            $table->uuid('salary_component_id');
            $table->decimal('amount', 14, 2);
            $table->string('effect')->default('increase'); // increase|decrease
            $table->uuid('resolved_ledger_account_id')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->unique(['payroll_run_result_id', 'salary_component_id']);
            $table->index('school_id');

            $table->foreign(['payroll_run_result_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_run_results')
                ->cascadeOnDelete();
            $table->foreign(['salary_component_id', 'school_id'])
                ->references(['id', 'school_id'])->on('salary_components')
                ->restrictOnDelete();
            $table->foreign(['resolved_ledger_account_id', 'school_id', 'currency'])
                ->references(['id', 'school_id', 'currency'])->on('ledger_accounts')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payroll_run_result_lines ADD CONSTRAINT payroll_run_result_lines_amount_check CHECK (amount >= 0)');
        DB::statement("ALTER TABLE payroll_run_result_lines ADD CONSTRAINT payroll_run_result_lines_effect_check CHECK (effect IN ('increase', 'decrease'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_run_result_lines_freeze_after_approval() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                target_result_id uuid;
            BEGIN
                target_result_id := COALESCE(NEW.payroll_run_result_id, OLD.payroll_run_result_id);

                SELECT pr.status INTO parent_status
                FROM payroll_run_results rr
                JOIN payroll_runs pr ON pr.id = rr.payroll_run_id
                WHERE rr.id = target_result_id;

                IF parent_status IN ('approved', 'posted') THEN
                    RAISE EXCEPTION 'payroll_run_result_lines: parent run is % -- lines are frozen (result %).', parent_status, target_result_id;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_run_result_lines_freeze
                BEFORE INSERT OR UPDATE OR DELETE ON payroll_run_result_lines
                FOR EACH ROW
                EXECUTE FUNCTION payroll_run_result_lines_freeze_after_approval();
        SQL);

        TenantRls::enable('payroll_run_result_lines');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_run_result_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_run_result_lines_freeze ON payroll_run_result_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_run_result_lines_freeze_after_approval()');
        Schema::dropIfExists('payroll_run_result_lines');
    }
};
