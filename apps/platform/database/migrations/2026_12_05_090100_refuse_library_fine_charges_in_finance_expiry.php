<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OPF.4 (ADR 0067 §21, §30): `library_fines` references `charges`, so the
 * Finance D8 unit must name it, exactly as it names `canteen_orders`: a unit
 * whose charge a Library fine still references is refused as
 * `retention_finance_dependency` (the caller's clean `dependency_blocked`),
 * instead of failing on the foreign key at delete time. The fine is Finance
 * evidence kept with its charge; no unit deletes it in OPF.4, as none deletes
 * a Canteen order.
 *
 * A forward amendment of the one existing statement, in place (E21-RH: the
 * function, its RH.7 prologue, owner and grants are otherwise unchanged); it
 * refuses to run against an unexpected body. Rollback restores the statement.
 */
return new class extends Migration
{
    private const FROM = 'IF EXISTS (SELECT 1 FROM public.canteen_orders WHERE school_id = p_school_id AND charge_id = ANY (v_charges))';

    private const TO = 'IF EXISTS (SELECT 1 FROM public.canteen_orders WHERE school_id = p_school_id AND charge_id = ANY (v_charges))'
        ."\n       OR EXISTS (SELECT 1 FROM public.library_fines WHERE school_id = p_school_id AND charge_id = ANY (v_charges))";

    public function up(): void
    {
        $this->replaceInFunction('retention_expire_finance_unit', self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->replaceInFunction('retention_expire_finance_unit', self::TO, self::FROM);
    }

    private function replaceInFunction(string $function, string $from, string $to): void
    {
        $definition = (string) DB::selectOne(
            "SELECT pg_get_functiondef(p.oid) AS d FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?",
            [$function],
        )->d;
        if (substr_count($definition, $from) !== 1 || ($from !== self::TO && str_contains($definition, 'public.library_fines'))) {
            throw new RuntimeException("{$function}: unexpected body shape; refusing to change it.");
        }
        DB::unprepared(str_replace($from, $to, $definition));
    }
};
