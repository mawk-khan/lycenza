<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.7 (ADR 0066 §15): retention eligibility counts only from times
 * PostgreSQL recorded -- never from a clock the runtime role can write.
 *
 * Every retention-relevant clock in this schema is written by the
 * application: `revoked_at`, `occurred_at`, `posted_at`, `closed_at`,
 * `delivered_at`, `processed_at`, `updated_at`, ... and the foreign keys that
 * place a row on an eligibility path (`academic_year_id`, `subject_offering_id`,
 * `student_id`, ...). The runtime role can INSERT such a value in the past,
 * or UPDATE a genuine row back in time or onto an older parent, and so make
 * genuine data eligible early. Those columns keep their domain meaning
 * (a business date may legitimately be historical); this migration adds the
 * database's own record next to them.
 *
 * The recorded anchor (`retention_recorded_at`, ANCHORS):
 * - `retention_stamp_anchor()` (BEFORE INSERT OR UPDATE, named to fire after
 *   every other BEFORE trigger) sets it from the database clock on INSERT,
 *   and again on every UPDATE that sets one of the table's tracked columns
 *   to a new non-NULL value: its eligibility clocks and statuses, and every
 *   link -- each foreign key and each logical `*_id` link without a
 *   constraint (an outbox `event_id`), other than user/role/request
 *   attribution -- so re-linking a row onto an older parent re-records it. Clearing a value (a SET NULL action,
 *   an unlink) never makes a row eligible, so it keeps the old anchor.
 *   Otherwise the old value stays. A caller-supplied anchor is ignored.
 * - Only a session whose LOGIN is the schema owner (session_user, so never a
 *   SECURITY DEFINER function a lower role calls) may set it explicitly:
 *   the reviewed maintenance/import path (model D), never ordinary runtime.
 * - The retention identity has no INSERT or UPDATE anywhere, so it can
 *   consume eligibility, never record it.
 *
 * Enforcement (`retention_guard_retention_delete()`, extended): every table
 * a retention path deletes from carries the statement guard (E21-RH.6's 51
 * plus the tables the database expiry functions delete from). For a
 * retention-session delete of an anchored table the unit must have DECLARED
 * its cutoff (`app.retention_anchor_cutoff`, set by the unit on its own
 * trusted session -- RetentionExpiry::retained() for the PHP units, the
 * function prologue for the database ones), and every deleted row must have
 * been recorded before it; otherwise PostgreSQL refuses (`retention_anchor`),
 * whatever any clock says. A forged or re-linked row was recorded when it
 * was forged, so it waits out the full period from then.
 *
 * Selection: the bulk expiry functions, the LMS functions (offering and
 * teaching-assignment anchors) and the Finance unit (period anchor) also
 * require the anchor, so a forged row is skipped rather than failing its
 * batch.
 *
 * Existing rows (no trustworthy source of when they were recorded): their
 * anchor is THIS migration's time. Uncertainty extends retention, it never
 * shortens it.
 *
 * Not anchored, deliberately: the tables where the runtime role holds DELETE
 * for a product reason (student_guardian_relationships, six HR profile
 * tables, failed_jobs) -- a forged clock adds nothing to a delete it may
 * already perform -- and the hold-exempt short-lived security state
 * (idempotency keys, account recovery, staff credentials).
 *
 * `down()` refuses: undoing this would hand the runtime role its eligibility
 * forgery back (see the fence, 2026_12_01_090000).
 */
return new class extends Migration
{
    private const ROLE = 'school_os_retention';

    /** table => tracked columns (a change re-records the row). */
    public const ANCHORS = [
        // Students (exit-measured units) and Student core
        'students' => [],
        'student_enrollments' => ['student_id', 'ends_on', 'status', 'academic_year_id', 'campus_id', 'grade_level_id', 'section_id'],
        'student_subject_enrollments' => ['status', 'academic_year_id', 'elective_group_id', 'student_enrollment_id', 'student_id', 'subject_offering_id'],
        'attendance_records' => ['academic_year_id', 'attendance_session_id', 'campus_id', 'grade_level_id', 'section_id', 'student_enrollment_id'],
        'enrollment_rollover_items' => ['mapping_id', 'plan_id', 'source_enrollment_id', 'student_id', 'target_enrollment_id', 'target_section_id'],
        'library_loans' => ['student_id', 'library_copy_id'],
        'transport_student_assignments' => ['student_id', 'dropoff_stop_id', 'pickup_stop_id', 'route_id'],
        'hostel_residency_assignments' => ['student_id', 'hostel_bed_id'],
        'student_guardian_account_links' => ['status', 'unlinked_at', 'guardian_id', 'school_membership_id', 'student_id'],
        'admission_applications' => ['status', 'terminal_at', 'applicant_id', 'academic_year_id', 'campus_id', 'converted_student_enrollment_id', 'converted_student_id', 'grade_level_id'],
        'applicants' => [],
        'communication_domain_preferences' => ['guardian_id', 'student_id'],
        'documents' => ['student_id', 'guardian_id', 'employee_id', 'learning_content_id', 'assignment_id'],
        'student_processing_authorizations' => ['student_id', 'purpose', 'student_guardian_relationship_id', 'terminates_authorization_id'],
        'communication_domain_consent_events' => ['guardian_id', 'student_id'],
        // Guardians
        'guardians' => ['no_relationship_since'],
        'guardian_contacts' => ['guardian_id'],
        // Employees and Payroll per-employment configuration
        'employees' => [],
        'employment_records' => ['employee_id', 'ends_on', 'status', 'employee_category_id'],
        'employee_assignments' => ['employment_record_id', 'campus_id', 'department_id', 'manager_assignment_id', 'position_id'],
        'employee_personal_details' => ['employee_id'],
        'employee_documents' => ['employee_id'],
        'employee_compensation_assignments' => ['employment_record_id', 'salary_structure_id'],
        'employee_statutory_identifiers' => ['employment_record_id'],
        'employee_tax_profile' => ['employment_record_id'],
        'employee_pf_status' => ['employment_record_id'],
        'employee_esi_coverage' => ['employment_record_id'],
        // Academic (year-measured) and LMS
        'curriculum_deliveries' => ['academic_year_id', 'campus_id', 'grade_level_id', 'section_id', 'subject_offering_id', 'syllabus_unit_id'],
        'attendance_sessions' => ['academic_year_id', 'campus_id', 'grade_level_id', 'period_id', 'section_id', 'subject_offering_id', 'teacher_id', 'timetable_entry_id'],
        'timetable_entries' => ['academic_year_id', 'campus_id', 'grade_level_id', 'period_id', 'room_id', 'section_id', 'subject_offering_id', 'teacher_id'],
        'learning_content' => ['subject_offering_id', 'owner_employee_id'],
        'assignments' => ['subject_offering_id', 'owner_employee_id'],
        'learning_content_section_audiences' => ['academic_year_id', 'campus_id', 'grade_level_id', 'learning_content_id', 'section_id', 'subject_offering_id'],
        'assignment_section_audiences' => ['academic_year_id', 'assignment_id', 'campus_id', 'grade_level_id', 'section_id', 'subject_offering_id'],
        'subject_offerings' => ['academic_year_id', 'campus_id', 'elective_group_id', 'grade_level_id', 'subject_id'],
        'teaching_assignments' => ['ends_on', 'ended_at', 'academic_year_id', 'campus_id', 'employee_id', 'grade_level_id', 'section_id', 'subject_offering_id'],
        // Operational residuals
        'transport_route_assignments' => ['ends_on', 'status', 'driver_employee_id', 'route_id', 'vehicle_id'],
        'visitors' => [],
        'visitor_visits' => ['visitor_id', 'status', 'checked_out_at', 'campus_id', 'host_employee_id'],
        'automation_executions' => ['status', 'completed_at', 'rule_instance_id', 'subject_id'],
        'identity_account_invitations' => ['status', 'accepted_at', 'revoked_at', 'expires_at', 'guardian_id', 'student_id'],
        // Communications
        'communication_deliveries' => ['status', 'delivered_at', 'read_at', 'failed_at', 'recipient_id'],
        'communication_messages' => ['thread_id', 'created_at', 'announcement_id', 'reply_to_message_id'],
        'communication_announcements' => ['status', 'published_at', 'cancelled_at', 'campus_id', 'message_id', 'source_template_id'],
        'communication_threads' => ['last_activity_at', 'campus_id'],
        'communication_approval_requests' => ['status', 'decided_at', 'requested_at', 'announcement_id'],
        'communication_delivery_policy_decisions' => ['created_at', 'message_id', 'recipient_guardian_id', 'recipient_student_id'],
        // E21.2A housekeeping
        'email_messages' => ['status', 'finished_at', 'source_id'],
        'email_provider_references' => ['email_message_id'],
        'email_events' => ['result', 'received_at', 'email_message_id'],
        'domain_event_outbox' => ['status', 'processed_at', 'campus_id'],
        'event_consumer_receipts' => ['event_id'],
        'webhook_deliveries' => ['status', 'updated_at', 'webhook_endpoint_id', 'event_id'],
        // Audit, authority history, suppressions, erasure
        'school_audit_events' => ['occurred_at', 'elevation_id', 'subject_id'],
        'platform_audit_events' => ['occurred_at', 'subject_id'],
        'membership_role_assignments' => ['revoked_at', 'school_membership_id'],
        'group_role_assignments' => ['revoked_at', 'school_group_id'],
        'platform_role_assignments' => ['revoked_at'],
        'school_elevations' => ['status', 'ended_at', 'expires_at', 'group_role_assignment_id', 'school_group_id'],
        'api_client_credentials' => ['revoked_at', 'expires_at', 'api_client_id'],
        'email_suppressions' => ['released_at', 'source_event_id', 'source_email_message_id'],
        'erasure_cases' => ['status', 'subject_id'],
        // Payroll evidence and runs
        'payroll_runs' => ['status', 'posted_at', 'corrects_payroll_run_id', 'payroll_period_id'],
        'payroll_run_postings' => ['created_at', 'currency', 'journal_entry_id', 'payroll_run_id', 'reversal_of_payroll_run_posting_id'],
        'payroll_statutory_run_postings' => ['created_at', 'currency', 'journal_entry_id', 'payroll_run_id', 'reversal_of_posting_id'],
        'payroll_run_results' => ['employee_id', 'employment_record_id', 'payroll_run_id'],
        'payroll_adjustments' => ['employment_record_id', 'payroll_run_id', 'salary_component_id'],
        'payroll_lwf_annual_charges' => ['employment_record_id', 'payroll_run_result_id'],
        // HRX Leave and Staff Attendance evidence
        'leave_ledger_entries' => ['created_at', 'allocation_run_id', 'employment_record_id', 'leave_policy_assignment_id', 'leave_request_id', 'leave_type_id', 'leave_year_id', 'reverses_entry_id', 'tracks_balance', 'year_close_id', 'year_close_reconciliation_id', 'source_id'],
        'leave_requests' => ['created_at', 'updated_at', 'employee_id', 'employment_record_id', 'leave_type_id'],
        'leave_request_days' => ['created_at', 'leave_policy_assignment_id', 'leave_policy_id', 'leave_request_id', 'leave_year_id'],
        'leave_decisions' => ['created_at', 'decider_employee_id', 'leave_request_id', 'requester_employee_id'],
        'leave_year_close_items' => ['created_at', 'employment_record_id', 'leave_policy_id', 'leave_type_id', 'year_close_id'],
        'leave_year_close_reconciliations' => ['created_at', 'leave_request_id', 'reversal_entry_id', 'year_close_item_id'],
        'leave_policy_assignments' => ['created_at', 'updated_at', 'ended_at', 'employment_record_id', 'leave_policy_id', 'leave_type_id'],
        'staff_attendance_records' => ['created_at', 'updated_at', 'employee_id', 'employment_record_id'],
        'staff_attendance_corrections' => ['created_at', 'employment_record_id', 'staff_attendance_record_id'],
        // Finance (D8)
        'financial_periods' => ['status', 'closed_at'],
        'charges' => ['academic_year_id', 'cancellation_journal_entry_id', 'currency', 'journal_entry_id', 'receivable_ledger_account_id', 'revenue_ledger_account_id', 'student_id'],
        'payments' => ['currency', 'journal_entry_id', 'provider_event_id', 'settlement_ledger_account_id'],
        'payment_allocations' => ['charge_id', 'payment_id'],
        'payment_receipts' => ['payment_id', 'series_key'],
        'payment_provider_events' => [],
        'journal_entries' => ['financial_period_id', 'currency', 'reversal_of_journal_entry_id'],
        'fee_adjustments' => ['cancellation_journal_entry_id', 'charge_id', 'credit_ledger_account_id', 'currency', 'debit_ledger_account_id', 'fee_assessment_id', 'fee_concession_id', 'journal_entry_id'],
        'fee_assessments' => ['academic_year_id', 'charge_id', 'fee_assessment_run_id', 'fee_head_id', 'fee_structure_id', 'fee_structure_installment_id', 'fee_structure_line_id', 'student_enrollment_id', 'student_id'],
        'fee_assessment_run_items' => ['fee_assessment_id', 'fee_assessment_run_id', 'fee_head_id', 'fee_structure_installment_id', 'fee_structure_line_id', 'student_enrollment_id', 'student_id', 'campus_id', 'grade_level_id'],
        'fee_concessions' => ['academic_year_id', 'charge_id', 'fee_head_id', 'student_id'],
        'late_fee_assessments' => ['charge_id', 'fee_late_fee_rule_id', 'late_fee_run_id', 'source_charge_id'],
        'late_fee_run_items' => ['late_fee_assessment_id', 'late_fee_run_id', 'source_charge_id', 'academic_year_id', 'fee_head_id', 'student_id'],
        'financial_period_charge_states' => ['charge_id', 'financial_period_id'],
    ];

    /** Anchored inputs no retention path deletes from (no delete guard). */
    private const INPUTS = ['subject_offerings', 'communication_approval_requests', 'financial_periods'];

    /** Deleted by the database expiry functions and not yet under the E21-RH.6 statement guard. */
    public const FUNCTION_DELETES = [
        'api_client_credentials', 'assignment_section_audiences', 'assignments', 'charges', 'communication_delivery_policy_decisions',
        'communication_domain_consent_events', 'email_suppressions', 'erasure_cases', 'fee_adjustments', 'fee_assessment_run_items',
        'fee_assessments', 'fee_concessions', 'financial_period_charge_states', 'group_role_assignments', 'journal_entries',
        'late_fee_assessments', 'late_fee_run_items', 'learning_content', 'learning_content_section_audiences', 'leave_decisions',
        'leave_ledger_entries', 'leave_policy_assignments', 'leave_request_days', 'leave_requests', 'leave_year_close_items',
        'leave_year_close_reconciliations', 'membership_role_assignments', 'payment_allocations', 'payment_provider_events',
        'payment_receipts', 'payments', 'payroll_adjustments', 'payroll_lwf_annual_charges', 'payroll_run_postings',
        'payroll_run_results', 'payroll_runs', 'payroll_statutory_run_postings', 'platform_audit_events', 'platform_role_assignments',
        'school_audit_events', 'school_elevations', 'staff_attendance_corrections', 'staff_attendance_records',
        'student_processing_authorizations', 'teaching_assignments',
    ];

    /** Bulk expiry functions: every `<clock> < p_cutoff` selection also requires `<alias>.retention_recorded_at < p_cutoff` (3 each). */
    private const BULK = [
        'retention_expire_api_client_credentials', 'retention_expire_communication_delivery_policy_decisions', 'retention_expire_erasure_cases',
        'retention_expire_group_role_assignments', 'retention_expire_membership_role_assignments', 'retention_expire_platform_audit_events',
        'retention_expire_platform_role_assignments', 'retention_expire_released_email_suppressions', 'retention_expire_school_audit_events',
        'retention_expire_school_elevations', 'retention_expire_teaching_assignments',
    ];

    /** function => [from, to]: the non-bulk anchor predicates (each exactly once). */
    private const PREDICATES = [
        'retention_expire_learning_content' => [
            ['WHERE o.school_id = p_school_id AND o.id = v_offering AND y.ends_on < p_cutoff) THEN',
                'WHERE o.school_id = p_school_id AND o.id = v_offering AND y.ends_on < p_cutoff AND o.retention_recorded_at < p_cutoff) THEN'],
            ['AND (t.ends_on IS NULL OR t.ends_on >= p_cutoff)) THEN',
                'AND (t.ends_on IS NULL OR t.ends_on >= p_cutoff OR t.retention_recorded_at >= p_cutoff)) THEN'],
        ],
        'retention_expire_assignment' => [
            ['WHERE o.school_id = p_school_id AND o.id = v_offering AND y.ends_on < p_cutoff) THEN',
                'WHERE o.school_id = p_school_id AND o.id = v_offering AND y.ends_on < p_cutoff AND o.retention_recorded_at < p_cutoff) THEN'],
            ['AND (t.ends_on IS NULL OR t.ends_on >= p_cutoff)) THEN',
                'AND (t.ends_on IS NULL OR t.ends_on >= p_cutoff OR t.retention_recorded_at >= p_cutoff)) THEN'],
        ],
        'retention_expire_finance_unit' => [
            ["OR p.closed_at > (now() AT TIME ZONE 'UTC') - interval '8 years') THEN",
                "OR p.closed_at > (now() AT TIME ZONE 'UTC') - interval '8 years'\n                   OR p.retention_recorded_at > (now() AT TIME ZONE 'UTC') - interval '8 years') THEN"],
        ],
    ];

    public function up(): void
    {
        $recordedAt = DB::selectOne("SELECT to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS t")->t;

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION retention_stamp_anchor() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_now timestamp := pg_catalog.now() AT TIME ZONE 'UTC';
                v_maintenance boolean := pg_catalog.pg_has_role(session_user, (SELECT c.relowner FROM pg_catalog.pg_class c WHERE c.oid = TG_RELID), 'MEMBER');
                v_new jsonb;
                v_old jsonb;
                v_column text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NOT (v_maintenance AND NEW.retention_recorded_at IS NOT NULL) THEN
                        NEW.retention_recorded_at := v_now;
                    END IF;
                    RETURN NEW;
                END IF;
                IF v_maintenance AND NEW.retention_recorded_at IS DISTINCT FROM OLD.retention_recorded_at THEN
                    RETURN NEW;
                END IF;
                NEW.retention_recorded_at := OLD.retention_recorded_at;
                v_new := pg_catalog.to_jsonb(NEW);
                v_old := pg_catalog.to_jsonb(OLD);
                IF TG_NARGS > 0 THEN
                    FOREACH v_column IN ARRAY TG_ARGV LOOP
                        -- A change TO a value re-records the row; clearing one (a SET NULL action, an unlink) never
                        -- makes it eligible, and setting it again is itself a change.
                        IF v_new -> v_column IS DISTINCT FROM v_old -> v_column AND pg_catalog.jsonb_typeof(v_new -> v_column) <> 'null' THEN
                            NEW.retention_recorded_at := v_now;
                            EXIT;
                        END IF;
                    END LOOP;
                END IF;
                RETURN NEW;
            END;
            $$;
            REVOKE ALL ON FUNCTION retention_stamp_anchor() FROM PUBLIC;

            CREATE OR REPLACE FUNCTION retention_guard_retention_delete() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                v_school uuid;
                v_cutoff timestamp;
                v_young boolean;
            BEGIN
                IF session_user <> 'school_os_retention'::name THEN
                    RETURN NULL;
                END IF;
                IF TG_ARGV[0] = 'school' THEN
                    FOR v_school IN EXECUTE 'SELECT DISTINCT school_id FROM gone' LOOP
                        PERFORM public.retention_assert_not_held(v_school);
                    END LOOP;
                ELSE
                    PERFORM public.retention_assert_not_held(NULL);
                END IF;
                -- E21-RH.7: only rows the database recorded before the unit's declared cutoff.
                IF EXISTS (SELECT 1 FROM pg_catalog.pg_attribute WHERE attrelid = TG_RELID AND attname = 'retention_recorded_at' AND NOT attisdropped) THEN
                    v_cutoff := NULLIF(pg_catalog.current_setting('app.retention_anchor_cutoff', true), '')::timestamp;
                    IF v_cutoff IS NULL THEN
                        RAISE EXCEPTION 'retention: % deleted without a declared recorded-before cutoff (retention_anchor)', TG_TABLE_NAME;
                    END IF;
                    EXECUTE 'SELECT EXISTS (SELECT 1 FROM gone WHERE retention_recorded_at >= $1)' INTO v_young USING v_cutoff;
                    IF v_young THEN
                        RAISE EXCEPTION 'retention: % row(s) recorded on or after % are not yet eligible (retention_anchor)', TG_TABLE_NAME, v_cutoff;
                    END IF;
                END IF;
                RETURN NULL;
            END;
            $$;
            SQL);

        foreach (self::ANCHORS as $table => $tracked) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT '{$recordedAt}'");
            // The database clock also fills a row written with triggers off (replica-mode maintenance); for any
            // ordinary session the trigger overrides whatever is supplied.
            DB::statement("ALTER TABLE {$table} ALTER COLUMN retention_recorded_at SET DEFAULT (now() AT TIME ZONE 'UTC')");
            $args = implode(', ', array_map(fn (string $c) => "'{$c}'", $tracked));
            DB::statement("CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION retention_stamp_anchor({$args})");
        }

        foreach (self::FUNCTION_DELETES as $table) {
            $scope = $this->hasSchoolId($table) ? 'school' : 'platform';
            DB::statement("CREATE TRIGGER {$this->guardTrigger($table)} AFTER DELETE ON {$table} REFERENCING OLD TABLE AS gone
                FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('{$scope}')");
        }

        foreach ($this->expiryFunctions() as $function) {
            $declare = $function === 'retention_expire_finance_unit'
                ? "((now() AT TIME ZONE 'UTC') - interval '8 years')::text"
                : 'p_cutoff::timestamp::text';
            $this->declareCutoff($function, $declare);
        }

        foreach (self::BULK as $function) {
            $this->anchorBulk($function);
        }
        foreach (self::PREDICATES as $function => $pairs) {
            foreach ($pairs as [$from, $to]) {
                $this->replace($function, $from, $to);
            }
        }

        // The PHP LMS check reads the offering and teaching-assignment anchors (column-level, like E21-RH.5's reads).
        foreach (['subject_offerings', 'teaching_assignments'] as $table) {
            DB::statement("GRANT SELECT (retention_recorded_at) ON {$table} TO ".self::ROLE);
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'E21-RH.7 (ADR 0066 §15): the retention recording anchors are a security fence and are deliberately irreversible. '
            .'Rolling back would let the runtime role manufacture retention eligibility again by writing a clock. '
            .'Replace a defect with a reviewed forward migration (docs/operations/DATABASE-BOOTSTRAP.md, "Retention security fences").'
        );
    }

    /** @return list<string> */
    private function expiryFunctions(): array
    {
        return array_map(fn (object $r) => $r->proname, DB::select(
            "SELECT p.proname FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname LIKE 'retention\\_expire\\_%' ORDER BY 1",
        ));
    }

    /** Each `<clock> < p_cutoff` selection (exactly three per bulk function) also requires the row's anchor. */
    private function anchorBulk(string $function): void
    {
        $definition = $this->definition($function);
        $count = 0;
        $anchored = preg_replace_callback(
            '/((?:\b(\w+)\.)?(?:LEAST|COALESCE)\(([^)]*)\)|\b(?:(\w+)\.)?(?:\w+_at|ends_on)) < p_cutoff\b/',
            function (array $m) use (&$count): string {
                $count++;
                preg_match('/\b(\w+)\.\w+/', $m[1], $alias);
                $qualifier = isset($alias[1]) ? $alias[1].'.' : '';

                return $m[0]." AND {$qualifier}retention_recorded_at < p_cutoff";
            },
            $definition,
        );
        if ($count !== 3) {
            throw new RuntimeException("{$function}: expected 3 cutoff selections, found {$count}");
        }
        DB::unprepared($anchored);
    }

    /**
     * Right after the identity (and, where it leads, hold) prologue, which
     * stays first; for the HRX functions, whose identity check is their
     * first statement's lock call, right after the body's BEGIN.
     */
    private function declareCutoff(string $function, string $declare): void
    {
        $definition = $this->definition($function);
        $identity = "\n    PERFORM public.retention_assert_retention_identity();\n";
        if (substr_count($definition, $identity) === 1) {
            $at = strpos($definition, $identity) + strlen($identity);
            if (preg_match('/\G    PERFORM public\.retention_assert_not_held\([^)]*\);\n/', $definition, $hold, 0, $at) === 1) {
                $at += strlen($hold[0]);
            }
        } elseif (($at = strpos($definition, "\nBEGIN\n")) !== false) {
            $at += strlen("\nBEGIN\n");
        } else {
            throw new RuntimeException("{$function}: no body BEGIN");
        }
        DB::unprepared(substr($definition, 0, $at)
            ."    -- E21-RH.7: this unit deletes only rows the database recorded before its cutoff (retention_anchor).\n"
            ."    PERFORM set_config('app.retention_anchor_cutoff', {$declare}, true);\n"
            .substr($definition, $at));
    }

    private function replace(string $function, string $from, string $to): void
    {
        $definition = $this->definition($function);
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException("{$function}: expected exactly one occurrence of the text to replace");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }

    private function definition(string $function): string
    {
        return DB::selectOne("SELECT pg_get_functiondef(p.oid) AS d FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?", [$function])->d;
    }

    private function guardTrigger(string $table): string
    {
        return 'trg_retention_guard_'.substr($table, 0, 40);
    }

    private function hasSchoolId(string $table): bool
    {
        return DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = ('public.'||?)::regclass AND attname = 'school_id' AND NOT attisdropped) AS x", [$table])->x;
    }
};
