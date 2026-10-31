<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.8 (OD-SIS-5A Club, OD-SIS-5B Activity): the Student Communities
 * foundation of SIS 11.7 gains exactly two more closed types. Same three
 * tables, same RLS, privileges, guards and history; only the rules that
 * genuinely differ by type change:
 *
 * - `group_type` CHECK: house, club, activity (still closed; a new type is a
 *   reviewed migration + code).
 * - Current membership: a House stays ONE per Student
 *   (`student_group_memberships_one_current`, now House-only); a Club or
 *   Activity is one current membership per Student PER GROUP
 *   (`student_group_memberships_one_current_per_group`) -- a Student may be
 *   in several Clubs and Activities at once. The no-overlap trigger follows
 *   the same split.
 * - Reasons are per type: a House keeps its five; a Club/Activity joins
 *   (`joined`) and ends with withdrew, completed, left_school,
 *   school_decision or correction.
 * - Responsibilities are per type: House Captain/Vice/Prefect on a House;
 *   President/Vice President/Secretary (unique per Club) and Member
 *   Representative (several) on a Club; none on an Activity (trigger).
 * - `activity_category`: an optional closed broad category, Activities only.
 *
 * Existing House rows are verified first and kept unchanged. down() refuses
 * while any Club or Activity row exists (it would otherwise have to delete
 * history), then restores the SIS 11.7 definitions exactly.
 */
return new class extends Migration
{
    private const HOUSE_RESPONSIBILITIES = "'house_captain', 'vice_captain', 'prefect'";

    private const CLUB_RESPONSIBILITIES = "'club_president', 'club_vice_president', 'club_secretary', 'member_representative'";

    private const CATEGORIES = "'sports', 'performing_arts', 'creative_arts', 'academic_enrichment', 'other'";

    public function up(): void
    {
        if (DB::table('student_groups')->where('group_type', '<>', 'house')->exists()
            || DB::table('student_group_memberships')->where('group_type', '<>', 'house')->exists()) {
            throw new RuntimeException('student_groups holds a non-House row before SIS 11.8: refusing to extend the type contract.');
        }

        DB::statement('ALTER TABLE student_groups DROP CONSTRAINT student_groups_type_check');
        DB::statement("ALTER TABLE student_groups ADD CONSTRAINT student_groups_type_check CHECK (group_type IN ('house', 'club', 'activity'))");
        DB::statement('ALTER TABLE student_groups ADD COLUMN activity_category varchar(30) NULL');
        DB::statement("ALTER TABLE student_groups ADD CONSTRAINT student_groups_activity_category_check CHECK (activity_category IS NULL OR (group_type = 'activity' AND activity_category IN (".self::CATEGORIES.')))');

        DB::statement('ALTER TABLE student_group_memberships DROP CONSTRAINT student_group_memberships_type_check');
        DB::statement("ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_type_check CHECK (group_type IN ('house', 'club', 'activity'))");
        DB::statement('ALTER TABLE student_group_memberships DROP CONSTRAINT student_group_memberships_reason_check');
        DB::statement("ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_reason_check CHECK (
            (group_type = 'house' AND start_reason IN ('assignment', 'reassignment', 'correction')
                AND (end_reason IS NULL OR end_reason IN ('reassignment', 'correction', 'left_school', 'school_decision')))
            OR (group_type IN ('club', 'activity') AND start_reason = 'joined'
                AND (end_reason IS NULL OR end_reason IN ('withdrew', 'completed', 'left_school', 'school_decision', 'correction'))))");

        // House: one current House per Student. Club/Activity: one current
        // membership per Student per group. Both are partial unique indexes,
        // so two racing inserts can never both commit.
        DB::statement('DROP INDEX student_group_memberships_one_current');
        DB::statement("CREATE UNIQUE INDEX student_group_memberships_one_current ON student_group_memberships (school_id, student_id, group_type) WHERE ends_on IS NULL AND group_type = 'house'");
        DB::statement('CREATE UNIQUE INDEX student_group_memberships_one_current_per_group ON student_group_memberships (school_id, student_id, student_group_id) WHERE ends_on IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION student_group_memberships_no_overlap() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Non-empty intervals never overlap (inclusive dates): per Student
                -- and type for a House, per Student and group for a Club/Activity.
                IF (NEW.ends_on IS NULL OR NEW.ends_on >= NEW.starts_on) AND EXISTS (
                    SELECT 1 FROM student_group_memberships m
                    WHERE m.school_id = NEW.school_id AND m.student_id = NEW.student_id AND m.group_type = NEW.group_type AND m.id <> NEW.id
                      AND (NEW.group_type = 'house' OR m.student_group_id = NEW.student_group_id)
                      AND (m.ends_on IS NULL OR m.ends_on >= m.starts_on)
                      AND m.starts_on <= COALESCE(NEW.ends_on, 'infinity'::date) AND NEW.starts_on <= COALESCE(m.ends_on, 'infinity'::date)) THEN
                    RAISE EXCEPTION 'a Student has at most one membership of a House, or of one Club or Activity, on any day' USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END $$;
            SQL);

        DB::statement('ALTER TABLE student_group_responsibilities DROP CONSTRAINT student_group_responsibilities_kind_check');
        DB::statement('ALTER TABLE student_group_responsibilities ADD CONSTRAINT student_group_responsibilities_kind_check CHECK (responsibility IN ('.self::HOUSE_RESPONSIBILITIES.', '.self::CLUB_RESPONSIBILITIES.'))');
        DB::statement('DROP INDEX student_group_responsibilities_unique_holder');
        DB::statement("CREATE UNIQUE INDEX student_group_responsibilities_unique_holder ON student_group_responsibilities (school_id, student_group_id, responsibility)
            WHERE ends_on IS NULL AND responsibility IN ('house_captain', 'vice_captain', 'club_president', 'club_vice_president', 'club_secretary')");
        DB::unprepared($this->responsibilityGuard(true));
    }

    public function down(): void
    {
        if (DB::table('student_groups')->where('group_type', '<>', 'house')->exists()) {
            throw new RuntimeException('Club or Activity history exists: SIS 11.8 cannot be rolled back without deleting it.');
        }

        DB::unprepared($this->responsibilityGuard(false));
        DB::statement('DROP INDEX student_group_responsibilities_unique_holder');
        DB::statement("CREATE UNIQUE INDEX student_group_responsibilities_unique_holder ON student_group_responsibilities (school_id, student_group_id, responsibility)
            WHERE ends_on IS NULL AND responsibility IN ('house_captain', 'vice_captain')");
        DB::statement('ALTER TABLE student_group_responsibilities DROP CONSTRAINT student_group_responsibilities_kind_check');
        DB::statement('ALTER TABLE student_group_responsibilities ADD CONSTRAINT student_group_responsibilities_kind_check CHECK (responsibility IN ('.self::HOUSE_RESPONSIBILITIES.'))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION student_group_memberships_no_overlap() RETURNS trigger LANGUAGE plpgsql AS $$
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
            SQL);
        DB::statement('DROP INDEX student_group_memberships_one_current_per_group');
        DB::statement('DROP INDEX student_group_memberships_one_current');
        DB::statement('CREATE UNIQUE INDEX student_group_memberships_one_current ON student_group_memberships (school_id, student_id, group_type) WHERE ends_on IS NULL');
        DB::statement('ALTER TABLE student_group_memberships DROP CONSTRAINT student_group_memberships_reason_check');
        DB::statement("ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_reason_check CHECK (start_reason IN ('assignment', 'reassignment', 'correction', 'left_school', 'school_decision') AND (end_reason IS NULL OR end_reason IN ('assignment', 'reassignment', 'correction', 'left_school', 'school_decision')))");
        DB::statement('ALTER TABLE student_group_memberships DROP CONSTRAINT student_group_memberships_type_check');
        DB::statement("ALTER TABLE student_group_memberships ADD CONSTRAINT student_group_memberships_type_check CHECK (group_type IN ('house'))");

        DB::statement('ALTER TABLE student_groups DROP CONSTRAINT student_groups_activity_category_check');
        DB::statement('ALTER TABLE student_groups DROP COLUMN activity_category');
        DB::statement('ALTER TABLE student_groups DROP CONSTRAINT student_groups_type_check');
        DB::statement("ALTER TABLE student_groups ADD CONSTRAINT student_groups_type_check CHECK (group_type IN ('house'))");
    }

    /** The responsibility guard; SIS 11.8 adds the per-type kind check on insert. */
    private function responsibilityGuard(bool $perType): string
    {
        // The per-type kind check, inserted before the INSERT branch's RETURN
        // (8-space body indentation, so down() reproduces SIS 11.7 exactly).
        $kind = $perType ? implode("\n", [
            "-- The kind belongs to the group's type: House kinds on a House,",
            '        -- Club kinds on a Club, none on an Activity.',
            '        IF NOT EXISTS (SELECT 1 FROM student_groups g WHERE g.id = NEW.student_group_id AND (',
            "            (g.group_type = 'house' AND NEW.responsibility IN ('house_captain', 'vice_captain', 'prefect'))",
            "            OR (g.group_type = 'club' AND NEW.responsibility IN ('club_president', 'club_vice_president', 'club_secretary', 'member_representative')))) THEN",
            "            RAISE EXCEPTION 'this responsibility does not exist for the group''s type' USING ERRCODE = 'check_violation';",
            '        END IF;',
            '        ',
        ]) : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION student_group_responsibilities_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    -- A responsibility starts only on a CURRENT membership.
                    IF NOT EXISTS (SELECT 1 FROM student_group_memberships m WHERE m.id = NEW.student_group_membership_id AND m.ends_on IS NULL) THEN
                        RAISE EXCEPTION 'a responsibility needs a current membership' USING ERRCODE = 'check_violation';
                    END IF;
                    {$kind}RETURN NEW;
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
            END \$\$;
            SQL;
    }
};
