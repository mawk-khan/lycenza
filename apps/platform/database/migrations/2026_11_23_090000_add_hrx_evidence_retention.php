<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HRX.6 (E21-D9, ADR 0065 §27; project-adopted, pending legal ratification):
 * the narrow privileged path that expires ONE Employee's Leave and Staff
 * Attendance evidence 8 calendar years after the Employee's final
 * separation. It is a D9 participant of the existing employee retention
 * run (`platform:employee-retention-prune`), not a second engine.
 *
 * The runtime role keeps exactly the privileges it had: no DELETE on any
 * Leave or Staff Attendance evidence table (the ledger, decisions, request
 * days, close items and reconciliations and the attendance corrections are
 * append-only; requests, assignments and attendance records are
 * insert/update only). Two fixed-purpose functions in the E21.2B pattern
 * (SECURITY DEFINER, `search_path` pinned, every table qualified, EXECUTE
 * for the runtime role only, never PUBLIC, called only through
 * `RetentionExpiry`) do the work, and the DATABASE re-proves each unit:
 *
 * - the School is the caller's tenant context (`retention_assert_tenant`);
 * - `retention_assert_payroll_employee_floor` -- the one D9 Employee floor
 *   (E21.3F): the cutoff is at least 8 calendar years back; the Employee and
 *   its EmploymentRecords are locked FOR UPDATE (every HRX writer holds
 *   FOR SHARE / FOR KEY SHARE on them, so it waits); at least one
 *   EmploymentRecord, every one terminal with an `ends_on` before the
 *   cutoff (a current, future, rehired or undated Employee is refused);
 * - then each employment's `hrx.staff_employment` advisory lock is taken
 *   EXCLUSIVELY, sorted by id (the HRX lock order: HR rows, then staff
 *   employment): no HRX writer and no Payroll evidence capture is inside
 *   the unit while it is read and removed;
 * - every row of the unit must have been written before the cutoff. A late
 *   administrative write (a cancellation, a correction, a close
 *   reconciliation) is new evidence with its own clock: the unit is kept
 *   (`retention_hrx_dependency`) until it is itself old enough (longest
 *   period wins).
 *
 * - `retention_expire_leave_employee_evidence(school, employee, cutoff,
 *   dry run)`, leaves first, causally complete:
 *   1. ledger entries a close reconciliation produced;
 *   2. the reconciliations (of the Employee's close items or requests);
 *   3. the remaining ledger entries, leaves first (an entry no remaining
 *      entry reverses, repeated until none is left), so a reversal goes
 *      before what it reverses;
 *   4. request days, decisions, requests;
 *   5. close items, policy assignments.
 *   A decision this Employee made as another Employee's MANAGER belongs to
 *   that Employee's request: it stays, and keeps this Employee's HR root
 *   (`leave_decisions` blocks the HR evidence purge) until that request is
 *   itself expired.
 * - `retention_expire_staff_attendance_employee_evidence(school, employee,
 *   cutoff, dry run)`: the corrections, then the records -- a record and its
 *   whole correction history together, never one without the other.
 *
 * Never touched: School configuration (leave settings, years, start
 * changes, types, policies, the working calendar, allocation-run and
 * year-close headers -- tenant lifetime), audit events and outbox rows
 * (their own retention), and `payroll_run_hrx_inputs`, which is Payroll
 * evidence: it references no HRX row, survives this purge and leaves only
 * with its payroll result (E21.3F). No cascade is added anywhere; the
 * Employee root is still removed only by HR's own evidence purge, which
 * the live FK catalog keeps blocked while any HRX row references it.
 *
 * Each returns the rows removed (or, with dry run, the rows it would
 * remove after proving the unit), and refuses if anything of the unit
 * remains (`retention_hrx_unit`).
 *
 * Rollback removes the mechanism only (no marker, no column, no privilege);
 * deleted evidence cannot be restored.
 */
return new class extends Migration
{
    /** @var array<string, string> function => signature */
    private const FUNCTIONS = [
        'retention_expire_leave_employee_evidence' => 'uuid, uuid, date, boolean',
        'retention_expire_staff_attendance_employee_evidence' => 'uuid, uuid, date, boolean',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Shared prologue: tenant, D9 Employee floor (locks), then the employments'
            -- HRX advisory locks exclusively in id order. Not executable by the runtime role.
            CREATE FUNCTION retention_lock_hrx_employee(p_school_id uuid, p_employee_id uuid, p_cutoff date) RETURNS uuid[]
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_records uuid[];
                v_record uuid;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_payroll_employee_floor(p_school_id, p_employee_id, p_cutoff);
                SELECT coalesce(array_agg(id ORDER BY id), '{}') INTO v_records
                  FROM public.employment_records WHERE school_id = p_school_id AND employee_id = p_employee_id;
                FOREACH v_record IN ARRAY v_records LOOP
                    PERFORM pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended('hrx.staff_employment:' || p_school_id::text || ':' || v_record::text, 0));
                END LOOP;
                RETURN v_records;
            END;
            $$;

            CREATE FUNCTION retention_expire_leave_employee_evidence(p_school_id uuid, p_employee_id uuid, p_cutoff date, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_records uuid[];
                v_requests uuid[];
                v_entries uuid[];
                v_items uuid[];
                v_at timestamp := p_cutoff::timestamp;
                v_count integer := 0;
                v_step integer;
            BEGIN
                v_records := public.retention_lock_hrx_employee(p_school_id, p_employee_id, p_cutoff);

                -- Read only after the locks: nothing of the unit can change below.
                SELECT coalesce(array_agg(id), '{}') INTO v_requests FROM public.leave_requests
                 WHERE school_id = p_school_id AND (employee_id = p_employee_id OR employment_record_id = ANY (v_records));
                SELECT coalesce(array_agg(id), '{}') INTO v_entries FROM public.leave_ledger_entries
                 WHERE school_id = p_school_id AND employment_record_id = ANY (v_records);
                SELECT coalesce(array_agg(id), '{}') INTO v_items FROM public.leave_year_close_items
                 WHERE school_id = p_school_id AND employment_record_id = ANY (v_records);

                IF EXISTS (SELECT 1 FROM public.leave_ledger_entries WHERE school_id = p_school_id AND id = ANY (v_entries) AND created_at >= v_at)
                   OR EXISTS (SELECT 1 FROM public.leave_requests WHERE school_id = p_school_id AND id = ANY (v_requests) AND (created_at >= v_at OR updated_at >= v_at))
                   OR EXISTS (SELECT 1 FROM public.leave_request_days WHERE school_id = p_school_id AND leave_request_id = ANY (v_requests) AND created_at >= v_at)
                   OR EXISTS (SELECT 1 FROM public.leave_decisions WHERE school_id = p_school_id AND leave_request_id = ANY (v_requests) AND created_at >= v_at)
                   OR EXISTS (SELECT 1 FROM public.leave_year_close_items WHERE school_id = p_school_id AND id = ANY (v_items) AND created_at >= v_at)
                   OR EXISTS (SELECT 1 FROM public.leave_year_close_reconciliations WHERE school_id = p_school_id
                               AND (year_close_item_id = ANY (v_items) OR leave_request_id = ANY (v_requests)) AND created_at >= v_at)
                   OR EXISTS (SELECT 1 FROM public.leave_policy_assignments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records)
                               AND (created_at >= v_at OR updated_at >= v_at OR ended_at >= v_at)) THEN
                    RAISE EXCEPTION 'retention: the leave evidence was written less than the evidence period ago (retention_hrx_dependency)';
                END IF;

                IF p_dry_run THEN
                    SELECT cardinality(v_entries) + cardinality(v_requests) + cardinality(v_items)
                         + (SELECT count(*) FROM public.leave_request_days WHERE school_id = p_school_id AND leave_request_id = ANY (v_requests))
                         + (SELECT count(*) FROM public.leave_decisions WHERE school_id = p_school_id AND leave_request_id = ANY (v_requests))
                         + (SELECT count(*) FROM public.leave_year_close_reconciliations WHERE school_id = p_school_id
                             AND (year_close_item_id = ANY (v_items) OR leave_request_id = ANY (v_requests)))
                         + (SELECT count(*) FROM public.leave_policy_assignments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records))
                      INTO v_count;
                    RETURN v_count;
                END IF;

                -- 1-2. A reconciliation's own entries, then the reconciliations (they name a reversal entry).
                DELETE FROM public.leave_ledger_entries WHERE school_id = p_school_id AND id = ANY (v_entries) AND year_close_reconciliation_id IS NOT NULL;
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                DELETE FROM public.leave_year_close_reconciliations WHERE school_id = p_school_id
                   AND (year_close_item_id = ANY (v_items) OR leave_request_id = ANY (v_requests));
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                -- 3. Leaves first: an entry no remaining entry reverses.
                LOOP
                    DELETE FROM public.leave_ledger_entries e WHERE e.school_id = p_school_id AND e.id = ANY (v_entries)
                       AND NOT EXISTS (SELECT 1 FROM public.leave_ledger_entries r WHERE r.school_id = p_school_id AND r.reverses_entry_id = e.id);
                    GET DIAGNOSTICS v_step = ROW_COUNT;
                    EXIT WHEN v_step = 0;
                    v_count := v_count + v_step;
                END LOOP;
                -- 4. The requests with their day snapshots and decisions.
                DELETE FROM public.leave_request_days WHERE school_id = p_school_id AND leave_request_id = ANY (v_requests);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                DELETE FROM public.leave_decisions WHERE school_id = p_school_id AND leave_request_id = ANY (v_requests);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                DELETE FROM public.leave_requests WHERE school_id = p_school_id AND id = ANY (v_requests);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                -- 5. Close items (their header is School configuration) and the policy assignments.
                DELETE FROM public.leave_year_close_items WHERE school_id = p_school_id AND id = ANY (v_items);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                DELETE FROM public.leave_policy_assignments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;

                IF EXISTS (SELECT 1 FROM public.leave_ledger_entries WHERE school_id = p_school_id AND employment_record_id = ANY (v_records))
                   OR EXISTS (SELECT 1 FROM public.leave_requests WHERE school_id = p_school_id AND (employee_id = p_employee_id OR employment_record_id = ANY (v_records)))
                   OR EXISTS (SELECT 1 FROM public.leave_year_close_items WHERE school_id = p_school_id AND employment_record_id = ANY (v_records))
                   OR EXISTS (SELECT 1 FROM public.leave_policy_assignments WHERE school_id = p_school_id AND employment_record_id = ANY (v_records)) THEN
                    RAISE EXCEPTION 'retention: leave evidence remains (retention_hrx_unit)';
                END IF;
                RETURN v_count;
            END;
            $$;

            CREATE FUNCTION retention_expire_staff_attendance_employee_evidence(p_school_id uuid, p_employee_id uuid, p_cutoff date, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_records uuid[];
                v_attendance uuid[];
                v_at timestamp := p_cutoff::timestamp;
                v_count integer := 0;
                v_step integer;
            BEGIN
                v_records := public.retention_lock_hrx_employee(p_school_id, p_employee_id, p_cutoff);

                SELECT coalesce(array_agg(id), '{}') INTO v_attendance FROM public.staff_attendance_records
                 WHERE school_id = p_school_id AND (employee_id = p_employee_id OR employment_record_id = ANY (v_records));

                IF EXISTS (SELECT 1 FROM public.staff_attendance_records WHERE school_id = p_school_id AND id = ANY (v_attendance) AND (created_at >= v_at OR updated_at >= v_at))
                   OR EXISTS (SELECT 1 FROM public.staff_attendance_corrections WHERE school_id = p_school_id
                               AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)) AND created_at >= v_at) THEN
                    RAISE EXCEPTION 'retention: the attendance evidence was written less than the evidence period ago (retention_hrx_dependency)';
                END IF;

                IF p_dry_run THEN
                    SELECT cardinality(v_attendance)
                         + (SELECT count(*) FROM public.staff_attendance_corrections WHERE school_id = p_school_id
                             AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)))
                      INTO v_count;
                    RETURN v_count;
                END IF;

                -- A record goes only together with its whole correction history.
                DELETE FROM public.staff_attendance_corrections WHERE school_id = p_school_id
                   AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records));
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;
                DELETE FROM public.staff_attendance_records WHERE school_id = p_school_id AND id = ANY (v_attendance);
                GET DIAGNOSTICS v_step = ROW_COUNT;
                v_count := v_count + v_step;

                IF EXISTS (SELECT 1 FROM public.staff_attendance_records WHERE school_id = p_school_id AND (employee_id = p_employee_id OR employment_record_id = ANY (v_records)))
                   OR EXISTS (SELECT 1 FROM public.staff_attendance_corrections WHERE school_id = p_school_id AND employment_record_id = ANY (v_records)) THEN
                    RAISE EXCEPTION 'retention: attendance evidence remains (retention_hrx_unit)';
                END IF;
                RETURN v_count;
            END;
            $$;
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION retention_lock_hrx_employee(uuid, uuid, date) FROM PUBLIC');
        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO school_os_app");
        }
    }

    public function down(): void
    {
        foreach (self::FUNCTIONS as $function => $signature) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}({$signature})");
        }
        DB::statement('DROP FUNCTION IF EXISTS retention_lock_hrx_employee(uuid, uuid, date)');
    }
};
