<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0032 "Run kinds, correction model, and posting" /
     * "Partial-period policy") -- one authoritative result row per
     * `EmploymentRecord` per run (never per bare `Employee` -- an
     * Employee may have multiple historical EmploymentRecords).
     * `employee_id` is denormalized for convenient querying/reporting
     * only; `employment_record_id` is what uniqueness and every
     * invariant actually key on.
     *
     * `net_amount = gross_amount - total_deductions` is a universal,
     * row-local arithmetic identity (plain CHECK, no cross-row
     * lookup). The SIGN of these three fields, however, depends on the
     * parent run's `run_kind` -- a regular run's result must be
     * non-negative on all three; a correction run's result is a
     * signed aggregate DELTA (may be negative, e.g. a decreased
     * earning or a reduced deduction) -- this needs the trigger below
     * since it requires looking at the parent `payroll_runs` row.
     *
     * Frozen the instant the parent run reaches `approved` or later --
     * this IS the immutability boundary from ADR 0032 ("approved" is
     * the sole boundary; there is no separate `finalized` state).
     * Mirrors `journal_lines_reject_post_commit_insert()`'s structural
     * shape (Finance, ADR 0030), keyed off run status rather than
     * commit timing.
     */
    public function up(): void
    {
        Schema::create('payroll_run_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_run_id');
            $table->uuid('employment_record_id');
            $table->uuid('employee_id');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('total_deductions', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employment_record_id']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');

            $table->foreign(['payroll_run_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_runs')
                ->cascadeOnDelete();
            $table->foreign(['employment_record_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employment_records')
                ->restrictOnDelete();
            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payroll_run_results ADD CONSTRAINT payroll_run_results_net_identity_check CHECK (net_amount = gross_amount - total_deductions)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_run_results_validate_sign() RETURNS trigger AS $$
            DECLARE
                parent_kind text;
            BEGIN
                SELECT run_kind INTO parent_kind FROM payroll_runs WHERE id = NEW.payroll_run_id;

                IF parent_kind = 'regular' AND (NEW.gross_amount < 0 OR NEW.total_deductions < 0 OR NEW.net_amount < 0) THEN
                    RAISE EXCEPTION 'payroll_run_results: a regular run''s result (%) must be non-negative on gross/deductions/net.', NEW.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_run_results_validate_sign
                BEFORE INSERT OR UPDATE ON payroll_run_results
                FOR EACH ROW
                EXECUTE FUNCTION payroll_run_results_validate_sign();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_run_results_freeze_after_approval() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                target_run_id uuid;
            BEGIN
                target_run_id := COALESCE(NEW.payroll_run_id, OLD.payroll_run_id);

                SELECT status INTO parent_status FROM payroll_runs WHERE id = target_run_id;

                IF parent_status IN ('approved', 'posted') THEN
                    RAISE EXCEPTION 'payroll_run_results: parent run (%) is % -- results are frozen.', target_run_id, parent_status;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_run_results_freeze
                BEFORE INSERT OR UPDATE OR DELETE ON payroll_run_results
                FOR EACH ROW
                EXECUTE FUNCTION payroll_run_results_freeze_after_approval();
        SQL);

        TenantRls::enable('payroll_run_results');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_run_results');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_run_results_freeze ON payroll_run_results');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_run_results_freeze_after_approval()');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_run_results_validate_sign ON payroll_run_results');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_run_results_validate_sign()');
        Schema::dropIfExists('payroll_run_results');
    }
};
