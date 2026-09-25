<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0N.7 (ADR 0046 section 3): a role may be granted at runtime only
 * if the code catalog marks it `runtime_assignable` -- a role row merely
 * existing is never enough.
 *
 * Database-enforced:
 * - `runtime_assignable` only for SYSTEM PLATFORM roles, and never for the
 *   root role `platform_super_admin` (CHECK);
 * - a runtime-assignable role never holds a ROOT-RESERVED capability --
 *   `platform.role_grants.manage` (platform-role governance) or
 *   `platform.schools.manage` (School creation, D12) -- whether the
 *   capability is attached later or the flag is set later (triggers on
 *   role_capabilities and roles). So no delegation chain can form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('runtime_assignable')->default(false)->after('is_system');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE roles ADD CONSTRAINT roles_runtime_assignable_check
                CHECK (NOT runtime_assignable OR (scope = 'platform' AND is_system AND key <> 'platform_super_admin'))
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_runtime_assignable_role_not_root() RETURNS trigger AS $$
            DECLARE
                assignable boolean;
            BEGIN
                IF TG_TABLE_NAME = 'role_capabilities' THEN
                    SELECT runtime_assignable INTO assignable FROM roles WHERE id = NEW.role_id;
                    IF assignable AND NEW.capability_key IN ('platform.role_grants.manage', 'platform.schools.manage') THEN
                        RAISE EXCEPTION
                            'role_capabilities: a runtime-assignable role cannot hold the root-reserved capability %', NEW.capability_key;
                    END IF;
                ELSIF NEW.runtime_assignable AND EXISTS (
                    SELECT 1 FROM role_capabilities
                    WHERE role_id = NEW.id
                      AND capability_key IN ('platform.role_grants.manage', 'platform.schools.manage')
                ) THEN
                    RAISE EXCEPTION 'roles: a role holding a root-reserved capability cannot be runtime-assignable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_role_capabilities_root_reserved
                BEFORE INSERT OR UPDATE ON role_capabilities
                FOR EACH ROW EXECUTE FUNCTION assert_runtime_assignable_role_not_root();

            CREATE TRIGGER trg_roles_runtime_assignable_root_reserved
                BEFORE INSERT OR UPDATE OF runtime_assignable ON roles
                FOR EACH ROW EXECUTE FUNCTION assert_runtime_assignable_role_not_root();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_roles_runtime_assignable_root_reserved ON roles;
            DROP TRIGGER IF EXISTS trg_role_capabilities_root_reserved ON role_capabilities;
            DROP FUNCTION IF EXISTS assert_runtime_assignable_role_not_root();
            SQL);

        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_runtime_assignable_check');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('runtime_assignable');
        });
    }
};
