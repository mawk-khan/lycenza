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
     * "Separation of duties") -- one payroll calculation execution.
     *
     * `run_kind` is `regular`|`correction`.
     * `UNIQUE(school_id, payroll_period_id) WHERE run_kind='regular'`
     * guarantees exactly one authoritative regular run per period,
     * race-safe via the same partial-unique-index + `UniqueConstraintViolationException`
     * pattern `journal_entries_reversal_of_unique` already uses. A
     * correction run is a SEPARATE row (`corrects_payroll_run_id`,
     * required iff `run_kind='correction'`) referencing the original
     * REGULAR, already-POSTED run only -- never chained
     * correction-of-correction, never a second regular run for the
     * same period. Both checks are enforced by the trigger below
     * (a cross-row lookup, not expressible as a plain CHECK).
     *
     * State machine: `draft -> calculated -> approved -> posted`, with
     * `calculated -> calculated` (recalculation) also valid.
     * `approved` is the SOLE immutability boundary -- there is no
     * separate `finalized` state (result freezing itself lives on
     * `payroll_run_results`/`_lines`, not here). The trigger below is
     * a defense-in-depth guard against an invalid transition (skipping
     * a state, moving backward) even via raw SQL -- the PRIMARY
     * concurrency guarantee for each one-time transition is the
     * Application layer's conditional-UPDATE claim (`WHERE
     * status = 'calculated'` for approve, `WHERE status = 'approved'`
     * for post), added in Checkpoint 9.4/9.5, mirroring
     * `App\Domain\Communications\Application\Approval\CommunicationApprovalService::decide()`'s
     * exact `WHERE status = 'pending'` claim shape.
     *
     * Separation of duties: `prepared_by_user_id`/`approved_by_user_id`/
     * `posted_by_user_id` are plain FKs to `users(id)` (not composite --
     * `User` is not itself School-scoped, matching
     * `documents.uploaded_by_user_id`'s exact pattern). The preparer of
     * a run may never approve it -- enforced here as a database CHECK
     * (cheap for this single-row, two-column comparison, unlike a
     * cross-row invariant) AND, in Checkpoint 9.4, at the Application
     * layer (mirroring `SelfApprovalNotAllowedException`'s existing
     * precedent). An approver may also post -- no repository
     * precedent requires further restriction.
     *
     * There is no `reversed` status value: a posted run stays `posted`
     * forever (append-only financial history) -- reversal is a
     * derived read computed from `payroll_run_postings`
     * (Checkpoint 9.5), never a mutation of this row.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('payroll_period_id');
            $table->string('run_kind'); // regular|correction
            $table->uuid('corrects_payroll_run_id')->nullable();
            $table->string('status')->default('draft'); // draft|calculated|approved|posted
            $table->foreignUuid('prepared_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('posted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index(['school_id', 'corrects_payroll_run_id']);

            $table->foreign(['payroll_period_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_periods')
                ->restrictOnDelete();
            $table->foreign(['corrects_payroll_run_id', 'school_id'])
                ->references(['id', 'school_id'])->on('payroll_runs')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_run_kind_check CHECK (run_kind IN ('regular', 'correction'))");
        DB::statement("ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_status_check CHECK (status IN ('draft', 'calculated', 'approved', 'posted'))");
        DB::statement('ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_correction_reference_check CHECK ((run_kind = \'correction\') = (corrects_payroll_run_id IS NOT NULL))');
        DB::statement('ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_sod_check CHECK (approved_by_user_id IS NULL OR approved_by_user_id <> prepared_by_user_id)');

        DB::statement('CREATE UNIQUE INDEX payroll_runs_one_regular_per_period ON payroll_runs (school_id, payroll_period_id) WHERE run_kind = \'regular\'');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_runs_validate_correction_target() RETURNS trigger AS $$
            DECLARE
                target_kind text;
                target_status text;
            BEGIN
                IF NEW.corrects_payroll_run_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT run_kind, status INTO target_kind, target_status
                FROM payroll_runs WHERE id = NEW.corrects_payroll_run_id;

                IF target_kind IS DISTINCT FROM 'regular' THEN
                    RAISE EXCEPTION 'payroll_runs: corrects_payroll_run_id (%) must reference a regular run, got %.', NEW.corrects_payroll_run_id, target_kind;
                END IF;

                IF target_status IS DISTINCT FROM 'posted' THEN
                    RAISE EXCEPTION 'payroll_runs: corrects_payroll_run_id (%) must reference a posted run, got %.', NEW.corrects_payroll_run_id, target_status;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_runs_validate_correction_target
                BEFORE INSERT ON payroll_runs
                FOR EACH ROW
                EXECUTE FUNCTION payroll_runs_validate_correction_target();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_runs_validate_transition() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = OLD.status THEN
                    RETURN NEW;
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'calculated' THEN
                    RETURN NEW;
                ELSIF OLD.status = 'calculated' AND NEW.status = 'approved' THEN
                    RETURN NEW;
                ELSIF OLD.status = 'approved' AND NEW.status = 'posted' THEN
                    RETURN NEW;
                ELSE
                    RAISE EXCEPTION 'payroll_runs: invalid status transition % -> % for run %.', OLD.status, NEW.status, OLD.id;
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_runs_validate_transition
                BEFORE UPDATE ON payroll_runs
                FOR EACH ROW
                EXECUTE FUNCTION payroll_runs_validate_transition();
        SQL);

        TenantRls::enable('payroll_runs');
    }

    public function down(): void
    {
        TenantRls::disable('payroll_runs');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_runs_validate_transition ON payroll_runs');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_runs_validate_transition()');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_runs_validate_correction_target ON payroll_runs');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_runs_validate_correction_target()');
        Schema::dropIfExists('payroll_runs');
    }
};
