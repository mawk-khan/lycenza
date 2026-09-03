<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoint 9.6F (ADR 0036 correction addendum §1.11) -- links a
     * `payroll_runs` row to the SEPARATE statutory `journal_entries`
     * row `StatutoryPayrollPostingService::post()`/`reverse()` posts
     * for it, alongside (never merged into) the main
     * `payroll_run_postings` entry `PayrollPostingService` already
     * owns. Mirrors `payroll_run_postings`' exact shape (partial
     * unique indexes for "one original per run" / "one reversal per
     * posting", the same reversal-target-validation trigger, append-
     * only) -- deliberately a SEPARATE table rather than adding
     * columns to `payroll_run_postings`, since the two postings are
     * independent Finance entries with independent lifecycles (a
     * School can post payroll before statutory calculation/posting is
     * wired up, and reversing one must never implicitly reverse the
     * other).
     */
    public function up(): void
    {
        Schema::create('payroll_statutory_run_postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_run_id');
            $table->uuid('journal_entry_id');
            $table->char('currency', 3)->default('INR');
            $table->string('posting_kind'); // original|reversal
            $table->uuid('reversal_of_posting_id')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['school_id', 'journal_entry_id']);
            $table->index('school_id');
            $table->index(['school_id', 'payroll_run_id']);

            $table->foreign(['payroll_run_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_runs')
                ->restrictOnDelete();
            $table->foreign(['journal_entry_id', 'school_id', 'currency'])
                ->references(['id', 'school_id', 'currency'])->on('journal_entries')
                ->restrictOnDelete();
            $table->foreign(['reversal_of_posting_id', 'school_id'], 'psrp_reversal_of_posting_fk')
                ->references(['id', 'school_id'])->on('payroll_statutory_run_postings')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payroll_statutory_run_postings ADD CONSTRAINT payroll_statutory_run_postings_kind_check CHECK (posting_kind IN ('original', 'reversal'))");
        DB::statement('ALTER TABLE payroll_statutory_run_postings ADD CONSTRAINT payroll_statutory_run_postings_reversal_shape_check CHECK ((posting_kind = \'reversal\') = (reversal_of_posting_id IS NOT NULL))');

        DB::statement('CREATE UNIQUE INDEX payroll_statutory_run_postings_one_original_per_run ON payroll_statutory_run_postings (school_id, payroll_run_id) WHERE posting_kind = \'original\'');
        DB::statement('CREATE UNIQUE INDEX payroll_statutory_run_postings_one_reversal_per_posting ON payroll_statutory_run_postings (school_id, reversal_of_posting_id) WHERE posting_kind = \'reversal\'');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_statutory_run_postings_validate_reversal_target() RETURNS trigger AS $$
            DECLARE
                target_kind text;
                target_run_id uuid;
            BEGIN
                IF NEW.reversal_of_posting_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT posting_kind, payroll_run_id INTO target_kind, target_run_id
                FROM payroll_statutory_run_postings WHERE id = NEW.reversal_of_posting_id;

                IF target_kind IS DISTINCT FROM 'original' THEN
                    RAISE EXCEPTION 'payroll_statutory_run_postings: reversal_of_posting_id (%) must reference an original posting, got %.', NEW.reversal_of_posting_id, target_kind;
                END IF;

                IF target_run_id IS DISTINCT FROM NEW.payroll_run_id THEN
                    RAISE EXCEPTION 'payroll_statutory_run_postings: a reversal must reference the SAME run''s original posting (got run % for target run %).', NEW.payroll_run_id, target_run_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_statutory_run_postings_validate_reversal_target
                BEFORE INSERT ON payroll_statutory_run_postings
                FOR EACH ROW
                EXECUTE FUNCTION payroll_statutory_run_postings_validate_reversal_target();
        SQL);

        TenantRls::enable('payroll_statutory_run_postings');
        TenantRls::makeAppendOnly('payroll_statutory_run_postings');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_statutory_run_postings');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_statutory_run_postings_validate_reversal_target ON payroll_statutory_run_postings');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_statutory_run_postings_validate_reversal_target()');
        Schema::dropIfExists('payroll_statutory_run_postings');
    }
};
