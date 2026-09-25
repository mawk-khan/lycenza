<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0N.9 (ADR 0047 sections 2, 8, 12): the School lifecycle, enforced
 * by the database.
 *
 * - `schools.status` keeps being the only lifecycle field. Allowed values
 *   `provisioning`, `active`, `suspended`, `archived` (CHECK); the default
 *   becomes `provisioning`, so a School inserted without an explicit
 *   status is never operational. Existing rows (`active`, `suspended`,
 *   `archived`) already satisfy the CHECK and are not rewritten.
 * - A status change is refused unless it is provisioning -> active,
 *   active -> suspended or suspended -> active (every role; there is no
 *   application path into or out of `archived`, and nothing ever returns
 *   to `provisioning`, which is what closes the bootstrap-administrator
 *   path for good).
 * - The runtime role cannot DELETE a School: 147 foreign keys cascade
 *   from `schools`, including audit evidence and the Finance journal.
 * - Elevation: a new terminal end reason `school_suspended`, and an
 *   INSERT guard that reads the target School FOR SHARE and refuses a
 *   non-active one, so an elevation start racing a suspension either
 *   commits first (and is then terminated by it) or is refused.
 */
return new class extends Migration
{
    private const PREVIOUS_END_CHECK = <<<'SQL'
        (status = 'active' AND ended_at IS NULL AND end_reason IS NULL)
        OR (status = 'ended' AND ended_at IS NOT NULL AND end_reason IN ('exited', 'logout'))
        OR (status = 'expired' AND ended_at IS NOT NULL AND end_reason = 'expired')
        OR (status = 'terminated' AND ended_at IS NOT NULL AND end_reason IN ('actor_disabled', 'capability_revoked', 'school_ineligible', 'membership_conflict', 'mfa_factor_revoked', 'school_left_group', 'group_authority_revoked', 'group_inactive'))
        SQL;

    private const LIFECYCLE_END_CHECK = <<<'SQL'
        (status = 'active' AND ended_at IS NULL AND end_reason IS NULL)
        OR (status = 'ended' AND ended_at IS NOT NULL AND end_reason IN ('exited', 'logout'))
        OR (status = 'expired' AND ended_at IS NOT NULL AND end_reason = 'expired')
        OR (status = 'terminated' AND ended_at IS NOT NULL AND end_reason IN ('actor_disabled', 'capability_revoked', 'school_ineligible', 'membership_conflict', 'mfa_factor_revoked', 'school_left_group', 'group_authority_revoked', 'group_inactive', 'school_suspended'))
        SQL;

    public function up(): void
    {
        DB::statement("ALTER TABLE schools ALTER COLUMN status SET DEFAULT 'provisioning'");
        DB::statement("ALTER TABLE schools ADD CONSTRAINT schools_status_check CHECK (status IN ('provisioning', 'active', 'suspended', 'archived'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_school_status_transition() RETURNS trigger AS $$
            BEGIN
                IF (OLD.status, NEW.status) IN (('provisioning', 'active'), ('active', 'suspended'), ('suspended', 'active')) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'schools: status transition % -> % is not permitted', OLD.status, NEW.status;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_schools_status_transition
                BEFORE UPDATE OF status ON schools
                FOR EACH ROW
                WHEN (OLD.status IS DISTINCT FROM NEW.status)
                EXECUTE FUNCTION assert_school_status_transition();
            SQL);

        TenantRls::revokeDelete('schools');

        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT school_elevations_end_check');
        DB::statement('ALTER TABLE school_elevations ADD CONSTRAINT school_elevations_end_check CHECK ('.self::LIFECYCLE_END_CHECK.')');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION school_elevations_assert_school_active() RETURNS trigger AS $$
            DECLARE
                school_status text;
            BEGIN
                SELECT status INTO school_status FROM schools WHERE id = NEW.school_id FOR SHARE;

                IF school_status IS DISTINCT FROM 'active' THEN
                    RAISE EXCEPTION 'school_elevations: the target School is not active';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER school_elevations_assert_school_active
                BEFORE INSERT ON school_elevations
                FOR EACH ROW EXECUTE FUNCTION school_elevations_assert_school_active();
            SQL);
    }

    public function down(): void
    {
        // Finished elevation rows are immutable (school_elevations_guard_update),
        // so a `school_suspended` row cannot be rewritten to fit the older
        // constraint: refuse rather than silently lose that evidence.
        if (DB::table('school_elevations')->where('end_reason', 'school_suspended')->exists()) {
            throw new RuntimeException('Cannot roll back: school_elevations holds rows ended with school_suspended, which the previous constraint does not allow.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS school_elevations_assert_school_active ON school_elevations;
            DROP FUNCTION IF EXISTS school_elevations_assert_school_active();
            SQL);

        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT school_elevations_end_check');
        DB::statement('ALTER TABLE school_elevations ADD CONSTRAINT school_elevations_end_check CHECK ('.self::PREVIOUS_END_CHECK.')');

        DB::statement('GRANT DELETE ON schools TO school_os_app');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_schools_status_transition ON schools;
            DROP FUNCTION IF EXISTS assert_school_status_transition();
            SQL);

        // `provisioning` rows stay as they are: the previous code treats any
        // non-`active` value as non-operational, which is still correct.
        DB::statement('ALTER TABLE schools DROP CONSTRAINT IF EXISTS schools_status_check');
        DB::statement("ALTER TABLE schools ALTER COLUMN status SET DEFAULT 'active'");
    }
};
