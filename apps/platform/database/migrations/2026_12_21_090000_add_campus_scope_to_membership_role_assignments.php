<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG.7A (ADR 0076 §11, §33): an optional Campus scope on School role
 * assignments.
 *
 * - `campus_id` NULL means the whole School -- every existing row keeps
 *   NULL and its meaning; nothing is backfilled or reinterpreted (§11.2).
 * - A non-NULL Campus is exactly one Campus of the SAME School: composite
 *   foreign key `(campus_id, school_id) -> campuses(id, school_id)`,
 *   ON DELETE RESTRICT, so grant history never disappears with a Campus.
 * - At most one active grant per (membership, role, scope): one partial
 *   unique index for the whole-School scope and one for a specific Campus
 *   (NULLs handled deliberately; revoked history untouched).
 * - The scope trigger admits a Campus only on a school-scope role that
 *   carries no access administration (school.members.manage,
 *   school.roles.manage, school.roles.grant.* -- ORG-C2), and only an
 *   ACTIVE Campus for a new grant (row read FOR SHARE, so a concurrent
 *   inactivation serializes). The history trigger makes the
 *   Campus immutable. The grantor trigger counts only the issuer's
 *   whole-School grants (§11.4: grant authority is never Campus-scoped).
 *
 * down() restores the three functions verbatim and the original single
 * active-grant index. It refuses while any Campus-scoped grant row exists
 * (revoked history included):
 * dropping the column would turn a narrow grant into whole-School
 * authority, which is never acceptable (revoke them first).
 */
return new class extends Migration
{
    private const SCOPE_NEW = <<<'SQL'
CREATE OR REPLACE FUNCTION public.assert_membership_role_assignment_scope()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    role_scope text;
BEGIN
    SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
    IF role_scope IS DISTINCT FROM 'school' AND role_scope IS DISTINCT FROM 'guardian' THEN
        RAISE EXCEPTION
            'membership_role_assignments.role_id must reference a role with scope=school or scope=guardian (got %)',
            role_scope;
    END IF;
    IF role_scope = 'guardian' AND TG_OP = 'INSERT' AND NOT EXISTS (
        SELECT 1 FROM student_guardian_account_links l
        WHERE l.school_membership_id = NEW.school_membership_id
          AND l.school_id = NEW.school_id
          AND l.status = 'active'
          AND l.guardian_id IS NOT NULL
    ) THEN
        RAISE EXCEPTION
            'membership_role_assignments: a guardian-scope grant needs an active Guardian account link on the same membership';
    END IF;
    -- ORG.7A (ADR 0076 §11.2, §11.4, §11.6, §33): an optional Campus scope.
    -- Only a school-scope role may carry one, never a role holding access
    -- administration (school.members.manage, school.roles.manage, the
    -- school.roles.grant.* rights -- ORG-C2), never an inactive Campus for a
    -- new grant. Same-School belonging is the composite foreign key.
    -- Checked when the grant is created only: the Campus is immutable
    -- afterwards (history trigger), and a revocation must never be refused.
    IF NEW.campus_id IS NOT NULL AND TG_OP = 'INSERT' THEN
        IF role_scope IS DISTINCT FROM 'school' THEN
            RAISE EXCEPTION 'membership_role_assignments: only a school-scope role can be Campus-scoped';
        END IF;
        IF EXISTS (
            SELECT 1 FROM role_capabilities rc
            WHERE rc.role_id = NEW.role_id
              AND (rc.capability_key IN ('school.members.manage', 'school.roles.manage') OR rc.capability_key LIKE 'school.roles.grant.%')
        ) THEN
            RAISE EXCEPTION 'membership_role_assignments: a role carrying access administration cannot be Campus-scoped';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM campuses c
            WHERE c.id = NEW.campus_id AND c.school_id = NEW.school_id AND c.status = 'active'
            FOR SHARE
        ) THEN
            RAISE EXCEPTION 'membership_role_assignments: a Campus-scoped grant needs an active Campus of the same School';
        END IF;
    END IF;
    RETURN NEW;
END;
$function$
SQL;

    private const HISTORY_NEW = <<<'SQL'
CREATE OR REPLACE FUNCTION public.membership_role_assignments_history_guard()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.revoked_at IS NOT NULL THEN
            RAISE EXCEPTION 'membership_role_assignments: a grant cannot be created already revoked';
        END IF;
        RETURN NEW;
    END IF;

    IF OLD.revoked_at IS NOT NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: a revoked grant is immutable and cannot be reactivated';
    END IF;
    IF NEW.revoked_at IS NULL
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.school_id IS DISTINCT FROM OLD.school_id
        OR NEW.school_membership_id IS DISTINCT FROM OLD.school_membership_id
        OR NEW.role_id IS DISTINCT FROM OLD.role_id
        OR NEW.campus_id IS DISTINCT FROM OLD.campus_id
        OR NEW.assigned_by_user_id IS DISTINCT FROM OLD.assigned_by_user_id
        OR NEW.assigned_at IS DISTINCT FROM OLD.assigned_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'membership_role_assignments: the only permitted change is revocation';
    END IF;
    RETURN NEW;
END;
$function$
SQL;

    private const GRANTOR_NEW = <<<'SQL'
CREATE OR REPLACE FUNCTION public.assert_membership_role_assignment_grantor()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_role roles%ROWTYPE;
    v_target_user uuid;
    v_issuer_membership uuid;
    v_held text[];
    v_uncovered integer;
BEGIN
    SELECT * INTO v_role FROM roles WHERE id = NEW.role_id;
    IF NOT FOUND THEN
        RETURN NEW; -- the foreign key reports it
    END IF;

    IF v_role.retired_at IS NOT NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: role % is retired and cannot be granted', v_role.key;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM role_capabilities WHERE role_id = v_role.id) THEN
        RAISE EXCEPTION 'membership_role_assignments: role % has no capabilities and cannot be granted', v_role.key;
    END IF;

    -- Guardian-scope grants keep their own link rule; other scopes are
    -- refused by trg_membership_role_assignments_scope.
    IF v_role.scope IS DISTINCT FROM 'school' THEN
        RETURN NEW;
    END IF;

    -- The trusted administrative boundary (Phase 0O.1A pattern).
    IF pg_has_role(current_user, (SELECT relowner FROM pg_class WHERE oid = TG_RELID), 'USAGE') THEN
        RETURN NEW;
    END IF;

    IF NEW.assigned_by_user_id IS NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: a runtime School-role grant must name its assigning user';
    END IF;

    -- ADR 0047 bootstrap: the first School Administrator of a School
    -- still being provisioned, by a platform School manager -- or, since
    -- ORG.4 (ADR 0076 §7.2), a platform onboarding operator.
    IF v_role.key = 'school_admin'
        AND EXISTS (SELECT 1 FROM schools s WHERE s.id = NEW.school_id AND s.status = 'provisioning')
        AND EXISTS (
            SELECT 1 FROM platform_role_assignments pra
            JOIN users pu ON pu.id = pra.user_id AND NOT pu.is_disabled
            JOIN role_capabilities prc ON prc.role_id = pra.role_id AND prc.capability_key IN ('platform.schools.manage', 'platform.schools.onboard')
            WHERE pra.user_id = NEW.assigned_by_user_id AND pra.revoked_at IS NULL
        ) THEN
        RETURN NEW;
    END IF;

    SELECT user_id INTO v_target_user FROM school_memberships WHERE id = NEW.school_membership_id;
    IF v_target_user = NEW.assigned_by_user_id THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user cannot grant a role to themselves';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.assigned_by_user_id AND NOT is_disabled) THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user is unknown or disabled';
    END IF;

    SELECT m.id INTO v_issuer_membership FROM school_memberships m
    WHERE m.user_id = NEW.assigned_by_user_id AND m.school_id = NEW.school_id AND m.status = 'active'
    FOR SHARE;
    IF v_issuer_membership IS NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user has no active membership in this School';
    END IF;

    PERFORM 1 FROM membership_role_assignments a
    WHERE a.school_membership_id = v_issuer_membership AND a.revoked_at IS NULL
    FOR SHARE;

    -- ORG.7A (ADR 0076 §11.4): grant authority is whole-School only -- a
    -- Campus-scoped grant of the issuer never counts.
    SELECT coalesce(array_agg(DISTINCT rc.capability_key), ARRAY[]::text[]) INTO v_held
    FROM membership_role_assignments a
    JOIN roles r ON r.id = a.role_id AND r.scope = 'school'
    JOIN role_capabilities rc ON rc.role_id = a.role_id
    WHERE a.school_membership_id = v_issuer_membership AND a.revoked_at IS NULL AND a.campus_id IS NULL;

    IF NOT ('school.roles.manage' = ANY (v_held)) THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user does not hold school.roles.manage in this School';
    END IF;

    SELECT count(*) INTO v_uncovered
    FROM role_capabilities t
    JOIN capabilities c ON c.key = t.capability_key
    WHERE t.role_id = v_role.id
      AND NOT (t.capability_key = ANY (v_held))
      AND (c.grant_right IS NULL OR NOT (c.grant_right = ANY (v_held)));
    IF v_uncovered > 0 THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user does not hold or cover every capability of role %', v_role.key;
    END IF;

    RETURN NEW;
END;
$function$
SQL;

    private const SCOPE_OLD = <<<'SQL'
CREATE OR REPLACE FUNCTION public.assert_membership_role_assignment_scope()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    role_scope text;
BEGIN
    SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
    IF role_scope IS DISTINCT FROM 'school' AND role_scope IS DISTINCT FROM 'guardian' THEN
        RAISE EXCEPTION
            'membership_role_assignments.role_id must reference a role with scope=school or scope=guardian (got %)',
            role_scope;
    END IF;
    IF role_scope = 'guardian' AND TG_OP = 'INSERT' AND NOT EXISTS (
        SELECT 1 FROM student_guardian_account_links l
        WHERE l.school_membership_id = NEW.school_membership_id
          AND l.school_id = NEW.school_id
          AND l.status = 'active'
          AND l.guardian_id IS NOT NULL
    ) THEN
        RAISE EXCEPTION
            'membership_role_assignments: a guardian-scope grant needs an active Guardian account link on the same membership';
    END IF;
    RETURN NEW;
END;
$function$
SQL;

    private const HISTORY_OLD = <<<'SQL'
CREATE OR REPLACE FUNCTION public.membership_role_assignments_history_guard()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.revoked_at IS NOT NULL THEN
            RAISE EXCEPTION 'membership_role_assignments: a grant cannot be created already revoked';
        END IF;
        RETURN NEW;
    END IF;

    IF OLD.revoked_at IS NOT NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: a revoked grant is immutable and cannot be reactivated';
    END IF;
    IF NEW.revoked_at IS NULL
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.school_id IS DISTINCT FROM OLD.school_id
        OR NEW.school_membership_id IS DISTINCT FROM OLD.school_membership_id
        OR NEW.role_id IS DISTINCT FROM OLD.role_id
        OR NEW.assigned_by_user_id IS DISTINCT FROM OLD.assigned_by_user_id
        OR NEW.assigned_at IS DISTINCT FROM OLD.assigned_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'membership_role_assignments: the only permitted change is revocation';
    END IF;
    RETURN NEW;
END;
$function$
SQL;

    private const GRANTOR_OLD = <<<'SQL'
CREATE OR REPLACE FUNCTION public.assert_membership_role_assignment_grantor()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    v_role roles%ROWTYPE;
    v_target_user uuid;
    v_issuer_membership uuid;
    v_held text[];
    v_uncovered integer;
BEGIN
    SELECT * INTO v_role FROM roles WHERE id = NEW.role_id;
    IF NOT FOUND THEN
        RETURN NEW; -- the foreign key reports it
    END IF;

    IF v_role.retired_at IS NOT NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: role % is retired and cannot be granted', v_role.key;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM role_capabilities WHERE role_id = v_role.id) THEN
        RAISE EXCEPTION 'membership_role_assignments: role % has no capabilities and cannot be granted', v_role.key;
    END IF;

    -- Guardian-scope grants keep their own link rule; other scopes are
    -- refused by trg_membership_role_assignments_scope.
    IF v_role.scope IS DISTINCT FROM 'school' THEN
        RETURN NEW;
    END IF;

    -- The trusted administrative boundary (Phase 0O.1A pattern).
    IF pg_has_role(current_user, (SELECT relowner FROM pg_class WHERE oid = TG_RELID), 'USAGE') THEN
        RETURN NEW;
    END IF;

    IF NEW.assigned_by_user_id IS NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: a runtime School-role grant must name its assigning user';
    END IF;

    -- ADR 0047 bootstrap: the first School Administrator of a School
    -- still being provisioned, by a platform School manager -- or, since
    -- ORG.4 (ADR 0076 §7.2), a platform onboarding operator.
    IF v_role.key = 'school_admin'
        AND EXISTS (SELECT 1 FROM schools s WHERE s.id = NEW.school_id AND s.status = 'provisioning')
        AND EXISTS (
            SELECT 1 FROM platform_role_assignments pra
            JOIN users pu ON pu.id = pra.user_id AND NOT pu.is_disabled
            JOIN role_capabilities prc ON prc.role_id = pra.role_id AND prc.capability_key IN ('platform.schools.manage', 'platform.schools.onboard')
            WHERE pra.user_id = NEW.assigned_by_user_id AND pra.revoked_at IS NULL
        ) THEN
        RETURN NEW;
    END IF;

    SELECT user_id INTO v_target_user FROM school_memberships WHERE id = NEW.school_membership_id;
    IF v_target_user = NEW.assigned_by_user_id THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user cannot grant a role to themselves';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.assigned_by_user_id AND NOT is_disabled) THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user is unknown or disabled';
    END IF;

    SELECT m.id INTO v_issuer_membership FROM school_memberships m
    WHERE m.user_id = NEW.assigned_by_user_id AND m.school_id = NEW.school_id AND m.status = 'active'
    FOR SHARE;
    IF v_issuer_membership IS NULL THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user has no active membership in this School';
    END IF;

    PERFORM 1 FROM membership_role_assignments a
    WHERE a.school_membership_id = v_issuer_membership AND a.revoked_at IS NULL
    FOR SHARE;

    SELECT coalesce(array_agg(DISTINCT rc.capability_key), ARRAY[]::text[]) INTO v_held
    FROM membership_role_assignments a
    JOIN roles r ON r.id = a.role_id AND r.scope = 'school'
    JOIN role_capabilities rc ON rc.role_id = a.role_id
    WHERE a.school_membership_id = v_issuer_membership AND a.revoked_at IS NULL;

    IF NOT ('school.roles.manage' = ANY (v_held)) THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user does not hold school.roles.manage in this School';
    END IF;

    SELECT count(*) INTO v_uncovered
    FROM role_capabilities t
    JOIN capabilities c ON c.key = t.capability_key
    WHERE t.role_id = v_role.id
      AND NOT (t.capability_key = ANY (v_held))
      AND (c.grant_right IS NULL OR NOT (c.grant_right = ANY (v_held)));
    IF v_uncovered > 0 THEN
        RAISE EXCEPTION 'membership_role_assignments: the assigning user does not hold or cover every capability of role %', v_role.key;
    END IF;

    RETURN NEW;
END;
$function$
SQL;

    public function up(): void
    {
        DB::statement('ALTER TABLE membership_role_assignments ADD COLUMN campus_id uuid NULL');
        DB::statement('ALTER TABLE membership_role_assignments ADD CONSTRAINT membership_role_assignments_campus_school_foreign FOREIGN KEY (campus_id, school_id) REFERENCES campuses(id, school_id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX membership_role_assignments_campus_id_index ON membership_role_assignments (campus_id) WHERE campus_id IS NOT NULL');

        DB::statement('DROP INDEX membership_role_assignments_one_active');
        DB::statement('CREATE UNIQUE INDEX membership_role_assignments_one_active ON membership_role_assignments (school_membership_id, role_id) WHERE revoked_at IS NULL AND campus_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX membership_role_assignments_one_active_campus ON membership_role_assignments (school_membership_id, role_id, campus_id) WHERE revoked_at IS NULL AND campus_id IS NOT NULL');

        DB::unprepared(self::SCOPE_NEW);
        DB::unprepared(self::HISTORY_NEW);
        DB::unprepared(self::GRANTOR_NEW);
    }

    public function down(): void
    {
        // A CHECK is validated against EVERY row regardless of RLS (a plain
        // SELECT here would see nothing under FORCE RLS): any Campus-scoped
        // grant aborts the rollback instead of being widened.
        try {
            DB::statement('ALTER TABLE membership_role_assignments ADD CONSTRAINT membership_role_assignments_rollback_no_campus CHECK (campus_id IS NULL)');
        } catch (Throwable $e) {
            throw new RuntimeException('Campus-scoped role grants exist: dropping the scope would widen them to the whole School. Revoke and retire them first.', previous: $e);
        }
        DB::statement('ALTER TABLE membership_role_assignments DROP CONSTRAINT membership_role_assignments_rollback_no_campus');

        DB::unprepared(self::SCOPE_OLD);
        DB::unprepared(self::HISTORY_OLD);
        DB::unprepared(self::GRANTOR_OLD);

        DB::statement('DROP INDEX membership_role_assignments_one_active_campus');
        DB::statement('DROP INDEX membership_role_assignments_one_active');
        DB::statement('CREATE UNIQUE INDEX membership_role_assignments_one_active ON membership_role_assignments (school_membership_id, role_id) WHERE revoked_at IS NULL');
        DB::statement('DROP INDEX membership_role_assignments_campus_id_index');
        DB::statement('ALTER TABLE membership_role_assignments DROP CONSTRAINT membership_role_assignments_campus_school_foreign');
        Schema::table('membership_role_assignments', fn ($table) => $table->dropColumn('campus_id'));
    }
};
