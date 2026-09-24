<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0N.5 (ADR 0045 sections 9-10): one elevation primitive, two
 * authority sources. Every `school_elevations` row records the authority
 * it was started under -- fixed for its whole life:
 *
 * - `platform`: both Group columns NULL (every existing row);
 * - `group`: the authorizing School Group and the exact Group grant, the
 *   grant bound to that Group by a composite foreign key.
 *
 * Database-enforced:
 * - the authority combination (CHECK) and the three new Group end reasons
 *   (school_left_group, group_authority_revoked, group_inactive) as
 *   `terminated` reasons (school_elevations_end_check);
 * - authority_type, the Group and the grant join actor, School, reason,
 *   start and expiry as immutable (school_elevations_guard_update);
 * - a Group-derived row can only be INSERTED while its grant is unrevoked
 *   and belongs to the actor, its Group is active and the School is a
 *   member of that Group -- rows read FOR SHARE, so a concurrent removal,
 *   revocation or archive waits for this insert to commit and then
 *   terminates it (fail closed; school_elevations_assert_group_authority).
 */
return new class extends Migration
{
    private const PLATFORM_END_CHECK = <<<'SQL'
        (status = 'active' AND ended_at IS NULL AND end_reason IS NULL)
        OR (status = 'ended' AND ended_at IS NOT NULL AND end_reason IN ('exited', 'logout'))
        OR (status = 'expired' AND ended_at IS NOT NULL AND end_reason = 'expired')
        OR (status = 'terminated' AND ended_at IS NOT NULL AND end_reason IN ('actor_disabled', 'capability_revoked', 'school_ineligible', 'membership_conflict', 'mfa_factor_revoked'))
        SQL;

    private const GROUP_END_CHECK = <<<'SQL'
        (status = 'active' AND ended_at IS NULL AND end_reason IS NULL)
        OR (status = 'ended' AND ended_at IS NOT NULL AND end_reason IN ('exited', 'logout'))
        OR (status = 'expired' AND ended_at IS NOT NULL AND end_reason = 'expired')
        OR (status = 'terminated' AND ended_at IS NOT NULL AND end_reason IN ('actor_disabled', 'capability_revoked', 'school_ineligible', 'membership_conflict', 'mfa_factor_revoked', 'school_left_group', 'group_authority_revoked', 'group_inactive'))
        SQL;

    public function up(): void
    {
        Schema::table('school_elevations', function (Blueprint $table) {
            $table->string('authority_type')->default('platform')->after('school_id');
            $table->foreignUuid('school_group_id')->nullable()->after('authority_type')->constrained('school_groups')->restrictOnDelete();
            $table->uuid('group_role_assignment_id')->nullable()->after('school_group_id');

            $table->index('school_group_id');
            $table->index('group_role_assignment_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE school_elevations
                ADD CONSTRAINT school_elevations_group_grant_fk
                    FOREIGN KEY (group_role_assignment_id, school_group_id)
                    REFERENCES group_role_assignments (id, school_group_id) ON DELETE RESTRICT,
                ADD CONSTRAINT school_elevations_authority_check
                    CHECK (
                        (authority_type = 'platform' AND school_group_id IS NULL AND group_role_assignment_id IS NULL)
                        OR (authority_type = 'group' AND school_group_id IS NOT NULL AND group_role_assignment_id IS NOT NULL)
                    )
            SQL);

        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT school_elevations_end_check');
        DB::statement('ALTER TABLE school_elevations ADD CONSTRAINT school_elevations_end_check CHECK ('.self::GROUP_END_CHECK.')');

        $this->guardFunction(withAuthority: true);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION school_elevations_assert_group_authority() RETURNS trigger AS $$
            DECLARE
                grant_user uuid;
                grant_revoked timestamp;
                group_status text;
            BEGIN
                -- Not Group authority, or an incomplete Group row that the
                -- school_elevations_authority_check constraint rejects.
                IF NEW.authority_type IS DISTINCT FROM 'group'
                    OR NEW.school_group_id IS NULL
                    OR NEW.group_role_assignment_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT status INTO group_status FROM school_groups WHERE id = NEW.school_group_id FOR SHARE;
                IF group_status IS DISTINCT FROM 'active' THEN
                    RAISE EXCEPTION 'school_elevations: the authorizing School Group is not active';
                END IF;

                SELECT user_id, revoked_at INTO grant_user, grant_revoked
                    FROM group_role_assignments
                    WHERE id = NEW.group_role_assignment_id AND school_group_id = NEW.school_group_id
                    FOR SHARE;
                IF grant_user IS DISTINCT FROM NEW.actor_user_id OR grant_revoked IS NOT NULL THEN
                    RAISE EXCEPTION 'school_elevations: the authorizing Group grant is not an active grant of this actor';
                END IF;

                PERFORM 1 FROM school_group_members
                    WHERE school_group_id = NEW.school_group_id AND school_id = NEW.school_id
                    FOR SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'school_elevations: the target School is not a member of the authorizing School Group';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER school_elevations_assert_group_authority
                BEFORE INSERT ON school_elevations
                FOR EACH ROW EXECUTE FUNCTION school_elevations_assert_group_authority();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS school_elevations_assert_group_authority ON school_elevations;
            DROP FUNCTION IF EXISTS school_elevations_assert_group_authority();
            SQL);

        $this->guardFunction(withAuthority: false);

        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT school_elevations_end_check');
        DB::statement('ALTER TABLE school_elevations ADD CONSTRAINT school_elevations_end_check CHECK ('.self::PLATFORM_END_CHECK.')');

        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT IF EXISTS school_elevations_authority_check');
        DB::statement('ALTER TABLE school_elevations DROP CONSTRAINT IF EXISTS school_elevations_group_grant_fk');

        Schema::table('school_elevations', function (Blueprint $table) {
            $table->dropForeign(['school_group_id']);
            $table->dropIndex(['school_group_id']);
            $table->dropIndex(['group_role_assignment_id']);
            $table->dropColumn(['authority_type', 'school_group_id', 'group_role_assignment_id']);
        });
    }

    /**
     * The Phase 0N.3 transition guard, with (up) or without (down) the
     * authority columns in its immutable set.
     */
    private function guardFunction(bool $withAuthority): void
    {
        $authority = $withAuthority ? <<<'SQL'
                    OR NEW.authority_type IS DISTINCT FROM OLD.authority_type
                    OR NEW.school_group_id IS DISTINCT FROM OLD.school_group_id
                    OR NEW.group_role_assignment_id IS DISTINCT FROM OLD.group_role_assignment_id
            SQL : '';
        $message = $withAuthority
            ? 'school_elevations: actor, School, authority, reason, start and expiry are immutable'
            : 'school_elevations: actor, School, reason, start and expiry are immutable';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION school_elevations_guard_update() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.status <> 'active' THEN
                    RAISE EXCEPTION 'school_elevations: a finished elevation is immutable';
                END IF;
                IF NEW.status = 'active' THEN
                    RAISE EXCEPTION 'school_elevations: an active elevation may only be finished';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.actor_user_id IS DISTINCT FROM OLD.actor_user_id
                    OR NEW.school_id IS DISTINCT FROM OLD.school_id
                    OR NEW.reason_code IS DISTINCT FROM OLD.reason_code
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.start_request_id IS DISTINCT FROM OLD.start_request_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    {$authority} THEN
                    RAISE EXCEPTION '{$message}';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            SQL);
    }
};
