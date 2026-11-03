<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR.10 (ADR 0065 §29): monthly accrual and opening entries on the ONE
 * Leave ledger.
 *
 * Policy version (`leave_policies`, frozen by the existing whole-row trigger):
 * - `entitlement_method`: `annual` (the existing allocation run; default for
 *   every existing version, so nothing changes) or `monthly`. The two are
 *   exclusive alternatives: a monthly version has `annual_allocation_units =
 *   0`, `monthly_accrual_units` (1..62 half-day units) and `accrual_timing`
 *   (`period_start`: a month is postable from its first day;
 *   `period_end`: from the day after its last day). An annual version has
 *   neither.
 *
 * Ledger (`leave_ledger_entries`; append-only, never negative, as before):
 * - kind `accrual`: one month's credit -- `accrual_period` (the first day of
 *   the month, inside the leave year), the policy assignment that covered the
 *   WHOLE month, and the run. One per employment x type x year x month
 *   (`leave_ledger_entries_one_accrual`).
 * - kind `opening`: a go-live opening balance -- one per account, and only
 *   as the account's first entry.
 * - Both are credits. Every earlier kind keeps its meaning and sign.
 * - Funding exclusivity per account (employment x type x year): an
 *   `allocation` and an `accrual` never coexist (trigger, under the balance
 *   lock the trigger already takes).
 * - The trigger checks every accrual against the assignment it names: same
 *   employment and type, covering the whole month, of a MONTHLY version whose
 *   `monthly_accrual_units` equals the entry -- so no path, raw SQL included,
 *   can credit a month the policy does not grant.
 * - A closed year still refuses both (unchanged rule).
 *
 * Runs:
 * - `leave_accrual_runs`: one header per School x type x year x month and
 *   attempt; at most one open (`processing` or `failed`) per month; resumable
 *   by its employment-id cursor. School configuration/operations history
 *   without per-Employee amounts (tenant lifetime, like allocation runs).
 * - `leave_accrual_run_items`: one row per examined employment with its
 *   closed outcome and units -- the run's reconciliation evidence. It goes
 *   with its employment (cascade), so it never blocks an Employee purge.
 *
 * No proration, no expiry execution, no sandwich rule (ADR 0065 §22.2,
 * §23.8, §28.9). Rollback refuses once any HR.10 evidence exists.
 */
return new class extends Migration
{
    private const KINDS_BEFORE = "'allocation', 'adjustment', 'consumption', 'reversal', 'carry_forward_in', 'carry_forward_out', 'expiry'";

    private const KINDS_AFTER = "'allocation', 'adjustment', 'consumption', 'reversal', 'carry_forward_in', 'carry_forward_out', 'expiry', 'opening', 'accrual'";

    private const REASONS = "'entitlement_change', 'allocation_correction', 'administrative_correction'";

    private const OUTCOMES = "'credited', 'already_credited', 'not_due', 'partial_period', 'no_policy', 'assignment_not_whole_period', 'not_monthly_policy', 'annual_allocation_exists'";

    private const ANCHOR_BEFORE = "'created_at', 'allocation_run_id', 'employment_record_id', 'leave_policy_assignment_id', 'leave_request_id', 'leave_type_id', 'leave_year_id', 'reverses_entry_id', 'tracks_balance', 'year_close_id', 'year_close_reconciliation_id', 'source_id'";

    private const ANCHOR_AFTER = "'created_at', 'allocation_run_id', 'employment_record_id', 'leave_policy_assignment_id', 'leave_request_id', 'leave_type_id', 'leave_year_id', 'reverses_entry_id', 'tracks_balance', 'year_close_id', 'year_close_reconciliation_id', 'source_id', 'accrual_run_id'";

    public function up(): void
    {
        // --- Policy version: the entitlement method -----------------------
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->string('entitlement_method', 16)->default('annual');
            $table->smallInteger('monthly_accrual_units')->nullable();
            $table->string('accrual_timing', 16)->nullable();
        });
        DB::statement('ALTER TABLE leave_policies ADD CONSTRAINT leave_policies_entitlement_check CHECK ('
            ."(entitlement_method = 'annual' AND monthly_accrual_units IS NULL AND accrual_timing IS NULL) "
            ."OR (entitlement_method = 'monthly' AND annual_allocation_units = 0 AND monthly_accrual_units BETWEEN 1 AND 62 "
            ."AND accrual_timing IN ('period_start', 'period_end')))");

        // --- Runs ---------------------------------------------------------
        Schema::create('leave_accrual_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('leave_type_id');
            $table->uuid('leave_year_id');
            $table->date('period_start');
            $table->string('status', 16)->default('processing');
            $table->uuid('cursor_employment_id')->nullable();
            $table->integer('batch_count')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->foreignUuid('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'school_id', 'leave_type_id', 'leave_year_id']);
            $table->index(['school_id', 'leave_type_id', 'leave_year_id', 'period_start'], 'leave_accrual_runs_period_idx');
            $table->foreign(['leave_type_id', 'school_id'], 'leave_accrual_runs_type_fk')->references(['id', 'school_id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['leave_year_id', 'school_id'], 'leave_accrual_runs_year_fk')->references(['id', 'school_id'])->on('leave_years')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_accrual_runs ADD CONSTRAINT leave_accrual_runs_shape_check CHECK ('
            ."status IN ('processing', 'completed', 'failed') AND EXTRACT(DAY FROM period_start) = 1 AND batch_count >= 0 "
            ."AND ((status = 'completed') = (completed_at IS NOT NULL)) AND ((status = 'failed') = (failure_code IS NOT NULL)))");
        // At most one OPEN run per month: a failed run is resumed, never duplicated.
        DB::statement("CREATE UNIQUE INDEX leave_accrual_runs_one_open ON leave_accrual_runs (school_id, leave_type_id, leave_year_id, period_start) WHERE status IN ('processing', 'failed')");

        Schema::create('leave_accrual_run_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('accrual_run_id');
            $table->uuid('employment_record_id');
            $table->string('outcome', 32);
            $table->smallInteger('units')->nullable();
            $table->boolean('eligibility_drift')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'school_id']);
            $table->unique(['accrual_run_id', 'employment_record_id'], 'leave_accrual_run_items_once');
            $table->index(['school_id', 'employment_record_id']);
            $table->foreign(['accrual_run_id', 'school_id'], 'leave_accrual_run_items_run_fk')->references(['id', 'school_id'])->on('leave_accrual_runs')->restrictOnDelete();
            // Processing evidence goes with its employment: it never blocks a lawful Employee purge.
            $table->foreign(['employment_record_id', 'school_id'], 'leave_accrual_run_items_employment_fk')->references(['id', 'school_id'])->on('employment_records')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE leave_accrual_run_items ADD CONSTRAINT leave_accrual_run_items_shape_check CHECK ('
            .'outcome IN ('.self::OUTCOMES.") AND ((outcome IN ('credited', 'already_credited')) = (units IS NOT NULL)) AND (units IS NULL OR units > 0))");

        // --- Ledger: the two new kinds -------------------------------------
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->date('accrual_period')->nullable();
            $table->uuid('accrual_run_id')->nullable();
            $table->index('accrual_run_id');
            $table->foreign(['accrual_run_id', 'school_id', 'leave_type_id', 'leave_year_id'], 'leave_ledger_entries_accrual_run_fk')
                ->references(['id', 'school_id', 'leave_type_id', 'leave_year_id'])->on('leave_accrual_runs')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT leave_ledger_entries_shape_check');
        DB::statement($this->shapeCheck(self::KINDS_AFTER, true));
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_one_accrual ON leave_ledger_entries (school_id, employment_record_id, leave_type_id, leave_year_id, accrual_period) WHERE kind = 'accrual'");
        DB::statement("CREATE UNIQUE INDEX leave_ledger_entries_one_opening ON leave_ledger_entries (school_id, employment_record_id, leave_type_id, leave_year_id) WHERE kind = 'opening'");
        DB::unprepared($this->guardFunction(true));

        DB::statement('DROP TRIGGER zzz_retention_anchor ON leave_ledger_entries');
        DB::statement('CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON leave_ledger_entries FOR EACH ROW EXECUTE FUNCTION retention_stamp_anchor('.self::ANCHOR_AFTER.')');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION leave_accrual_runs_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.leave_type_id IS DISTINCT FROM OLD.leave_type_id
                   OR NEW.leave_year_id IS DISTINCT FROM OLD.leave_year_id OR NEW.period_start IS DISTINCT FROM OLD.period_start
                   OR NEW.started_by_user_id IS DISTINCT FROM OLD.started_by_user_id OR NEW.started_at IS DISTINCT FROM OLD.started_at THEN
                    RAISE EXCEPTION 'leave_accrual_runs: a run''s identity never changes (leave_accrual_run_immutable)';
                END IF;
                IF OLD.status = 'completed' THEN
                    RAISE EXCEPTION 'leave_accrual_runs: a completed run is final (leave_accrual_run_immutable)';
                END IF;
                IF NEW.batch_count < OLD.batch_count OR (OLD.cursor_employment_id IS NOT NULL AND (NEW.cursor_employment_id IS NULL OR NEW.cursor_employment_id < OLD.cursor_employment_id)) THEN
                    RAISE EXCEPTION 'leave_accrual_runs: a run only moves forward (leave_accrual_run_immutable)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_accrual_runs_guard BEFORE UPDATE ON leave_accrual_runs FOR EACH ROW EXECUTE FUNCTION leave_accrual_runs_guard();

            CREATE FUNCTION leave_accrual_run_items_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'leave_accrual_run_items: run evidence never changes';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM leave_accrual_runs WHERE id = NEW.accrual_run_id AND school_id = NEW.school_id AND status = 'processing') THEN
                    RAISE EXCEPTION 'leave_accrual_run_items: evidence is written only by a processing run (leave_accrual_run_not_processing)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_accrual_run_items_guard BEFORE INSERT OR UPDATE ON leave_accrual_run_items FOR EACH ROW EXECUTE FUNCTION leave_accrual_run_items_guard();

            -- A policy assignment is never shortened past a month it already funded by accrual
            -- (the credited month would lose the coverage the ledger guard checked when it was posted).
            CREATE FUNCTION leave_policy_assignments_accrual_bounds() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.effective_to IS NOT NULL AND (OLD.effective_to IS NULL OR NEW.effective_to < OLD.effective_to)
                   AND EXISTS (SELECT 1 FROM leave_ledger_entries WHERE school_id = NEW.school_id AND leave_policy_assignment_id = NEW.id
                                AND kind = 'accrual' AND (accrual_period + INTERVAL '1 month - 1 day')::date > NEW.effective_to) THEN
                    RAISE EXCEPTION 'leave_policy_assignments: the assignment already funded a later month by accrual (leave_assignment_accrued)';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER trg_leave_policy_assignments_accrual_bounds BEFORE UPDATE ON leave_policy_assignments FOR EACH ROW EXECUTE FUNCTION leave_policy_assignments_accrual_bounds();
            SQL);

        foreach (['leave_accrual_runs', 'leave_accrual_run_items'] as $table) {
            TenantRls::enable($table);
        }
        TenantRls::revokeDelete('leave_accrual_runs');
        TenantRls::makeAppendOnly('leave_accrual_run_items');
    }

    public function down(): void
    {
        $evidence = DB::selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM leave_ledger_entries WHERE kind IN ('opening', 'accrual')) AS entries,
                   (SELECT count(*) FROM leave_accrual_runs) AS runs,
                   (SELECT count(*) FROM leave_policies WHERE entitlement_method <> 'annual') AS monthly
            SQL);
        if ((int) $evidence->entries + (int) $evidence->runs + (int) $evidence->monthly > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to roll back HR.10: it would lose %d opening/accrual ledger entr(ies), %d accrual run(s) and the method of %d monthly policy version(s).',
                $evidence->entries, $evidence->runs, $evidence->monthly,
            ));
        }

        DB::statement('DROP TRIGGER zzz_retention_anchor ON leave_ledger_entries');
        DB::statement('CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON leave_ledger_entries FOR EACH ROW EXECUTE FUNCTION retention_stamp_anchor('.self::ANCHOR_BEFORE.')');
        DB::unprepared($this->guardFunction(false));
        DB::statement('DROP INDEX IF EXISTS leave_ledger_entries_one_opening');
        DB::statement('DROP INDEX IF EXISTS leave_ledger_entries_one_accrual');
        DB::statement('ALTER TABLE leave_ledger_entries DROP CONSTRAINT leave_ledger_entries_shape_check');
        DB::statement($this->shapeCheck(self::KINDS_BEFORE, false));
        Schema::table('leave_ledger_entries', function (Blueprint $table) {
            $table->dropForeign('leave_ledger_entries_accrual_run_fk');
            $table->dropIndex(['accrual_run_id']);
            $table->dropColumn(['accrual_period', 'accrual_run_id']);
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_leave_policy_assignments_accrual_bounds ON leave_policy_assignments;
            DROP FUNCTION IF EXISTS leave_policy_assignments_accrual_bounds();
            DROP TRIGGER IF EXISTS trg_leave_accrual_run_items_guard ON leave_accrual_run_items;
            DROP TRIGGER IF EXISTS trg_leave_accrual_runs_guard ON leave_accrual_runs;
            DROP FUNCTION IF EXISTS leave_accrual_run_items_guard();
            DROP FUNCTION IF EXISTS leave_accrual_runs_guard();
            SQL);
        foreach (['leave_accrual_run_items', 'leave_accrual_runs'] as $table) {
            TenantRls::disable($table);
            Schema::dropIfExists($table);
        }

        DB::statement('ALTER TABLE leave_policies DROP CONSTRAINT leave_policies_entitlement_check');
        Schema::table('leave_policies', function (Blueprint $table) {
            $table->dropColumn(['entitlement_method', 'monthly_accrual_units', 'accrual_timing']);
        });
    }

    private function shapeCheck(string $kinds, bool $hr10): string
    {
        $sql = 'ALTER TABLE leave_ledger_entries ADD CONSTRAINT leave_ledger_entries_shape_check CHECK ('
            .'kind IN ('.$kinds.') AND units > 0 AND tracks_balance '
            ."AND ((kind = 'adjustment') = (direction IS NOT NULL)) AND (direction IS NULL OR direction IN ('credit', 'debit')) "
            ."AND ((kind = 'adjustment') = (reason_code IS NOT NULL)) AND (reason_code IS NULL OR reason_code IN (".self::REASONS.')) '
            ."AND (allocation_run_id IS NULL OR kind = 'allocation') "
            ."AND ((kind = 'reversal') = (reverses_entry_id IS NOT NULL)) "
            .'AND ((source_type IS NULL) = (source_id IS NULL))';
        if ($hr10) {
            $sql .= " AND ((kind = 'accrual') = (accrual_period IS NOT NULL)) AND (accrual_period IS NULL OR EXTRACT(DAY FROM accrual_period) = 1)"
                ." AND ((kind = 'accrual') = (accrual_run_id IS NOT NULL)) AND (kind <> 'accrual' OR leave_policy_assignment_id IS NOT NULL)"
                ." AND (source_type IS NULL OR (kind = 'adjustment' AND source_type = 'leave_ledger_entry'))";
        }

        return $sql.')';
    }

    /** The ledger guard. HR.10 adds the two credit kinds, the accrual and opening checks and funding exclusivity; the rest is the HRX.2 body unchanged. */
    private function guardFunction(bool $hr10): string
    {
        $credits = $hr10 ? "'allocation', 'reversal', 'carry_forward_in', 'opening', 'accrual'" : "'allocation', 'reversal', 'carry_forward_in'";
        $hr10Checks = ! $hr10 ? '' : <<<'SQL'

                IF NEW.kind = 'opening' AND EXISTS (SELECT 1 FROM leave_ledger_entries WHERE school_id = NEW.school_id AND employment_record_id = NEW.employment_record_id
                        AND leave_type_id = NEW.leave_type_id AND leave_year_id = NEW.leave_year_id) THEN
                    RAISE EXCEPTION 'leave_ledger_entries: an opening balance is the account''s first entry (leave_opening_not_first)';
                END IF;

                IF NEW.kind = 'accrual' THEN
                    SELECT * INTO v_year FROM leave_years WHERE id = NEW.leave_year_id AND school_id = NEW.school_id;
                    v_period_end := (NEW.accrual_period + INTERVAL '1 month - 1 day')::date;
                    IF NEW.accrual_period < v_year.starts_on OR v_period_end > v_year.ends_on THEN
                        RAISE EXCEPTION 'leave_ledger_entries: an accrual month lies inside its leave year (leave_accrual_invalid)';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM leave_policy_assignments a JOIN leave_policies p ON p.id = a.leave_policy_id AND p.school_id = a.school_id
                            WHERE a.id = NEW.leave_policy_assignment_id AND a.school_id = NEW.school_id
                              AND a.employment_record_id = NEW.employment_record_id AND a.leave_type_id = NEW.leave_type_id
                              AND a.effective_from <= NEW.accrual_period AND coalesce(a.effective_to, 'infinity'::date) >= v_period_end
                              AND p.entitlement_method = 'monthly' AND p.monthly_accrual_units = NEW.units) THEN
                        RAISE EXCEPTION 'leave_ledger_entries: an accrual is the monthly units of the version assigned for the whole month (leave_accrual_invalid)';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM leave_accrual_runs WHERE id = NEW.accrual_run_id AND school_id = NEW.school_id
                            AND period_start = NEW.accrual_period AND status = 'processing') THEN
                        RAISE EXCEPTION 'leave_ledger_entries: an accrual is posted by a processing run of its own month (leave_accrual_invalid)';
                    END IF;
                END IF;

                IF (NEW.kind = 'accrual' AND EXISTS (SELECT 1 FROM leave_ledger_entries WHERE school_id = NEW.school_id AND employment_record_id = NEW.employment_record_id
                        AND leave_type_id = NEW.leave_type_id AND leave_year_id = NEW.leave_year_id AND kind = 'allocation'))
                   OR (NEW.kind = 'allocation' AND EXISTS (SELECT 1 FROM leave_ledger_entries WHERE school_id = NEW.school_id AND employment_record_id = NEW.employment_record_id
                        AND leave_type_id = NEW.leave_type_id AND leave_year_id = NEW.leave_year_id AND kind = 'accrual')) THEN
                    RAISE EXCEPTION 'leave_ledger_entries: an account is funded by an annual allocation or by monthly accrual, never both (leave_funding_conflict)';
                END IF;

                IF NEW.kind = 'adjustment' AND NEW.source_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM leave_ledger_entries
                        WHERE id = NEW.source_id AND school_id = NEW.school_id AND employment_record_id = NEW.employment_record_id
                          AND leave_type_id = NEW.leave_type_id AND leave_year_id = NEW.leave_year_id) THEN
                    RAISE EXCEPTION 'leave_ledger_entries: a correcting adjustment names an entry of the same account (leave_adjustment_source_invalid)';
                END IF;
SQL;
        $declare = $hr10 ? "\n                v_year leave_years%ROWTYPE;\n                v_period_end date;" : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION leave_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE
                v_balance bigint;
                v_target leave_ledger_entries%ROWTYPE;
                v_closed boolean;
                v_day_units bigint;{$declare}
            BEGIN
                -- Year first (the close holds it exclusively), then the balance key.
                PERFORM pg_advisory_xact_lock_shared(hashtextextended('leave.year:' || NEW.school_id::text || ':' || NEW.leave_year_id::text, 0));
                PERFORM pg_advisory_xact_lock(hashtextextended('leave.balance:' || NEW.school_id::text || ':' || NEW.employment_record_id::text || ':'
                    || NEW.leave_type_id::text || ':' || NEW.leave_year_id::text, 0));

                v_closed := EXISTS (SELECT 1 FROM leave_year_closes WHERE school_id = NEW.school_id AND leave_year_id = NEW.leave_year_id);
                IF v_closed AND NOT (NEW.kind = 'reversal' OR (NEW.kind IN ('carry_forward_out', 'expiry') AND NEW.year_close_id IS NOT NULL)) THEN
                    RAISE EXCEPTION 'leave_ledger_entries: the leave year is closed (leave_year_closed)';
                END IF;

                IF NEW.kind = 'consumption' THEN
                    SELECT coalesce(sum(units), 0) INTO v_day_units FROM leave_request_days
                     WHERE school_id = NEW.school_id AND leave_request_id = NEW.leave_request_id AND leave_year_id = NEW.leave_year_id;
                    IF v_day_units <> NEW.units OR NOT EXISTS (SELECT 1 FROM leave_requests WHERE id = NEW.leave_request_id AND school_id = NEW.school_id
                            AND status = 'submitted' AND employment_record_id = NEW.employment_record_id AND leave_type_id = NEW.leave_type_id) THEN
                        RAISE EXCEPTION 'leave_ledger_entries: a consumption equals its approval''s day evidence for the year (leave_consumption_invalid)';
                    END IF;
                END IF;

                IF NEW.kind = 'reversal' THEN
                    SELECT * INTO v_target FROM leave_ledger_entries WHERE id = NEW.reverses_entry_id AND school_id = NEW.school_id;
                    IF v_target.kind IS DISTINCT FROM 'consumption'
                       OR v_target.employment_record_id <> NEW.employment_record_id OR v_target.leave_type_id <> NEW.leave_type_id
                       OR v_target.leave_year_id <> NEW.leave_year_id OR v_target.units <> NEW.units
                       OR v_target.leave_request_id IS DISTINCT FROM NEW.leave_request_id THEN
                        RAISE EXCEPTION 'leave_ledger_entries: a reversal undoes exactly one consumption of the same balance (leave_reversal_invalid)';
                    END IF;
                END IF;
                {$hr10Checks}
                SELECT coalesce(sum(CASE
                        WHEN kind IN ({$credits}) THEN units
                        WHEN kind = 'adjustment' AND direction = 'credit' THEN units
                        ELSE -units END), 0)
                  INTO v_balance
                  FROM leave_ledger_entries
                 WHERE school_id = NEW.school_id AND employment_record_id = NEW.employment_record_id
                   AND leave_type_id = NEW.leave_type_id AND leave_year_id = NEW.leave_year_id;

                v_balance := v_balance + CASE
                        WHEN NEW.kind IN ({$credits}) THEN NEW.units
                        WHEN NEW.kind = 'adjustment' AND NEW.direction = 'credit' THEN NEW.units
                        ELSE -NEW.units END;
                IF v_balance < 0 THEN
                    RAISE EXCEPTION 'leave_ledger_entries: the leave balance cannot go below zero (leave_balance_negative)';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL;
    }
};
