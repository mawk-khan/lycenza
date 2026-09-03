<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 9.11 (Security/Concurrency/Migration Closure) -- closes a
     * gap the original `payroll_periods` migration left open: unlike
     * `payroll_runs` (whose `trg_payroll_runs_validate_transition`
     * trigger is explicit, documented defense-in-depth alongside the
     * Application layer's conditional-UPDATE claim,
     * `2026_09_15_090600_create_payroll_runs_table.php`), a period's
     * `status` column had no transition guard at all -- neither an
     * Application-layer check nor a database trigger. `open()`/
     * `close()` gained the Application-layer conditional-UPDATE claim
     * (`WHERE status = 'draft'`/`WHERE status = 'open'`) in this same
     * checkpoint; this trigger is the same defense-in-depth backstop
     * `payroll_runs` already has, against an invalid transition
     * (skipping a state, moving backward, reopening a closed period)
     * even via raw SQL that bypasses the Application layer entirely.
     * `draft -> open -> closed` is the sole valid path; same-status
     * no-op is allowed (mirrors `payroll_runs_validate_transition`'s
     * identical `IF NEW.status = OLD.status THEN RETURN NEW;` shape).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payroll_periods_validate_transition() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = OLD.status THEN
                    RETURN NEW;
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'open' THEN
                    RETURN NEW;
                ELSIF OLD.status = 'open' AND NEW.status = 'closed' THEN
                    RETURN NEW;
                ELSE
                    RAISE EXCEPTION 'payroll_periods: invalid status transition % -> % for period %.', OLD.status, NEW.status, OLD.id;
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payroll_periods_validate_transition
                BEFORE UPDATE ON payroll_periods
                FOR EACH ROW
                EXECUTE FUNCTION payroll_periods_validate_transition();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payroll_periods_validate_transition ON payroll_periods');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_periods_validate_transition()');
    }
};
