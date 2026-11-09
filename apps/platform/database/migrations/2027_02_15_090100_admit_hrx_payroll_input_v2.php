<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C22 (WO-5): the HRX evidence contract becomes `hrx_payroll_input.v2` (a half before the School's
 * calendar history start is `calendar_not_recorded`, with a null working flag, and names its calendar
 * version). Existing v1 snapshots stay exactly as they are (immutable evidence); the shape CHECK now admits
 * both versions and nothing else. The live definition is transformed (rule 146): an unexpected shape refuses.
 */
return new class extends Migration
{
    private const V1 = "((contract_version)::text = 'hrx_payroll_input.v1'::text)";

    // PostgreSQL's own rendering of the admitted pair, so `down()` finds exactly what `up()` wrote.
    private const BOTH = "((contract_version)::text = ANY (ARRAY[('hrx_payroll_input.v1'::character varying)::text, ('hrx_payroll_input.v2'::character varying)::text]))";

    public function up(): void
    {
        $this->replace(self::V1, self::BOTH);
    }

    public function down(): void
    {
        // PAY-15, rule 143: lifted inside this transaction, so the check sees every School's rows.
        DB::statement('ALTER TABLE payroll_run_hrx_inputs NO FORCE ROW LEVEL SECURITY');
        DB::statement('LOCK TABLE payroll_run_hrx_inputs IN ACCESS EXCLUSIVE MODE');
        if (DB::table('payroll_run_hrx_inputs')->where('contract_version', '<>', 'hrx_payroll_input.v1')->exists()) {
            throw new RuntimeException('Refusing to roll back: v2 HRX evidence exists and is immutable (HR.R1-C22). Fix forward.');
        }
        DB::statement('ALTER TABLE payroll_run_hrx_inputs FORCE ROW LEVEL SECURITY');
        $this->replace(self::BOTH, self::V1);
    }

    private function replace(string $from, string $to): void
    {
        DB::statement('LOCK TABLE payroll_run_hrx_inputs IN ACCESS EXCLUSIVE MODE');
        $definition = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conrelid = 'payroll_run_hrx_inputs'::regclass AND conname = 'payroll_run_hrx_inputs_shape_check'")->d;
        if (substr_count($definition, $from) !== 1) {
            throw new RuntimeException('payroll_run_hrx_inputs_shape_check has an unexpected shape; refusing to rewrite it.');
        }
        DB::statement('ALTER TABLE payroll_run_hrx_inputs DROP CONSTRAINT payroll_run_hrx_inputs_shape_check');
        DB::statement('ALTER TABLE payroll_run_hrx_inputs ADD CONSTRAINT payroll_run_hrx_inputs_shape_check '.str_replace($from, $to, $definition));
    }
};
