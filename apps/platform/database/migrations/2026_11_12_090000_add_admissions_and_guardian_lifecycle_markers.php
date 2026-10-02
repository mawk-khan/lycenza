<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21.3C (E21.2G AD2/G1, project-adopted, pending legal ratification): the
 * two durable lifecycle markers the adopted periods need, and the one narrow
 * path for append-only Guardian consent evidence.
 *
 * 1. `admission_applications.terminal_at`: the canonical moment a NON-
 *    converted application ended (`rejected` or `withdrawn`). The database
 *    owns it:
 *    - entering `rejected`/`withdrawn` sets it to the transaction time, in
 *      the same UPDATE (AdmissionApplicationService::transitionTo());
 *    - once set it never changes (no lifecycle path leaves those states);
 *    - only a terminal row may carry it (CHECK);
 *    - a terminal row that predates this column starts NULL (unresolved).
 *      Only `platform:admission-decisions-backfill` may set it once, from
 *      the transition's audit event, never in the future.
 *    `updated_at`/`created_at` are never a trigger. Converted applications
 *    keep `converted_at` and follow the Student core record (E21.3B).
 *
 * 2. `guardians.no_relationship_since`: NULL while the Guardian has any
 *    Student relationship; otherwise the moment it last had none. A trigger
 *    on `student_guardian_relationships` maintains it in the writer's own
 *    transaction for EVERY path (link, unlink, conversion, D7 retention,
 *    cascades): it locks the Guardian row FOR UPDATE first, then reads the
 *    remaining relationships with a fresh snapshot, so a concurrent link and
 *    unlink of the same Guardian serialize on the Guardian row and the
 *    result is always the committed truth. A new link clears it (the clock
 *    stops); the next final unlink sets a new time (the clock restarts).
 *    The time is the statement's clock (`clock_timestamp()`), the actual
 *    moment the last relationship went.
 *    A Guardian created without a relationship starts its clock at creation
 *    (column default). A Guardian that predates this column and has no
 *    relationship starts NULL (unresolved); only
 *    `platform:guardian-markers-backfill` may set it once, from complete
 *    audit evidence. Lock order: Student (the writer's own) -> Guardian (the
 *    trigger); the Guardian purge takes only the Guardian.
 *
 * 3. `retention_expire_guardian_consent_events`: E21.2B pattern (SECURITY
 *    DEFINER, pinned `search_path`, one Guardian's rows, tenant tie, EXECUTE
 *    for the runtime role only). It re-proves the Guardian floor itself: the
 *    Guardian row locked, no relationship, `no_relationship_since` at least
 *    one calendar year old.
 *
 * Rollback removes only the mechanism and refuses once a marker carries
 * lifecycle evidence that cannot be recreated (the E21.3A2 refusal pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE admission_applications ADD COLUMN terminal_at timestamp NULL');
        DB::statement("ALTER TABLE admission_applications ADD CONSTRAINT admission_applications_terminal_at_check CHECK (terminal_at IS NULL OR status IN ('rejected', 'withdrawn'))");
        DB::statement('CREATE INDEX admission_applications_terminal_at_index ON admission_applications (school_id, terminal_at) WHERE terminal_at IS NOT NULL');

        DB::statement('ALTER TABLE guardians ADD COLUMN no_relationship_since timestamp NULL');
        // Only rows created from now on start their clock at creation; existing rows stay NULL.
        DB::statement("ALTER TABLE guardians ALTER COLUMN no_relationship_since SET DEFAULT (now() AT TIME ZONE 'UTC')");
        DB::statement('CREATE INDEX guardians_no_relationship_since_index ON guardians (school_id, no_relationship_since) WHERE no_relationship_since IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION admission_applications_guard_terminal_at() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_now timestamp := now() AT TIME ZONE 'UTC';
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status IN ('rejected', 'withdrawn') AND NEW.terminal_at IS NULL THEN
                        NEW.terminal_at := v_now;
                    ELSIF NEW.terminal_at > v_now THEN
                        RAISE EXCEPTION 'admission_applications.terminal_at cannot be in the future';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.terminal_at IS NOT NULL AND NEW.terminal_at IS DISTINCT FROM OLD.terminal_at THEN
                    RAISE EXCEPTION 'admission_applications.terminal_at is immutable once set';
                END IF;
                IF NEW.status IN ('rejected', 'withdrawn') AND OLD.status NOT IN ('rejected', 'withdrawn') THEN
                    NEW.terminal_at := v_now;
                ELSIF OLD.terminal_at IS NULL AND NEW.terminal_at IS NOT NULL
                      AND (NEW.status IS DISTINCT FROM OLD.status OR NEW.terminal_at > v_now) THEN
                    RAISE EXCEPTION 'admission_applications.terminal_at is set only by the terminal transition or a past-dated backfill';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER admission_applications_guard_terminal_at
                BEFORE INSERT OR UPDATE ON admission_applications
                FOR EACH ROW EXECUTE FUNCTION admission_applications_guard_terminal_at();

            -- Maintains guardians.no_relationship_since for one Guardian, under its row lock.
            CREATE FUNCTION guardians_sync_no_relationship_since(p_guardian_id uuid) RETURNS void
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                PERFORM 1 FROM public.guardians WHERE id = p_guardian_id FOR UPDATE;
                IF EXISTS (SELECT 1 FROM public.student_guardian_relationships WHERE guardian_id = p_guardian_id) THEN
                    UPDATE public.guardians SET no_relationship_since = NULL WHERE id = p_guardian_id AND no_relationship_since IS NOT NULL;
                ELSE
                    UPDATE public.guardians SET no_relationship_since = clock_timestamp() AT TIME ZONE 'UTC' WHERE id = p_guardian_id AND no_relationship_since IS NULL;
                END IF;
            END;
            $$;

            CREATE FUNCTION student_guardian_relationships_track_guardian() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.guardian_id IS DISTINCT FROM OLD.guardian_id THEN
                    -- Two Guardians: always in id order, so two such moves cannot deadlock.
                    PERFORM public.guardians_sync_no_relationship_since(LEAST(OLD.guardian_id, NEW.guardian_id));
                    PERFORM public.guardians_sync_no_relationship_since(GREATEST(OLD.guardian_id, NEW.guardian_id));
                ELSIF TG_OP = 'INSERT' THEN
                    PERFORM public.guardians_sync_no_relationship_since(NEW.guardian_id);
                ELSIF TG_OP = 'DELETE' THEN
                    PERFORM public.guardians_sync_no_relationship_since(OLD.guardian_id);
                END IF;
                RETURN NULL;
            END;
            $$;

            CREATE TRIGGER student_guardian_relationships_track_guardian
                AFTER INSERT OR DELETE OR UPDATE OF guardian_id ON student_guardian_relationships
                FOR EACH ROW EXECUTE FUNCTION student_guardian_relationships_track_guardian();

            -- A direct write may only start an unresolved Guardian's clock, once, in the past, with no relationship.
            CREATE FUNCTION guardians_guard_no_relationship_since() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF pg_trigger_depth() > 1 THEN
                    RETURN NEW;
                END IF;
                IF OLD.no_relationship_since IS NULL AND NEW.no_relationship_since IS NOT NULL
                   AND NEW.no_relationship_since <= now() AT TIME ZONE 'UTC'
                   AND NOT EXISTS (SELECT 1 FROM public.student_guardian_relationships WHERE guardian_id = NEW.id) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'guardians.no_relationship_since is maintained by the relationship trigger (only a past-dated backfill of an unresolved Guardian may set it)';
            END;
            $$;

            CREATE TRIGGER guardians_guard_no_relationship_since
                BEFORE UPDATE OF no_relationship_since ON guardians
                FOR EACH ROW WHEN (NEW.no_relationship_since IS DISTINCT FROM OLD.no_relationship_since)
                EXECUTE FUNCTION guardians_guard_no_relationship_since();

            CREATE FUNCTION retention_expire_guardian_consent_events(p_school_id uuid, p_guardian_id uuid, p_cutoff timestamp, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_tenant(p_school_id);
                PERFORM public.retention_assert_floor(p_cutoff, interval '1 year');
                PERFORM 1 FROM public.guardians WHERE school_id = p_school_id AND id = p_guardian_id FOR UPDATE;
                IF NOT EXISTS (SELECT 1 FROM public.guardians WHERE school_id = p_school_id AND id = p_guardian_id
                                 AND no_relationship_since IS NOT NULL AND no_relationship_since < p_cutoff)
                   OR EXISTS (SELECT 1 FROM public.student_guardian_relationships WHERE school_id = p_school_id AND guardian_id = p_guardian_id) THEN
                    RAISE EXCEPTION 'retention: the Guardian has had a relationship within the period (retention_guardian)';
                END IF;
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.communication_domain_consent_events WHERE school_id = p_school_id AND guardian_id = p_guardian_id;
                    RETURN v_count;
                END IF;
                DELETE FROM public.communication_domain_consent_events WHERE school_id = p_school_id AND guardian_id = p_guardian_id;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            SQL);

        // The relationship trigger runs as the writer (the runtime role).
        foreach (['guardians_sync_no_relationship_since(uuid)', 'student_guardian_relationships_track_guardian()', 'guardians_guard_no_relationship_since()', 'admission_applications_guard_terminal_at()'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO school_os_app");
        }
        DB::statement('REVOKE ALL ON FUNCTION retention_expire_guardian_consent_events(uuid, uuid, timestamp, boolean) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION retention_expire_guardian_consent_events(uuid, uuid, timestamp, boolean) TO school_os_app');
    }

    public function down(): void
    {
        if (DB::table('admission_applications')->whereNotNull('terminal_at')->exists() || DB::table('guardians')->whereNotNull('no_relationship_since')->exists()) {
            throw new RuntimeException('Refusing to roll back: Admissions decision times or Guardian no-relationship markers now exist; they are lifecycle evidence that cannot be recreated (E21.3C).');
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS retention_expire_guardian_consent_events(uuid, uuid, timestamp, boolean);
            DROP TRIGGER IF EXISTS guardians_guard_no_relationship_since ON guardians;
            DROP FUNCTION IF EXISTS guardians_guard_no_relationship_since();
            DROP TRIGGER IF EXISTS student_guardian_relationships_track_guardian ON student_guardian_relationships;
            DROP FUNCTION IF EXISTS student_guardian_relationships_track_guardian();
            DROP FUNCTION IF EXISTS guardians_sync_no_relationship_since(uuid);
            DROP TRIGGER IF EXISTS admission_applications_guard_terminal_at ON admission_applications;
            DROP FUNCTION IF EXISTS admission_applications_guard_terminal_at();
            SQL);
        DB::statement('DROP INDEX IF EXISTS guardians_no_relationship_since_index');
        DB::statement('ALTER TABLE guardians DROP COLUMN IF EXISTS no_relationship_since');
        DB::statement('DROP INDEX IF EXISTS admission_applications_terminal_at_index');
        DB::statement('ALTER TABLE admission_applications DROP CONSTRAINT IF EXISTS admission_applications_terminal_at_check');
        DB::statement('ALTER TABLE admission_applications DROP COLUMN IF EXISTS terminal_at');
    }
};
