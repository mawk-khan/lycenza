<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0N.5 (ADR 0045 sections 2-4): three authorization scopes,
 * enforced by the database, not only by code.
 *
 * - `roles.scope` is exactly `platform`, `school` or `group` (it was free
 *   text; every existing row is `platform` or `school`).
 * - A `group.*` capability key lives in the `group` namespace and nothing
 *   else does.
 * - A role only ever holds capabilities of its own scope's namespace
 *   (role_capabilities trigger): a Group role can never hold a platform or
 *   School capability, and no platform or School role can hold a `group.*`
 *   one. Every existing row already satisfies this (school -> school,
 *   platform -> platform).
 *
 * The two existing assignment-table scope triggers (CLAUDE.md rule 25)
 * are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('platform', 'school', 'group'))");

        DB::statement("ALTER TABLE capabilities ADD CONSTRAINT capabilities_group_namespace_check CHECK ((key LIKE 'group.%') = (namespace = 'group'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_role_capability_scope() RETURNS trigger AS $$
            DECLARE
                role_scope text;
                capability_namespace text;
            BEGIN
                SELECT scope INTO role_scope FROM roles WHERE id = NEW.role_id;
                SELECT namespace INTO capability_namespace FROM capabilities WHERE key = NEW.capability_key;
                IF role_scope IS DISTINCT FROM capability_namespace THEN
                    RAISE EXCEPTION
                        'role_capabilities: a % role cannot hold the % capability % (scopes never mix)',
                        role_scope, capability_namespace, NEW.capability_key;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_role_capabilities_scope
                BEFORE INSERT OR UPDATE ON role_capabilities
                FOR EACH ROW EXECUTE FUNCTION assert_role_capability_scope();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_role_capabilities_scope ON role_capabilities;
            DROP FUNCTION IF EXISTS assert_role_capability_scope();
            SQL);

        DB::statement('ALTER TABLE capabilities DROP CONSTRAINT IF EXISTS capabilities_group_namespace_check');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_scope_check');
    }
};
