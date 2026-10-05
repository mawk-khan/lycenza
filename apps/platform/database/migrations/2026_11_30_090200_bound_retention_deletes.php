<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.6 (ADR 0066 §14): the database hold boundary for the retention
 * paths that delete from PHP, and the end of runtime DELETE privileges that
 * exist only for retention (ADR 0066 §3).
 *
 * The PHP retention units (Student, Guardian, Employee, academic and
 * operational residuals, Communications, Admissions, portal invitations,
 * email, outbox, webhook deliveries, failed jobs) run WHOLE as the dedicated
 * retention identity on `pgsql_retention` (RetentionExpiry::privileged()).
 * For the tables they delete from (DELETES):
 * - `school_os_retention` gets DELETE and table-level SELECT. Its units read
 *   whole rows of what they may delete (production readers, `SELECT *`);
 *   narrowing the columns of a table the role may delete protects nothing.
 *   Never INSERT or UPDATE.
 * - `retention_guard_retention_delete()` (AFTER DELETE, FOR EACH STATEMENT,
 *   over the deleted rows): when the session user is the retention identity,
 *   PostgreSQL refuses (`retention_hold`) unless no platform hold and no
 *   hold of each affected School is active -- whatever PHP decided. Deletes
 *   by any other role (ordinary product actions where the runtime role keeps
 *   DELETE) are not retention and pass unchanged.
 * - Runtime DELETE is revoked wherever only retention used it
 *   (RUNTIME_REVOKES; also `learning_content`, `assignments`, whose
 *   retention is in the database since E21-RH.5). The runtime role keeps
 *   DELETE where the product deletes (RUNTIME_KEEPS).
 *
 * Narrow definers (retention identity only):
 * - `retention_lock_rows(table, ids, skip_locked)`: FOR UPDATE on rows of a
 *   closed table list -- the unit-root and batch locks the PHP units take
 *   (row locks need UPDATE privilege, which the role never gets). It locks;
 *   it never changes a row.
 * - `retention_first_reference(parent, school, ids, handled)`: the
 *   read-only dependency probe of ReferencingRows::first() (which table, if
 *   any, still references the unit) -- so the role needs no read of every
 *   referencing table. It returns a table name, never a row.
 * - `retention_unlink_announcement_message(school, announcement)`: breaks
 *   the announcement <-> message RESTRICT cycle for a published
 *   announcement before its purge (the one UPDATE the Communications purge
 *   needs), hold- and tenant-checked.
 *
 * User erasure (identity minimization) stays on the runtime role (it touches
 * product credential tables) and gets its own database hold boundary:
 * `users_guard_minimization_hold` refuses setting `minimized_at` while the
 * platform, or any School the User is a member of, is held.
 *
 * Rollback drops the guards and helpers and revokes the retention grants
 * (restoring the earlier slices' column-level SELECTs exactly). It
 * deliberately does NOT re-grant runtime DELETE (an unsafe privilege is
 * never restored): retention then fails closed until migrated again.
 */
return new class extends Migration
{
    private const ROLE = 'school_os_retention';

    /** Tables the retention units delete from directly. */
    private const DELETES = [
        // Students (platform:student-retention-prune, erasure)
        'students', 'student_enrollments', 'student_subject_enrollments', 'student_guardian_relationships', 'student_guardian_account_links',
        'attendance_records', 'enrollment_rollover_items', 'library_loans', 'transport_student_assignments', 'hostel_residency_assignments',
        'admission_applications', 'applicants', 'communication_domain_preferences', 'documents',
        // Guardians
        'guardians', 'guardian_contacts',
        // Employees (HR and Payroll per-employment configuration)
        'employees', 'employment_records', 'employee_assignments', 'employee_addresses', 'employee_emergency_contacts', 'employee_notes',
        'employee_qualifications', 'employee_experience_records', 'employee_certifications', 'employee_personal_details', 'employee_documents',
        'employee_compensation_assignments', 'employee_statutory_identifiers', 'employee_tax_profile', 'employee_pf_status', 'employee_esi_coverage',
        // Academic and operational residuals
        'curriculum_deliveries', 'attendance_sessions', 'timetable_entries', 'transport_route_assignments', 'automation_executions',
        'visitors', 'visitor_visits', 'identity_account_invitations',
        // Communications
        'communication_deliveries', 'communication_messages', 'communication_announcements', 'communication_threads',
        // E21.2A records under the hold seam
        'email_messages', 'email_provider_references', 'email_events', 'domain_event_outbox', 'event_consumer_receipts', 'webhook_deliveries', 'failed_jobs',
    ];

    /** Where the product (or the framework) deletes too: the runtime role keeps DELETE. */
    private const RUNTIME_KEEPS = [
        'student_guardian_relationships', // a user unlinks a Guardian (StudentGuardianRelationshipService::unlink)
        'employee_addresses', 'employee_emergency_contacts', 'employee_notes', 'employee_qualifications',
        'employee_experience_records', 'employee_certifications', // HR removes a profile entry
        'failed_jobs', // queue:retry / queue:forget
    ];

    /** Retention-only DELETE grants of the runtime role outside DELETES (their retention runs in the database). */
    private const RUNTIME_REVOKES_EXTRA = ['learning_content', 'assignments', 'student_processing_authorizations'];

    /** Read-only tables the moved units read (SELECT only). */
    private const READS = [
        'schools', 'academic_years', 'subject_offerings', 'sections', 'school_memberships', 'school_settings',
        // Student core evidence the units check before their functions remove it
        'student_processing_authorizations', 'communication_domain_consent_events',
        // Finance (D8): the plan, the dual-read verification and the unit's before/after readings
        'financial_periods', 'financial_period_account_balances', 'financial_period_expiries', 'financial_period_charge_states',
        'journal_entries', 'journal_lines', 'ledger_accounts', 'charges', 'payments', 'payment_allocations', 'payment_receipt_counters',
        'late_fee_assessments', 'payroll_run_postings', 'payroll_statutory_run_postings',
        'fee_adjustments', 'fee_assessments', 'fee_heads', 'fee_structure_installments',
        // Communications residuals: a never-sent announcement still awaiting approval keeps it; its attachments' bytes
        'communication_approval_requests', 'communication_attachments',
        // The email prune keeps an event a current suppression still rests on (it reads only whether one exists)
        'email_suppressions',
    ];

    public function up(): void
    {
        $lockable = array_values(array_filter(self::DELETES, fn (string $t) => $this->hasUuidId($t)));
        $list = "'".implode("', '", $lockable)."'";

        DB::unprepared(<<<SQL
            CREATE FUNCTION retention_guard_retention_delete() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_school uuid;
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
                RETURN NULL;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION retention_guard_retention_delete() FROM PUBLIC;

            CREATE FUNCTION retention_lock_rows(p_table text, p_ids uuid[], p_skip_locked boolean) RETURNS SETOF uuid
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                IF p_table IS NULL OR NOT (p_table = ANY (ARRAY[{$list}])) THEN
                    RAISE EXCEPTION 'retention: not a lockable retention table (retention_lock)';
                END IF;
                RETURN QUERY EXECUTE format('SELECT id FROM public.%I WHERE id = ANY (\$1) ORDER BY id FOR UPDATE%s', p_table,
                    CASE WHEN p_skip_locked THEN ' SKIP LOCKED' ELSE '' END) USING p_ids;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION retention_lock_rows(text, uuid[], boolean) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION retention_lock_rows(text, uuid[], boolean) TO school_os_retention;

            CREATE FUNCTION retention_unlink_announcement_message(p_school_id uuid, p_announcement_id uuid) RETURNS integer
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_count integer;
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                PERFORM public.retention_assert_not_held(p_school_id);
                PERFORM public.retention_assert_tenant(p_school_id);
                UPDATE public.communication_announcements SET message_id = NULL
                 WHERE school_id = p_school_id AND id = p_announcement_id AND published_at IS NOT NULL AND message_id IS NOT NULL;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION retention_unlink_announcement_message(uuid, uuid) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION retention_unlink_announcement_message(uuid, uuid) TO school_os_retention;

            -- The dependency probe of ReferencingRows::first(), read-only: the first table (in table, column order)
            -- holding a foreign key to p_parent.id that still references one of p_ids (School-scoped references
            -- of p_school_id only), skipping p_handled. It reveals a table name, never a row.
            CREATE FUNCTION retention_first_reference(p_parent text, p_school_id uuid, p_ids uuid[], p_handled text[]) RETURNS text
                LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_ref record;
                v_hit boolean;
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                IF p_ids IS NULL OR cardinality(p_ids) = 0 THEN
                    RETURN NULL;
                END IF;
                FOR v_ref IN
                    SELECT DISTINCT cl.relname::text AS tbl, a.attname AS col,
                           EXISTS (SELECT 1 FROM pg_attribute s WHERE s.attrelid = c.conrelid AND s.attname = 'school_id' AND NOT s.attisdropped) AS tenant
                      FROM pg_constraint c
                      JOIN pg_class cl ON cl.oid = c.conrelid
                      CROSS JOIN LATERAL unnest(c.conkey, c.confkey) AS k(src, dst)
                      JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.src
                      JOIN pg_attribute fa ON fa.attrelid = c.confrelid AND fa.attnum = k.dst
                     WHERE c.contype = 'f' AND c.confrelid = ('public.' || p_parent)::regclass AND fa.attname = 'id'
                     ORDER BY 1, 2
                LOOP
                    CONTINUE WHEN v_ref.tbl = ANY (coalesce(p_handled, '{}'));
                    IF v_ref.tenant THEN
                        EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I WHERE %I = ANY ($1) AND school_id = $2)', v_ref.tbl, v_ref.col) INTO v_hit USING p_ids, p_school_id;
                    ELSE
                        EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I WHERE %I = ANY ($1))', v_ref.tbl, v_ref.col) INTO v_hit USING p_ids;
                    END IF;
                    IF v_hit THEN
                        RETURN v_ref.tbl;
                    END IF;
                END LOOP;
                RETURN NULL;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION retention_first_reference(text, uuid, uuid[], text[]) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION retention_first_reference(text, uuid, uuid[], text[]) TO school_os_retention;

            -- A retention delete of a Guardian relationship fires the Guardian lifecycle trigger, which recomputes
            -- the marker from facts (now when no relationship remains; cleared otherwise -- never backdated). It
            -- runs as the owner so the retention identity needs no UPDATE on guardians.
            ALTER FUNCTION guardians_sync_no_relationship_since(uuid) SECURITY DEFINER;
            GRANT EXECUTE ON FUNCTION guardians_sync_no_relationship_since(uuid) TO school_os_retention;
            -- Likewise the operational backlog projections: a retention delete of a delivery, email, webhook delivery
            -- or automation execution removes its projection row through the owner (fixed trigger logic).
            ALTER FUNCTION sync_operational_work_backlog() SECURITY DEFINER;
            ALTER FUNCTION sync_operational_work_backlog() SET search_path = pg_catalog, public, pg_temp;
            ALTER FUNCTION sync_email_work_backlog() SECURITY DEFINER;
            ALTER FUNCTION sync_email_work_backlog() SET search_path = pg_catalog, public, pg_temp;

            -- User erasure stays on the runtime role: its database hold boundary is the minimization itself.
            CREATE FUNCTION users_guard_minimization_hold() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS \$\$
            DECLARE
                v_school uuid;
            BEGIN
                IF OLD.minimized_at IS NULL AND NEW.minimized_at IS NOT NULL THEN
                    PERFORM public.retention_assert_not_held(NULL);
                    FOR v_school IN SELECT DISTINCT m.school_id FROM public.school_memberships m WHERE m.user_id = NEW.id LOOP
                        PERFORM public.retention_assert_not_held(v_school);
                    END LOOP;
                END IF;
                RETURN NEW;
            END;
            \$\$;
            REVOKE ALL ON FUNCTION users_guard_minimization_hold() FROM PUBLIC;
            CREATE TRIGGER users_guard_minimization_hold BEFORE UPDATE OF minimized_at ON users
                FOR EACH ROW EXECUTE FUNCTION users_guard_minimization_hold();
            SQL);

        foreach (self::DELETES as $table) {
            $scope = $this->hasSchoolId($table) ? 'school' : 'platform';
            DB::statement("CREATE TRIGGER {$this->trigger($table)} AFTER DELETE ON {$table} REFERENCING OLD TABLE AS gone
                FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('{$scope}')");
            DB::statement("GRANT SELECT, DELETE ON {$table} TO ".self::ROLE);
        }
        foreach (self::READS as $table) {
            DB::statement("GRANT SELECT ON {$table} TO ".self::ROLE);
        }
        foreach ($this->runtimeRevokes() as $table) {
            DB::statement("REVOKE DELETE ON {$table} FROM school_os_app");
        }
    }

    public function down(): void
    {
        // A table-level REVOKE SELECT also strips column-level SELECTs: the
        // earlier slices' column grants (E21-RH.2/RH.5, never granted here)
        // are captured first and restored exactly.
        $columns = [];
        foreach ([...self::READS, ...self::DELETES] as $table) {
            $columns[$table] = $this->retentionColumnSelects($table);
        }
        foreach (self::READS as $table) {
            DB::statement("REVOKE SELECT ON {$table} FROM ".self::ROLE);
        }
        foreach (self::DELETES as $table) {
            DB::statement("REVOKE SELECT, DELETE ON {$table} FROM ".self::ROLE);
            DB::statement("DROP TRIGGER {$this->trigger($table)} ON {$table}");
        }
        foreach (array_filter($columns) as $table => $list) {
            DB::statement('GRANT SELECT ('.implode(', ', $list).") ON {$table} TO ".self::ROLE);
        }
        // Deliberately no runtime DELETE re-grant (see the class docblock).
        DB::unprepared(<<<'SQL'
            ALTER FUNCTION sync_email_work_backlog() RESET search_path;
            ALTER FUNCTION sync_email_work_backlog() SECURITY INVOKER;
            ALTER FUNCTION sync_operational_work_backlog() RESET search_path;
            ALTER FUNCTION sync_operational_work_backlog() SECURITY INVOKER;
            REVOKE EXECUTE ON FUNCTION guardians_sync_no_relationship_since(uuid) FROM school_os_retention;
            ALTER FUNCTION guardians_sync_no_relationship_since(uuid) SECURITY INVOKER;
            DROP TRIGGER users_guard_minimization_hold ON users;
            DROP FUNCTION users_guard_minimization_hold();
            DROP FUNCTION retention_first_reference(text, uuid, uuid[], text[]);
            DROP FUNCTION retention_unlink_announcement_message(uuid, uuid);
            DROP FUNCTION retention_lock_rows(text, uuid[], boolean);
            DROP FUNCTION retention_guard_retention_delete();
            SQL);
    }

    /** @return list<string> */
    private function runtimeRevokes(): array
    {
        return [...array_values(array_diff(self::DELETES, self::RUNTIME_KEEPS)), ...self::RUNTIME_REVOKES_EXTRA];
    }

    /** @return list<string> the columns the retention role holds a column-level SELECT on */
    private function retentionColumnSelects(string $table): array
    {
        return array_map(fn (object $r) => '"'.$r->attname.'"', DB::select(
            "SELECT a.attname FROM pg_attribute a CROSS JOIN LATERAL aclexplode(a.attacl) x
              WHERE a.attrelid = ('public.'||?)::regclass AND a.attnum > 0 AND NOT a.attisdropped
                AND x.grantee = ?::regrole AND x.privilege_type = 'SELECT' ORDER BY a.attnum",
            [$table, self::ROLE],
        ));
    }

    private function trigger(string $table): string
    {
        return 'trg_retention_guard_'.substr($table, 0, 40);
    }

    private function hasSchoolId(string $table): bool
    {
        return DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = ('public.'||?)::regclass AND attname = 'school_id' AND NOT attisdropped) AS x", [$table])->x;
    }

    private function hasUuidId(string $table): bool
    {
        return DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = ('public.'||?)::regclass AND attname = 'id' AND atttypid = 'uuid'::regtype AND NOT attisdropped) AS x", [$table])->x;
    }
};
