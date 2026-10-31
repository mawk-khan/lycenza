<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIS 11.7 (OD-SIS-4: House is the approved Student Group pilot) -- the
 * Student Communities foundation, with HOUSE as the only enabled type.
 *
 * - `student_groups`: School-owned communities. `group_type` is a CLOSED
 *   set ('house' only; a new type is a reviewed migration + code), never a
 *   free string. Houses are School-wide (no Campus): Students of several
 *   Campuses share a House, and a Campus transfer never changes it.
 *   Deactivated, never deleted (no runtime DELETE).
 * - `student_group_memberships`: effective-dated, School-local calendar
 *   dates, INCLUSIVE `ends_on` (the placement convention; a same-day
 *   replacement records the empty interval `ends_on = starts_on - 1`,
 *   ADR 0078). At most one CURRENT membership per Student per type
 *   (partial unique index) and no overlapping intervals (trigger -- the
 *   repository uses no EXCLUDE/btree_gist). Identity immutable; an ended
 *   row is final; every change advances `version`. Records go with the
 *   Student core record (cascade).
 * - `student_group_responsibilities`: descriptive participation (House
 *   Captain, Vice Captain, Prefect) -- NEVER a permission. Bound to one
 *   membership of the same Student, House and School; Captain and Vice
 *   Captain are unique per House while current, a Prefect is not.
 */
return new class extends Migration
{
    private const TYPES = "'house'";

    private const MEMBERSHIP_REASONS = "'assignment', 'reassignment', 'correction', 'left_school', 'school_decision'";

    private const RESPONSIBILITIES = "'house_captain', 'vice_captain', 'prefect'";

    public function up(): void
    {
        Schema::create('student_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('group_type', 20);
            $table->string('name', 80);
            $table->string('code', 20);
            $table->string('description', 255)->nullable();
            $table->string('status', 10)->default('active');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'group_type', 'school_id']);
            $table->unique(['school_id', 'group_type', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX student_groups_school_type_name_unique ON student_groups (school_id, group_type, lower(name))');
        DB::statement('ALTER TABLE student_groups ADD CONSTRAINT student_groups_type_check CHECK (group_type IN ('.self::TYPES.'))');
        DB::statement("ALTER TABLE student_groups ADD CONSTRAINT student_groups_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement("ALTER TABLE student_groups ADD CONSTRAINT student_groups_name_check CHECK (name = btrim(name) AND char_length(name) BETWEEN 1 AND 80 AND name !~ '[[:cntrl:]]')");
        DB::statement("ALTER TABLE student_groups ADD CONSTRAINT student_groups_code_check CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,19}$')");
        DB::statement('ALTER TABLE student_groups ADD CONSTRAINT student_groups_version_check CHECK (version >= 1)');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_groups_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.group_type IS DISTINCT FROM OLD.group_type OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'a Student group''s School and type are immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'a Student group change advances its version by one' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_student_groups_guard BEFORE UPDATE ON student_groups
                FOR EACH ROW EXECUTE FUNCTION student_groups_guard();
            SQL);
        TenantRls::enable('student_groups');
        DB::statement('REVOKE DELETE ON student_groups FROM school_os_app');

        Schema::create('student_group_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('student_group_id');
            $table->string('group_type', 20);
            $table->uuid('student_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('start_reason', 30);
            $table->string('end_reason', 30)->nullable();
            $table->foreignUuid('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'student_id', 'student_group_id', 'school_id']);
            $table->index(['school_id', 'student_group_id']);
            $table->index(['school_id', 'student_id']);
            $table->foreign(['student_group_id', 'group_type', 'school_id'])->references(['id', 'group_type', 'school_id'])->on('student_groups')->restrictOnDelete();
            $table->foreign(['student_id', 'school_id'])->references(['id', 'school_id'])->on('students')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX student_group_memberships_one_current ON student_group_memberships (school_id, student_id, group_type) WHERE ends_on IS NULL');
        DB::statement('ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_type_check CHECK (group_type IN ('.self::TYPES.'))');
        DB::statement('ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_reason_check CHECK (start_reason IN ('.self::MEMBERSHIP_REASONS.') AND (end_reason IS NULL OR end_reason IN ('.self::MEMBERSHIP_REASONS.')))');
        DB::statement('ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_interval_check CHECK (ends_on IS NULL OR ends_on >= starts_on - 1)');
        DB::statement('ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_end_shape_check CHECK (
            (ends_on IS NULL AND end_reason IS NULL AND ended_by_user_id IS NULL)
            OR (ends_on IS NOT NULL AND end_reason IS NOT NULL AND ended_by_user_id IS NOT NULL))');
        DB::statement('ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_version_check CHECK (version >= 1)');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_group_memberships_no_overlap() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Non-empty intervals of one Student and type never overlap (inclusive dates).
                IF (NEW.ends_on IS NULL OR NEW.ends_on >= NEW.starts_on) AND EXISTS (
                    SELECT 1 FROM student_group_memberships m
                    WHERE m.school_id = NEW.school_id AND m.student_id = NEW.student_id AND m.group_type = NEW.group_type AND m.id <> NEW.id
                      AND (m.ends_on IS NULL OR m.ends_on >= m.starts_on)
                      AND m.starts_on <= COALESCE(NEW.ends_on, 'infinity'::date) AND NEW.starts_on <= COALESCE(m.ends_on, 'infinity'::date)) THEN
                    RAISE EXCEPTION 'a Student has at most one membership of a group type on any day' USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_student_group_memberships_no_overlap BEFORE INSERT OR UPDATE ON student_group_memberships
                FOR EACH ROW EXECUTE FUNCTION student_group_memberships_no_overlap();

            CREATE FUNCTION student_group_memberships_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.student_group_id IS DISTINCT FROM OLD.student_group_id
                   OR NEW.group_type IS DISTINCT FROM OLD.group_type OR NEW.student_id IS DISTINCT FROM OLD.student_id
                   OR NEW.starts_on IS DISTINCT FROM OLD.starts_on OR NEW.start_reason IS DISTINCT FROM OLD.start_reason
                   OR NEW.started_by_user_id IS DISTINCT FROM OLD.started_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'a membership''s identity and start are immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.ends_on IS NOT NULL THEN
                    RAISE EXCEPTION 'an ended membership is final history' USING ERRCODE = 'check_violation';
                END IF;
                -- Its responsibilities end first (the service does so in the same transaction).
                IF NEW.ends_on IS NOT NULL AND EXISTS (SELECT 1 FROM student_group_responsibilities r WHERE r.student_group_membership_id = OLD.id AND r.ends_on IS NULL) THEN
                    RAISE EXCEPTION 'a membership with a current responsibility cannot end' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'a membership change advances its version by one' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_student_group_memberships_guard BEFORE UPDATE ON student_group_memberships
                FOR EACH ROW EXECUTE FUNCTION student_group_memberships_guard();
            SQL);
        TenantRls::enable('student_group_memberships');
        DB::statement('REVOKE DELETE ON student_group_memberships FROM school_os_app');

        Schema::create('student_group_responsibilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->restrictOnDelete();
            $table->uuid('student_group_id');
            $table->uuid('student_group_membership_id');
            $table->uuid('student_id');
            $table->string('responsibility', 30);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignUuid('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['school_id', 'student_group_id']);
            $table->index(['school_id', 'student_id']);
            $table->foreign(['student_group_membership_id', 'student_id', 'student_group_id', 'school_id'], 'student_group_responsibilities_membership_fk')
                ->references(['id', 'student_id', 'student_group_id', 'school_id'])->on('student_group_memberships')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE student_group_responsibilities ADD CONSTRAINT student_group_responsibilities_kind_check CHECK (responsibility IN ('.self::RESPONSIBILITIES.'))');
        DB::statement('ALTER TABLE student_group_responsibilities ADD CONSTRAINT student_group_responsibilities_interval_check CHECK (ends_on IS NULL OR ends_on >= starts_on - 1)');
        DB::statement('ALTER TABLE student_group_responsibilities ADD CONSTRAINT student_group_responsibilities_end_shape_check CHECK ((ends_on IS NULL) = (ended_by_user_id IS NULL))');
        DB::statement('ALTER TABLE student_group_responsibilities ADD CONSTRAINT student_group_responsibilities_version_check CHECK (version >= 1)');
        // Captain and Vice Captain: one current holder per House. A Prefect is not unique.
        DB::statement("CREATE UNIQUE INDEX student_group_responsibilities_unique_holder ON student_group_responsibilities (school_id, student_group_id, responsibility)
            WHERE ends_on IS NULL AND responsibility IN ('house_captain', 'vice_captain')");
        // A Student holds a responsibility at most once at a time.
        DB::statement('CREATE UNIQUE INDEX student_group_responsibilities_one_per_member ON student_group_responsibilities (school_id, student_group_membership_id, responsibility) WHERE ends_on IS NULL');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION student_group_responsibilities_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    -- A responsibility starts only on a CURRENT membership.
                    IF NOT EXISTS (SELECT 1 FROM student_group_memberships m WHERE m.id = NEW.student_group_membership_id AND m.ends_on IS NULL) THEN
                        RAISE EXCEPTION 'a responsibility needs a current membership' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.student_group_id IS DISTINCT FROM OLD.student_group_id
                   OR NEW.student_group_membership_id IS DISTINCT FROM OLD.student_group_membership_id OR NEW.student_id IS DISTINCT FROM OLD.student_id
                   OR NEW.responsibility IS DISTINCT FROM OLD.responsibility OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
                   OR NEW.assigned_by_user_id IS DISTINCT FROM OLD.assigned_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'a responsibility''s identity and start are immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.ends_on IS NOT NULL THEN
                    RAISE EXCEPTION 'an ended responsibility is final history' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'a responsibility change advances its version by one' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_student_group_responsibilities_guard BEFORE INSERT OR UPDATE ON student_group_responsibilities
                FOR EACH ROW EXECUTE FUNCTION student_group_responsibilities_guard();
            SQL);
        TenantRls::enable('student_group_responsibilities');
        DB::statement('REVOKE DELETE ON student_group_responsibilities FROM school_os_app');
    }

    public function down(): void
    {
        TenantRls::disable('student_group_responsibilities');
        Schema::dropIfExists('student_group_responsibilities');
        DB::unprepared('DROP FUNCTION IF EXISTS student_group_responsibilities_guard()');
        TenantRls::disable('student_group_memberships');
        Schema::dropIfExists('student_group_memberships');
        DB::unprepared('DROP FUNCTION IF EXISTS student_group_memberships_guard(); DROP FUNCTION IF EXISTS student_group_memberships_no_overlap();');
        TenantRls::disable('student_groups');
        Schema::dropIfExists('student_groups');
        DB::unprepared('DROP FUNCTION IF EXISTS student_groups_guard()');
    }
};
