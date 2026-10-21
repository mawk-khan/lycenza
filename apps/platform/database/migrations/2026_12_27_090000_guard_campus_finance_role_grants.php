<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.7G-2 (ADR 0076 §51, owner decision G2-D2): the database backstop for
     * the protected-Finance-authority rule ORG.7G-1 enforced in application
     * code only (StaffRoleCatalog::campusScopable(), §50.13 LOW-1).
     *
     * - `assert_membership_role_assignment_scope` (BEFORE INSERT on
     *   membership_role_assignments) now also refuses a Campus-scoped grant of
     *   a role holding any whole-School Finance authority -- finance.ledger.*,
     *   accounts/periods/fee-structure management, fee runs, charge management,
     *   finance.fee_concessions.* -- decided from the role's capabilities, never
     *   its name. A whole-School grant (campus_id NULL) is unaffected; a
     *   grant's Campus is already immutable (history trigger).
     * - `role_capabilities_campus_finance_guard` (BEFORE INSERT OR UPDATE on
     *   role_capabilities): a protected key can never be added to a role that
     *   has an ACTIVE Campus-scoped grant, so no existing grant is ever
     *   silently reinterpreted. membership_role_assignments is FORCE-RLS, so
     *   the check is meaningful only for a writer that bypasses RLS (the
     *   admin connection, rule 93): any other writer of a protected key is
     *   refused outright -- fail closed, never an empty EXISTS.
     *
     * Precondition: no active Campus-scoped grant of a protected-authority
     * role exists (ORG.7G-2 audit: none anywhere). If one did, this migration
     * aborts, changing nothing -- an owner remediation decision.
     */
    private const PROTECTED = <<<'SQL'
(rc.capability_key LIKE 'finance.ledger.%'
 OR rc.capability_key IN ('finance.accounts.manage', 'finance.periods.manage', 'finance.fee_structures.manage', 'finance.fee_assessments.run', 'finance.charges.manage')
 OR rc.capability_key LIKE 'finance.fee\_concessions.%')
SQL;

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
        -- ORG.7G-2 (ADR 0076 §51, owner decision G2-D2): nor a role holding
        -- whole-School Finance authority -- the same families as
        -- CampusScopeCatalogue::CAMPUS_INCOMPATIBLE, by capability, never by
        -- role name (cashier yes, accountant never).
        IF EXISTS (
            SELECT 1 FROM role_capabilities rc
            WHERE rc.role_id = NEW.role_id
              AND (rc.capability_key LIKE 'finance.ledger.%'
                   OR rc.capability_key IN ('finance.accounts.manage', 'finance.periods.manage', 'finance.fee_structures.manage', 'finance.fee_assessments.run', 'finance.charges.manage')
                   OR rc.capability_key LIKE 'finance.fee\_concessions.%')
        ) THEN
            RAISE EXCEPTION 'membership_role_assignments: a role carrying whole-School Finance authority cannot be Campus-scoped';
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

    public function up(): void
    {
        // The precondition reads every School's grants (FORCE RLS): only a
        // bypassing admin connection sees them all (ADR 0021).
        $role = DB::selectOne('SELECT rolsuper OR rolbypassrls AS bypass FROM pg_roles WHERE rolname = current_user');
        if ($role === null || ! $role->bypass) {
            throw new RuntimeException('ORG.7G-2: this migration must run on the admin connection with RLS bypass (ADR 0021); nothing was changed.');
        }

        $prohibited = (int) DB::selectOne('SELECT count(*) AS n FROM membership_role_assignments a
            WHERE a.revoked_at IS NULL AND a.campus_id IS NOT NULL
              AND EXISTS (SELECT 1 FROM role_capabilities rc WHERE rc.role_id = a.role_id AND '.self::PROTECTED.')')->n;
        if ($prohibited > 0) {
            throw new RuntimeException("ORG.7G-2: {$prohibited} active Campus-scoped grant(s) carry whole-School Finance authority. Nothing was changed; this needs an owner remediation decision (ADR 0076 §51).");
        }

        DB::unprepared(self::SCOPE_NEW);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION role_capabilities_campus_finance_guard() RETURNS trigger
                LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                IF NOT (NEW.capability_key LIKE 'finance.ledger.%'
                    OR NEW.capability_key IN ('finance.accounts.manage', 'finance.periods.manage', 'finance.fee_structures.manage', 'finance.fee_assessments.run', 'finance.charges.manage')
                    OR NEW.capability_key LIKE 'finance.fee\_concessions.%') THEN
                    RETURN NEW;
                END IF;
                -- membership_role_assignments is FORCE RLS: a writer that does
                -- not bypass it would see no grant at all. Fail closed.
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_user AND (rolsuper OR rolbypassrls)) THEN
                    RAISE EXCEPTION 'role_capabilities: whole-School Finance authority is written only by a catalogue owner that bypasses row-level security';
                END IF;
                IF EXISTS (SELECT 1 FROM membership_role_assignments a
                               WHERE a.role_id = NEW.role_id AND a.revoked_at IS NULL AND a.campus_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'role_capabilities: whole-School Finance authority cannot be added to a role with active Campus-scoped grants';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER role_capabilities_campus_finance_guard BEFORE INSERT OR UPDATE ON role_capabilities
                FOR EACH ROW EXECUTE FUNCTION role_capabilities_campus_finance_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS role_capabilities_campus_finance_guard ON role_capabilities;
            DROP FUNCTION IF EXISTS role_capabilities_campus_finance_guard();
            SQL);
        DB::unprepared(self::SCOPE_OLD);
    }
};
