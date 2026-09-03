<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9.1 (ADR 0034 "Run kinds, correction model, and posting" /
     * "Reversal model") -- append-only link between a `payroll_runs`
     * row and the Finance `journal_entries` row
     * `LedgerService::post()`/`reverse()` produced for it.
     *
     * `posting_kind` is `original`|`reversal` -- NOT one-to-one with a
     * run in the naive sense (a run can have an original posting AND,
     * later, a reversal posting): the actual one-to-one invariant is
     * "at most one ORIGINAL posting per run" (`payroll_run_postings_one_original_per_run`,
     * a partial unique index) and "at most one REVERSAL per posting"
     * (`payroll_run_postings_one_reversal_per_posting`), both race-safe
     * via the same partial-unique-index +
     * `UniqueConstraintViolationException` pattern
     * `journal_entries_reversal_of_unique` already established.
     * `UNIQUE(school_id, journal_entry_id)` additionally guarantees one
     * Payroll posting row per Finance journal entry.
     *
     * A run's own `status` never becomes `reversed` -- reversal is a
     * derived read, computed by checking whether a `posting_kind='reversal'`
     * row exists for a run's original posting, never a mutation of
     * `payroll_runs` itself (append-only financial history).
     *
     * Reversal never mutates the original `JournalEntry`
     * (`LedgerService::reverse()` already guarantees this -- see
     * ADR 0030) nor the original `payroll_run_postings` row -- a
     * reversal is always a NEW row here, linked via
     * `reversal_of_payroll_run_posting_id`.
     */
    public function up(): void
    {
        Schema::create('payroll_run_postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_run_id');
            $table->uuid('journal_entry_id');
            $table->char('currency', 3)->default('INR');
            $table->string('posting_kind'); // original|reversal
            $table->uuid('reversal_of_payroll_run_posting_id')->nullable();
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
            $table->foreign(['reversal_of_payroll_run_posting_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_run_postings')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payroll_run_postings ADD CONSTRAINT payroll_run_postings_kind_check CHECK (posting_kind IN ('original', 'reversal'))");
        DB::statement('ALTER TABLE payroll_run_postings ADD CONSTRAINT payroll_run_postings_reversal_shape_check CHECK ((posting_kind = \'reversal\') = (reversal_of_payroll_run_posting_id IS NOT NULL))');

        DB::statement('CREATE UNIQUE INDEX payroll_run_postings_one_original_per_run ON payroll_run_postings (school_id, payroll_run_id) WHERE posting_kind = \'original\'');
        DB::statement('CREATE UNIQUE INDEX payroll_run_postings_one_reversal_per_posting ON payroll_run_postings (school_id, reversal_of_payroll_run_posting_id) WHERE posting_kind = \'reversal\'');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_run_postings_validate_reversal_target() RETURNS trigger AS $$
            DECLARE
                target_kind text;
                target_run_id uuid;
            BEGIN
                IF NEW.reversal_of_payroll_run_posting_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT posting_kind, payroll_run_id INTO target_kind, target_run_id
                FROM payroll_run_postings WHERE id = NEW.reversal_of_payroll_run_posting_id;

                IF target_kind IS DISTINCT FROM 'original' THEN
                    RAISE EXCEPTION 'payroll_run_postings: reversal_of_payroll_run_posting_id (%) must reference an original posting, got %.', NEW.reversal_of_payroll_run_posting_id, target_kind;
                END IF;

                IF target_run_id IS DISTINCT FROM NEW.payroll_run_id THEN
                    RAISE EXCEPTION 'payroll_run_postings: a reversal must reference the SAME run''s original posting (got run % for target run %).', NEW.payroll_run_id, target_run_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_run_postings_validate_reversal_target
                BEFORE INSERT ON payroll_run_postings
                FOR EACH ROW
                EXECUTE FUNCTION payroll_run_postings_validate_reversal_target();
        SQL);

        TenantRls::enable('payroll_run_postings');
        TenantRls::makeAppendOnly('payroll_run_postings');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_run_postings');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_run_postings_validate_reversal_target ON payroll_run_postings');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_run_postings_validate_reversal_target()');
        Schema::dropIfExists('payroll_run_postings');
    }
};
