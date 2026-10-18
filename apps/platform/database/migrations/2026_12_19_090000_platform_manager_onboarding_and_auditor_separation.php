<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG.4 (ADR 0076 §7.4, ORG-C3): one person never holds an active Platform
 * Manager grant and an active Platform Auditor grant at the same time -- the
 * auditor oversees operator activity, including the manager's.
 *
 * A separate BEFORE INSERT trigger, so the existing governance trigger
 * (trg_platform_role_assignments_governance) is untouched. It applies to
 * every grant, runtime or out of band. A per-user advisory transaction lock
 * serializes concurrent grants to the same person, so two racing grants of
 * the two roles can never both commit. A revoked grant never conflicts:
 * revoking one role makes the other grantable again.
 *
 * PlatformRoleGovernanceService refuses the conflict first (audited as
 * `role_conflict`); this is the authoritative backstop.
 *
 * ORG.4 (ADR 0076 §7.2) also lets an onboarding operator establish the
 * bootstrap School Administrator: the ADR 0047 bootstrap exception in
 * assert_membership_role_assignment_grantor() (SR.1) accepted only a holder
 * of `platform.schools.manage`; it now accepts `platform.schools.onboard`
 * too. Nothing else in the function changes -- still only the
 * `school_admin` role, only for a School in `provisioning`, only from an
 * enabled user with an active platform grant. down() restores the previous
 * definition verbatim.
 */
return new class extends Migration
{
    private const BOOTSTRAP_GRANTOR = <<<'SQL'
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
$function$;
SQL;

    private const PREVIOUS_BOOTSTRAP_GRANTOR = <<<'SQL'
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
    -- still being provisioned, by a platform School manager.
    IF v_role.key = 'school_admin'
        AND EXISTS (SELECT 1 FROM schools s WHERE s.id = NEW.school_id AND s.status = 'provisioning')
        AND EXISTS (
            SELECT 1 FROM platform_role_assignments pra
            JOIN users pu ON pu.id = pra.user_id AND NOT pu.is_disabled
            JOIN role_capabilities prc ON prc.role_id = pra.role_id AND prc.capability_key = 'platform.schools.manage'
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
$function$;
SQL;

    public function up(): void
    {
        DB::unprepared(self::BOOTSTRAP_GRANTOR);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_platform_role_separation() RETURNS trigger AS $$
            DECLARE
                role_key text;
                conflicting text;
            BEGIN
                IF NEW.revoked_at IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                SELECT key INTO role_key FROM roles WHERE id = NEW.role_id;
                conflicting := CASE role_key
                    WHEN 'platform_manager' THEN 'platform_auditor'
                    WHEN 'platform_auditor' THEN 'platform_manager'
                END;
                IF conflicting IS NULL THEN
                    RETURN NEW;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtextextended('platform_role_separation:' || NEW.user_id::text, 0));

                IF EXISTS (
                    SELECT 1 FROM platform_role_assignments a
                    JOIN roles r ON r.id = a.role_id
                    WHERE a.user_id = NEW.user_id
                      AND a.revoked_at IS NULL
                      AND r.key = conflicting
                ) THEN
                    RAISE EXCEPTION 'platform_role_separation: % cannot be granted to a user who holds an active % grant', role_key, conflicting
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_platform_role_assignments_separation
                BEFORE INSERT ON platform_role_assignments
                FOR EACH ROW EXECUTE FUNCTION assert_platform_role_separation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_platform_role_assignments_separation ON platform_role_assignments;
            DROP FUNCTION IF EXISTS assert_platform_role_separation();
            SQL);

        DB::unprepared(self::PREVIOUS_BOOTSTRAP_GRANTOR);
    }
};
