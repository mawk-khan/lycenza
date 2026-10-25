<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ORG.9D-3 (ADR 0076 §75, PV1-12) -- the School Events Calendar,
     * Communications-owned, as narrow as the contract allows:
     *
     * 1. `communication_events`: one informational calendar entry. All-day
     *    (School-local dates) or timed (instants, shown in the School's
     *    timezone); one existing audience (School-wide, Staff, or the
     *    Guardians of a Grade or Section); an optional display-only
     *    location. No recurrence, participants, RSVP, Campus, category,
     *    colour or attachment. Never Attendance, closure, timetable or exam
     *    truth.
     * 2. The database enforces what needs no human identity:
     *    - the status, date-mode and audience domains;
     *    - the date shape (all-day: dates, end >= start; timed: instants,
     *      end > start) and a bounded range (2000-2100);
     *    - the audience shape (a Grade or a Section only for those types);
     *    - publication evidence: published needs published_at; cancelled
     *      needs cancelled_at; never-published states carry none;
     *    - `communication_events_published_guard` (insert + update): a row is
     *      born `draft`; the status moves only along the lifecycle graph
     *      (draft -> pending_approval -> approved|rejected; approved ->
     *      published; any non-final -> cancelled; back to draft on edit or
     *      withdrawal); `approved` and `published` need an APPROVED request
     *      row for the Event (evidence, not identity); once published, the
     *      material terms and the publication record are immutable,
     *      `published` only moves to `cancelled`, and `cancelled` is final;
     *    - the runtime role cannot DELETE an Event (published history is
     *      never hard-deleted; retention gets its own grant in ORG.9D-3R).
     * 3. `communication_approval_requests` gains an exclusive `event_id`
     *    subject (exactly one of announcement or event; one pending request
     *    per event), so Events use the SAME request table, queue and
     *    decision path as announcements -- not a second approval engine.
     */
    public function up(): void
    {
        Schema::create('communication_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->string('title', 200);
            $table->text('body');
            $table->string('location', 200)->nullable();
            $table->string('date_mode'); // all_day|timed
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->string('audience_type'); // school_wide|staff|grade|section
            $table->uuid('grade_level_id')->nullable();
            $table->uuid('section_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'status']);

            $table->foreign(['grade_level_id', 'school_id'], 'cev_grade_level_school_foreign')
                ->references(['id', 'school_id'])->on('grade_levels')->restrictOnDelete();
            $table->foreign(['section_id', 'school_id'], 'cev_section_school_foreign')
                ->references(['id', 'school_id'])->on('sections')->restrictOnDelete();
        });

        foreach ([
            'cev_status_check' => "status IN ('draft', 'pending_approval', 'approved', 'rejected', 'published', 'cancelled')",
            'cev_date_mode_check' => "date_mode IN ('all_day', 'timed')",
            'cev_audience_type_check' => "audience_type IN ('school_wide', 'staff', 'grade', 'section')",
            'cev_title_check' => 'char_length(btrim(title)) BETWEEN 1 AND 200',
            'cev_body_check' => 'char_length(body) BETWEEN 1 AND 10000',
            'cev_location_check' => 'location IS NULL OR char_length(btrim(location)) BETWEEN 1 AND 200',
            'cev_date_shape_check' => "(date_mode = 'all_day' AND starts_on IS NOT NULL AND ends_on IS NOT NULL AND starts_at IS NULL AND ends_at IS NULL AND ends_on >= starts_on)"
                ." OR (date_mode = 'timed' AND starts_at IS NOT NULL AND ends_at IS NOT NULL AND starts_on IS NULL AND ends_on IS NULL AND ends_at > starts_at)",
            'cev_date_range_check' => "(starts_on IS NULL OR (starts_on >= DATE '2000-01-01' AND ends_on <= DATE '2100-12-31'))"
                ." AND (starts_at IS NULL OR (starts_at >= TIMESTAMPTZ '2000-01-01 00:00:00+00' AND ends_at <= TIMESTAMPTZ '2101-01-01 00:00:00+00'))",
            'cev_audience_shape_check' => "(audience_type IN ('school_wide', 'staff') AND grade_level_id IS NULL AND section_id IS NULL)"
                ." OR (audience_type = 'grade' AND grade_level_id IS NOT NULL AND section_id IS NULL)"
                ." OR (audience_type = 'section' AND section_id IS NOT NULL AND grade_level_id IS NULL)",
            'cev_publication_check' => "(status = 'published' AND published_at IS NOT NULL AND published_by_user_id IS NOT NULL AND cancelled_at IS NULL)"
                ." OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL)"
                ." OR (status IN ('draft', 'pending_approval', 'approved', 'rejected') AND published_at IS NULL AND published_by_user_id IS NULL AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL)",
        ] as $name => $check) {
            DB::statement("ALTER TABLE communication_events ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION communication_events_published_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'communication_events: an event is created as a draft (communication_events_published_guard)';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status = 'cancelled' THEN
                    RAISE EXCEPTION 'communication_events: a cancelled event is final (communication_events_published_guard)';
                END IF;
                IF OLD.published_at IS NOT NULL THEN
                    IF NEW.status NOT IN ('published', 'cancelled')
                        OR NEW.published_at IS DISTINCT FROM OLD.published_at
                        OR NEW.published_by_user_id IS DISTINCT FROM OLD.published_by_user_id
                        OR NEW.title IS DISTINCT FROM OLD.title OR NEW.body IS DISTINCT FROM OLD.body
                        OR NEW.location IS DISTINCT FROM OLD.location OR NEW.date_mode IS DISTINCT FROM OLD.date_mode
                        OR NEW.starts_on IS DISTINCT FROM OLD.starts_on OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
                        OR NEW.starts_at IS DISTINCT FROM OLD.starts_at OR NEW.ends_at IS DISTINCT FROM OLD.ends_at
                        OR NEW.audience_type IS DISTINCT FROM OLD.audience_type
                        OR NEW.grade_level_id IS DISTINCT FROM OLD.grade_level_id OR NEW.section_id IS DISTINCT FROM OLD.section_id
                        OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id THEN
                        RAISE EXCEPTION 'communication_events: a published event''s terms and publication record are immutable; it may only be cancelled (communication_events_published_guard)';
                    END IF;
                    RETURN NEW;
                END IF;
                -- Never published: the lifecycle graph, and its evidence.
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'draft' AND NEW.status IN ('pending_approval', 'cancelled'))
                    OR (OLD.status = 'pending_approval' AND NEW.status IN ('approved', 'rejected', 'draft', 'cancelled'))
                    OR (OLD.status = 'approved' AND NEW.status IN ('published', 'draft', 'cancelled'))
                    OR (OLD.status = 'rejected' AND NEW.status IN ('draft', 'cancelled'))
                ) THEN
                    RAISE EXCEPTION 'communication_events: % -> % is not a lifecycle step (communication_events_published_guard)', OLD.status, NEW.status;
                END IF;
                IF NEW.status = 'cancelled' AND NEW.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'communication_events: a never-published event is cancelled without a publication record (communication_events_published_guard)';
                END IF;
                IF NEW.status IN ('approved', 'published') AND NEW.status <> OLD.status AND NOT EXISTS (
                    SELECT 1 FROM communication_approval_requests r
                     WHERE r.event_id = NEW.id AND r.school_id = NEW.school_id AND r.status = 'approved'
                ) THEN
                    RAISE EXCEPTION 'communication_events: % needs an approved request for this event (communication_events_published_guard)', NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER communication_events_published_guard BEFORE INSERT OR UPDATE ON communication_events
                FOR EACH ROW EXECUTE FUNCTION communication_events_published_guard();
            SQL);

        TenantRls::enable('communication_events');
        TenantRls::revokeDelete('communication_events');

        // E21-D3 retention (ADR 0076 §75.12), the RH.4/RH.7 shape (as
        // retention_expire_elective_teaching_assignments): the database-recorded
        // anchor, the retention delete guard, and two SECURITY DEFINER expiry
        // functions executable only by the retention identity, refusing under a
        // hold, deleting only rows recorded before their cutoff. The period is
        // the caller's cutoff, exactly as for announcements: the Communications
        // content and residual units (platform:communications-prune) compute it,
        // and their PHP purge of announcements has no database floor either.
        // - a PUBLISHED Event (cancelled later or not) is content: it expires
        //   3 years after the end of the ONE Academic Year containing its
        //   School-local last day (none or two: unresolved, kept);
        // - a NEVER-published cancelled Event: 1 year after its cancellation.
        // Drafts, pending, approved and rejected Events are working state.
        DB::unprepared(<<<'SQL'
            ALTER TABLE communication_events ADD COLUMN retention_recorded_at timestamp NOT NULL DEFAULT (now() AT TIME ZONE 'UTC');
            CREATE TRIGGER zzz_retention_anchor BEFORE INSERT OR UPDATE ON communication_events FOR EACH ROW
                EXECUTE FUNCTION retention_stamp_anchor('status', 'published_at', 'cancelled_at', 'ends_on', 'ends_at', 'grade_level_id', 'section_id');
            CREATE TRIGGER trg_retention_guard_communication_events AFTER DELETE ON communication_events
                REFERENCING OLD TABLE AS gone FOR EACH STATEMENT EXECUTE FUNCTION retention_guard_retention_delete('school');

            CREATE FUNCTION retention_expire_communication_events(p_school_id uuid, p_cutoff date, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                PERFORM set_config('app.retention_anchor_cutoff', p_cutoff::timestamp::text, true);
                IF NOT p_dry_run THEN
                    PERFORM public.retention_assert_not_held(p_school_id);
                END IF;
                PERFORM public.retention_assert_tenant(p_school_id);
                IF p_cutoff IS NULL THEN
                    RAISE EXCEPTION 'retention: a cutoff is required (retention_floor)';
                END IF;
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count
                      FROM public.communication_events e JOIN public.schools s ON s.id = e.school_id
                      CROSS JOIN LATERAL (SELECT CASE WHEN e.date_mode = 'all_day' THEN e.ends_on
                                                      ELSE (e.ends_at AT TIME ZONE COALESCE(s.timezone, 'UTC'))::date END AS last_day) d
                     WHERE e.school_id = p_school_id AND e.published_at IS NOT NULL AND e.retention_recorded_at < p_cutoff
                       AND (SELECT count(*) FROM public.academic_years y WHERE y.school_id = e.school_id AND d.last_day BETWEEN y.starts_on AND y.ends_on) = 1
                       AND (SELECT max(y.ends_on) FROM public.academic_years y WHERE y.school_id = e.school_id AND d.last_day BETWEEN y.starts_on AND y.ends_on) < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.communication_events t
                 WHERE t.id IN (SELECT e.id
                                  FROM public.communication_events e JOIN public.schools s ON s.id = e.school_id
                                  CROSS JOIN LATERAL (SELECT CASE WHEN e.date_mode = 'all_day' THEN e.ends_on
                                                                  ELSE (e.ends_at AT TIME ZONE COALESCE(s.timezone, 'UTC'))::date END AS last_day) d
                                 WHERE e.school_id = p_school_id AND e.published_at IS NOT NULL AND e.retention_recorded_at < p_cutoff
                                   AND (SELECT count(*) FROM public.academic_years y WHERE y.school_id = e.school_id AND d.last_day BETWEEN y.starts_on AND y.ends_on) = 1
                                   AND (SELECT max(y.ends_on) FROM public.academic_years y WHERE y.school_id = e.school_id AND d.last_day BETWEEN y.starts_on AND y.ends_on) < p_cutoff
                                 ORDER BY e.id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE OF e SKIP LOCKED)
                   AND t.school_id = p_school_id AND t.published_at IS NOT NULL AND t.retention_recorded_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            REVOKE ALL ON FUNCTION retention_expire_communication_events(uuid, date, integer, boolean) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION retention_expire_communication_events(uuid, date, integer, boolean) TO school_os_retention;

            CREATE FUNCTION retention_expire_cancelled_communication_events(p_school_id uuid, p_cutoff timestamp, p_limit integer, p_dry_run boolean)
                RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE v_count integer;
            BEGIN
                PERFORM public.retention_assert_retention_identity();
                PERFORM set_config('app.retention_anchor_cutoff', p_cutoff::timestamp::text, true);
                IF NOT p_dry_run THEN
                    PERFORM public.retention_assert_not_held(p_school_id);
                END IF;
                PERFORM public.retention_assert_tenant(p_school_id);
                IF p_cutoff IS NULL THEN
                    RAISE EXCEPTION 'retention: a cutoff is required (retention_floor)';
                END IF;
                IF p_dry_run THEN
                    SELECT count(*) INTO v_count FROM public.communication_events
                     WHERE school_id = p_school_id AND status = 'cancelled' AND published_at IS NULL AND cancelled_at < p_cutoff AND retention_recorded_at < p_cutoff;
                    RETURN v_count;
                END IF;
                DELETE FROM public.communication_events t
                 WHERE t.id IN (SELECT id FROM public.communication_events
                                 WHERE school_id = p_school_id AND status = 'cancelled' AND published_at IS NULL AND cancelled_at < p_cutoff AND retention_recorded_at < p_cutoff
                                 ORDER BY id LIMIT LEAST(GREATEST(COALESCE(p_limit, 1), 1), 5000) FOR UPDATE SKIP LOCKED)
                   AND t.school_id = p_school_id AND t.status = 'cancelled' AND t.published_at IS NULL AND t.cancelled_at < p_cutoff AND t.retention_recorded_at < p_cutoff;
                GET DIAGNOSTICS v_count = ROW_COUNT;
                RETURN v_count;
            END;
            $$;
            REVOKE ALL ON FUNCTION retention_expire_cancelled_communication_events(uuid, timestamp, integer, boolean) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION retention_expire_cancelled_communication_events(uuid, timestamp, integer, boolean) TO school_os_retention;
            SQL);

        Schema::table('communication_approval_requests', function (Blueprint $table) {
            $table->uuid('event_id')->nullable()->after('announcement_id');
            $table->foreign(['event_id', 'school_id'], 'car_event_school_foreign')
                ->references(['id', 'school_id'])->on('communication_events')->cascadeOnDelete();
            $table->index(['event_id', 'status']);
        });
        DB::statement('ALTER TABLE communication_approval_requests ALTER COLUMN announcement_id DROP NOT NULL');
        DB::statement('ALTER TABLE communication_approval_requests ADD CONSTRAINT communication_approval_requests_one_subject_check CHECK (num_nonnulls(announcement_id, event_id) = 1)');
        DB::statement("CREATE UNIQUE INDEX communication_approval_requests_one_pending_per_event ON communication_approval_requests (event_id) WHERE (status = 'pending')");
    }

    /**
     * Reversible only while no Event exists (rule 10): Events and their
     * approval history are School content and are never discarded to roll
     * back.
     */
    public function down(): void
    {
        if (DB::table('communication_events')->exists() || DB::table('communication_approval_requests')->whereNotNull('event_id')->exists()) {
            throw new RuntimeException('communication_events holds School Events; they are history and are never discarded to roll back.');
        }

        DB::statement('DROP INDEX communication_approval_requests_one_pending_per_event');
        DB::statement('ALTER TABLE communication_approval_requests DROP CONSTRAINT communication_approval_requests_one_subject_check');
        DB::statement('ALTER TABLE communication_approval_requests ALTER COLUMN announcement_id SET NOT NULL');
        Schema::table('communication_approval_requests', function (Blueprint $table) {
            $table->dropForeign('car_event_school_foreign');
            $table->dropIndex(['event_id', 'status']);
            $table->dropColumn('event_id');
        });

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS retention_expire_cancelled_communication_events(uuid, timestamp, integer, boolean);
            DROP FUNCTION IF EXISTS retention_expire_communication_events(uuid, date, integer, boolean);
            DROP TRIGGER IF EXISTS trg_retention_guard_communication_events ON communication_events;
            DROP TRIGGER IF EXISTS zzz_retention_anchor ON communication_events;
            SQL);
        TenantRls::disable('communication_events');
        DB::unprepared('DROP TRIGGER IF EXISTS communication_events_published_guard ON communication_events; DROP FUNCTION IF EXISTS communication_events_published_guard();');
        Schema::dropIfExists('communication_events');
    }
};
