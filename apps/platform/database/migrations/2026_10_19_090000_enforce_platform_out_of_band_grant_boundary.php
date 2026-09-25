<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0O.1A (ADR 0046 section 2): an out-of-band platform role grant
 * (`granted_by_user_id` NULL -- how the root role arrives) may be written
 * ONLY across the trusted administrative database boundary.
 *
 * Before this, trg_platform_role_assignments_governance treated a NULL
 * grantor as "provisioned out of band" without checking who was writing,
 * so the runtime role (`school_os_app`) could insert a root assignment by
 * raw SQL. Now a grantor-less INSERT requires that the writing role hold
 * the privileges of the table's OWNER -- the migration/admin role (ADR
 * 0021) -- via pg_has_role(current_user, owner, 'USAGE'), which is true for
 * the owner, a member that inherits it, or a superuser. `current_user`
 * cannot be forged by a client, and the runtime role is never a member of
 * the owner role (asserted by Tests\Feature\Postgres\PlatformRootBoundaryTest),
 * so no role name is hard-coded here (decision O6 stays open).
 *
 * Unchanged: a runtime grant must name a grantor AND a runtime-assignable
 * role (so a "forged" grantor never makes the root role grantable), grants
 * are never created revoked, revocation is the only permitted change and
 * only of a runtime-assignable role, revoked rows are immutable, and the
 * scope trigger still keeps other scopes out. The trigger stays enabled
 * for every role; nothing is disabled or bypassed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_platform_role_assignment_governance() RETURNS trigger AS $$
            DECLARE
                assignable boolean;
                role_scope text;
            BEGIN
                SELECT runtime_assignable, scope INTO assignable, role_scope FROM roles WHERE id = NEW.role_id;

                -- Another scope's role is refused by trg_platform_role_assignments_scope.
                IF role_scope IS DISTINCT FROM 'platform' THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'platform_role_assignments: a grant cannot be created already revoked';
                    END IF;
                    IF NEW.granted_by_user_id IS NULL THEN
                        IF NOT pg_has_role(current_user, (SELECT relowner FROM pg_class WHERE oid = TG_RELID), 'USAGE') THEN
                            RAISE EXCEPTION 'platform_role_assignments: only the trusted administrative database role may provision a grant without a grantor (out of band)';
                        END IF;
                    ELSIF NOT assignable THEN
                        RAISE EXCEPTION 'platform_role_assignments: only a runtime-assignable role can be granted at runtime';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'platform_role_assignments: a revoked grant is immutable and cannot be reactivated';
                END IF;
                IF NEW.revoked_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.role_id IS DISTINCT FROM OLD.role_id
                    OR NEW.granted_by_user_id IS DISTINCT FROM OLD.granted_by_user_id
                    OR NEW.granted_at IS DISTINCT FROM OLD.granted_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'platform_role_assignments: the only permitted change is revocation';
                END IF;
                IF NOT assignable THEN
                    RAISE EXCEPTION 'platform_role_assignments: only a runtime-assignable role can be revoked at runtime';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    /**
     * Restores the Phase 0N.7 function body (2026_10_17_090100), which
     * accepted a grantor-less insert from any role.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_platform_role_assignment_governance() RETURNS trigger AS $$
            DECLARE
                assignable boolean;
            BEGIN
                SELECT runtime_assignable INTO assignable FROM roles WHERE id = NEW.role_id;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'platform_role_assignments: a grant cannot be created already revoked';
                    END IF;
                    IF NEW.granted_by_user_id IS NOT NULL AND NOT assignable THEN
                        RAISE EXCEPTION 'platform_role_assignments: only a runtime-assignable role can be granted at runtime';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'platform_role_assignments: a revoked grant is immutable and cannot be reactivated';
                END IF;
                IF NEW.revoked_at IS NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.role_id IS DISTINCT FROM OLD.role_id
                    OR NEW.granted_by_user_id IS DISTINCT FROM OLD.granted_by_user_id
                    OR NEW.granted_at IS DISTINCT FROM OLD.granted_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'platform_role_assignments: the only permitted change is revocation';
                END IF;
                IF NOT assignable THEN
                    RAISE EXCEPTION 'platform_role_assignments: only a runtime-assignable role can be revoked at runtime';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }
};
