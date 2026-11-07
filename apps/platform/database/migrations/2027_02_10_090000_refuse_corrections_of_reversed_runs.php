<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C8 (PAY-17): a correction needs a target that stands.
 *
 * The contract was already written: a correction of a reversed run is "a delta with no
 * economic basis". But the check ran once, unlocked, at creation, the database looked
 * at status only (a reversed run stays `posted`, ADR 0034), and approval and posting
 * never looked again. Reproduced at c4ea0307: a correction was approved and posted after
 * its target was reversed, leaving 500.00 in the ledger with nothing under it, and a raw
 * INSERT on a reversed target was accepted.
 *
 * - Creating, approving or posting a correction holds its target FOR SHARE and refuses a
 *   reversed one. A reversal holds that row FOR UPDATE, so each is ordered wholly before
 *   or after the other.
 * - A reversal stays unblocked (ADR 0034). A correction already posted stands, and is
 *   recorded on the reversal, for an authorized person to decide (owner, HR.R1-C8).
 * - A refused correction can never post, so it is a dead record. The retention functions
 *   waited for every run of a group to post, which would have held it, its target and
 *   their Employees forever. A dead correction now ages from its own creation. Those
 *   functions are patched in place: see RETENTION.
 *
 * No backfill: an existing correction keeps what it has. Nothing here deletes a row.
 */
return new class extends Migration
{
    /**
     * The one clause each retention function changes. These are patched in their LIVE
     * definition, never restated: later migrations added the retention identity, hold
     * and anchor checks to them, and a copy of an older body would silently remove those.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const RETENTION = [
        'public.retention_expire_payroll_employee_evidence(uuid, uuid, date, boolean)' => [
            "AND (status <> 'posted' OR posted_at IS NULL OR posted_at >= p_cutoff::timestamp))",
            "AND CASE WHEN public.payroll_run_is_dead_correction(p_school_id, id) THEN created_at >= p_cutoff::timestamp ELSE (status <> 'posted' OR posted_at IS NULL OR posted_at >= p_cutoff::timestamp) END)",
        ],
        'public.retention_expire_payroll_run(uuid, uuid, timestamp, boolean)' => [
            "AND (status <> 'posted' OR posted_at IS NULL OR posted_at >= p_cutoff OR results_expired_at IS NULL))",
            "AND CASE WHEN public.payroll_run_is_dead_correction(p_school_id, id) THEN created_at >= p_cutoff ELSE (status <> 'posted' OR posted_at IS NULL OR posted_at >= p_cutoff OR results_expired_at IS NULL) END)",
        ],
    ];

    private function patch(string $signature, string $from, string $to): void
    {
        $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS d', [$signature])->d;
        if (! str_contains($definition, $from)) {
            throw new RuntimeException("{$signature} no longer has the clause this migration changes.");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.payroll_run_target_reversed(p_school uuid, p_target uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT EXISTS (SELECT 1 FROM public.payroll_run_postings p
                                WHERE p.school_id = p_school AND p.payroll_run_id = p_target AND p.posting_kind = 'reversal')
            $$;

            CREATE FUNCTION public.payroll_run_is_dead_correction(p_school uuid, p_run uuid) RETURNS boolean
                LANGUAGE sql STABLE SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
                SELECT COALESCE((SELECT r.status <> 'posted' AND public.payroll_run_target_reversed(p_school, r.corrects_payroll_run_id)
                                   FROM public.payroll_runs r
                                  WHERE r.school_id = p_school AND r.id = p_run AND r.run_kind = 'correction'), false)
            $$;

            CREATE OR REPLACE FUNCTION payroll_runs_validate_correction_target() RETURNS trigger AS $$
            DECLARE
                target_kind text;
                target_status text;
            BEGIN
                IF NEW.corrects_payroll_run_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT run_kind, status INTO target_kind, target_status
                FROM payroll_runs WHERE id = NEW.corrects_payroll_run_id FOR SHARE;

                IF target_kind IS DISTINCT FROM 'regular' THEN
                    RAISE EXCEPTION 'payroll_runs: corrects_payroll_run_id (%) must reference a regular run, got %.', NEW.corrects_payroll_run_id, target_kind;
                END IF;

                IF target_status IS DISTINCT FROM 'posted' THEN
                    RAISE EXCEPTION 'payroll_runs: corrects_payroll_run_id (%) must reference a posted run, got %.', NEW.corrects_payroll_run_id, target_status;
                END IF;

                IF public.payroll_run_target_reversed(NEW.school_id, NEW.corrects_payroll_run_id) THEN
                    RAISE EXCEPTION 'payroll_runs: a correction needs a target that stands (%)', NEW.corrects_payroll_run_id USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE FUNCTION public.payroll_runs_correction_target_standing() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                PERFORM 1 FROM public.payroll_runs WHERE school_id = NEW.school_id AND id = NEW.corrects_payroll_run_id FOR SHARE;
                IF public.payroll_run_target_reversed(NEW.school_id, NEW.corrects_payroll_run_id) THEN
                    RAISE EXCEPTION 'payroll_runs: a correction needs a target that stands (%)', NEW.corrects_payroll_run_id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_payroll_runs_correction_target_standing
                BEFORE UPDATE OF status ON payroll_runs
                FOR EACH ROW WHEN (NEW.corrects_payroll_run_id IS NOT NULL AND OLD.status IS DISTINCT FROM NEW.status AND NEW.status IN ('approved', 'posted'))
                EXECUTE FUNCTION public.payroll_runs_correction_target_standing();

            REVOKE ALL ON FUNCTION public.payroll_run_target_reversed(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_run_is_dead_correction(uuid, uuid) FROM PUBLIC;
            REVOKE ALL ON FUNCTION public.payroll_runs_correction_target_standing() FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION public.payroll_run_target_reversed(uuid, uuid) TO school_os_app;
            GRANT EXECUTE ON FUNCTION public.payroll_runs_correction_target_standing() TO school_os_app;
            SQL);

        foreach (self::RETENTION as $signature => [$live, $patched]) {
            $this->patch($signature, $live, $patched);
        }
    }

    public function down(): void
    {
        foreach (self::RETENTION as $signature => [$live, $patched]) {
            $this->patch($signature, $patched, $live);
        }

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

            DROP TRIGGER trg_payroll_runs_correction_target_standing ON payroll_runs;
            DROP FUNCTION public.payroll_runs_correction_target_standing();
            DROP FUNCTION public.payroll_run_is_dead_correction(uuid, uuid);
            DROP FUNCTION public.payroll_run_target_reversed(uuid, uuid);
            SQL);
    }
};
