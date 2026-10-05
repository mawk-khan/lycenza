<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.6 (ADR 0066 §14): the last four legacy retention functions move to
 * the dedicated retention identity, exactly as the Payroll and LMS unit
 * functions did in E21-RH.5 (2026_11_29_090000):
 * `retention_expire_finance_unit`,
 * `retention_expire_student_processing_authorizations`,
 * `retention_expire_student_consent_events` and
 * `retention_expire_guardian_consent_events`.
 *
 * - EXECUTE moves from the runtime role to `school_os_retention` (PUBLIC
 *   stays revoked; owner and SECURITY DEFINER unchanged). After this no
 *   destructive retention function is executable by the runtime role.
 * - A prologue is injected right after BEGIN, before every existing check:
 *   `retention_assert_retention_identity()` (session_user must be exactly
 *   `school_os_retention`) and `retention_assert_not_held(p_school_id)`
 *   UNCONDITIONALLY, dry runs included: these are unit functions validated
 *   inside the destructive transaction (the Finance dry run is its own
 *   transaction and simply refuses under a hold).
 * - Every existing floor, lock, dependency and accounting check is left
 *   byte for byte.
 *
 * Rollback removes exactly the prologue and the retention EXECUTE and
 * deliberately does NOT re-grant the runtime role (fail closed: owner only).
 */
return new class extends Migration
{
    private const FUNCTIONS = [
        'retention_expire_finance_unit',
        'retention_expire_student_processing_authorizations',
        'retention_expire_student_consent_events',
        'retention_expire_guardian_consent_events',
    ];

    private const ROLE = 'school_os_retention';

    public function up(): void
    {
        foreach (self::FUNCTIONS as $function) {
            $definition = $this->definition($function);
            if (substr_count($definition, "\nBEGIN\n") !== 1 || str_contains($definition, 'retention_assert_retention_identity')) {
                throw new RuntimeException("{$function}: unexpected body shape; refusing to inject the RH.6 prologue.");
            }
            DB::unprepared(str_replace("\nBEGIN\n", "\nBEGIN\n".self::prologue(), $definition));

            $signature = $this->signature($function);
            DB::statement("REVOKE ALL ON FUNCTION {$function}({$signature}) FROM PUBLIC");
            DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$signature}) FROM school_os_app");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function}({$signature}) TO ".self::ROLE);
        }
    }

    public function down(): void
    {
        foreach (self::FUNCTIONS as $function) {
            $definition = $this->definition($function);
            $injected = "\nBEGIN\n".self::prologue();
            if (substr_count($definition, $injected) !== 1) {
                throw new RuntimeException("{$function}: the RH.6 prologue is not present exactly once; refusing to roll back.");
            }
            DB::unprepared(str_replace($injected, "\nBEGIN\n", $definition));

            // Deliberately no re-grant to the runtime role (see the class docblock).
            DB::statement("REVOKE EXECUTE ON FUNCTION {$function}({$this->signature($function)}) FROM ".self::ROLE);
        }
    }

    private static function prologue(): string
    {
        return "    -- E21-RH.6 (ADR 0066): only the retention identity; nothing, not even a dry-run validation, while a retention hold is active.\n"
            ."    PERFORM public.retention_assert_retention_identity();\n"
            ."    PERFORM public.retention_assert_not_held(p_school_id);\n";
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
