<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.4 (ADR 0066 §3.2, §9, §12): the eleven STANDALONE legacy retention
 * functions move to the dedicated retention identity.
 *
 * Standalone means each is called on its own by RetentionExpiry::run() --
 * autocommit batches, no surrounding runtime transaction, no external lock,
 * no runtime-role writes around it (re-audited in RH.4). The coupled ones
 * (Payroll evidence/run, Learning content, Assignment: RH.5; Finance unit,
 * Student processing authorizations, Student and Guardian consent events:
 * RH.6) are NOT touched.
 *
 * For each function below:
 * - EXECUTE moves from the runtime role to `school_os_retention`
 *   (PUBLIC stays revoked; owner and SECURITY DEFINER unchanged);
 * - a prologue is injected right after BEGIN, before every existing check:
 *   - `retention_assert_retention_identity()`: the authenticated login
 *     (`session_user`) must be exactly `school_os_retention` -- never
 *     current_user, a membership or a client setting (`retention_privilege`);
 *   - for a destructive call (`NOT p_dry_run`), `retention_assert_not_held()`
 *     (E21-RH.3): no active platform hold, and -- for a School-scoped
 *     function -- no active hold of `p_school_id` (`retention_hold`). A dry
 *     run still counts under a hold; it deletes nothing.
 *   Every existing tenant check, floor, predicate, dependency check, batch
 *   limit and SKIP LOCKED is left byte for byte as it was.
 *
 * Rollback removes exactly the injected prologue and the retention
 * identity's EXECUTE. It deliberately does NOT re-grant EXECUTE to the
 * runtime role (an unsafe privilege is never restored by a rollback): after
 * a rollback only the owner can run them, so destructive retention for these
 * categories fails closed until migrated again.
 */
return new class extends Migration
{
    /** @var array<string, bool> function => School-scoped */
    private const FUNCTIONS = [
        'retention_expire_school_audit_events' => true,
        'retention_expire_membership_role_assignments' => true,
        'retention_expire_teaching_assignments' => true,
        'retention_expire_school_elevations' => true,
        'retention_expire_communication_delivery_policy_decisions' => true,
        'retention_expire_api_client_credentials' => true,
        'retention_expire_platform_audit_events' => false,
        'retention_expire_released_email_suppressions' => false,
        'retention_expire_group_role_assignments' => false,
        'retention_expire_platform_role_assignments' => false,
        'retention_expire_erasure_cases' => false,
    ];

    private const ROLE = 'school_os_retention';

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- E21-RH.4: the one exact retention-caller check for migrated destructive functions.
            CREATE FUNCTION retention_assert_retention_identity() RETURNS void
                LANGUAGE plpgsql STABLE SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF session_user <> 'school_os_retention'::name THEN
                    RAISE EXCEPTION 'retention: not the authorized retention identity (retention_privilege)';
                END IF;
            END;
            $$;
            REVOKE ALL ON FUNCTION retention_assert_retention_identity() FROM PUBLIC;
            SQL);

        foreach (self::FUNCTIONS as $function => $school) {
            $definition = $this->definition($function);
            if (substr_count($definition, "\nBEGIN\n") !== 1 || str_contains($definition, 'retention_assert_retention_identity')) {
                throw new RuntimeException("{$function}: unexpected body shape; refusing to inject the RH.4 prologue.");
            }
            DB::unprepared(str_replace("\nBEGIN\n", "\nBEGIN\n".$this->prologue($school), $definition));

            $signature = $this->signature($function);
            DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
            DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$signature}) FROM school_os_app");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO ".self::ROLE);
        }
    }

    public function down(): void
    {
        foreach (self::FUNCTIONS as $function => $school) {
            $definition = $this->definition($function);
            $injected = "\nBEGIN\n".$this->prologue($school);
            if (substr_count($definition, $injected) !== 1) {
                throw new RuntimeException("{$function}: the RH.4 prologue is not present exactly once; refusing to roll back.");
            }
            DB::unprepared(str_replace($injected, "\nBEGIN\n", $definition));

            // Deliberately no re-grant to the runtime role (see the class docblock).
            DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$this->signature($function)}) FROM ".self::ROLE);
        }

        DB::statement('DROP FUNCTION retention_assert_retention_identity()');
    }

    private function prologue(bool $school): string
    {
        $scope = $school ? 'p_school_id' : 'NULL';

        return "    -- E21-RH.4 (ADR 0066): only the retention identity; nothing destructive while a retention hold is active.\n"
            ."    PERFORM public.retention_assert_retention_identity();\n"
            ."    IF NOT p_dry_run THEN\n"
            ."        PERFORM public.retention_assert_not_held({$scope});\n"
            ."    END IF;\n";
    }

    private function definition(string $function): string
    {
        return (string) DB::selectOne(
            "SELECT pg_get_functiondef(p.oid) AS d FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$function],
        )->d;
    }

    private function signature(string $function): string
    {
        return (string) DB::selectOne(
            "SELECT pg_get_function_identity_arguments(p.oid) AS s FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$function],
        )->s;
    }
};
