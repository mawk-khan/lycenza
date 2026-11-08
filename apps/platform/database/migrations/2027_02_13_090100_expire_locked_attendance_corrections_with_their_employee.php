<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C18 (WO-6): a locked-month correction request is Staff Attendance evidence, so it leaves
 * with its Employee in the existing unit (`staff_attendance_evidence`, D9), no new period. The
 * function is patched in place against its live body (rule 146): its identity, hold and anchor
 * checks stay. Requests go after their correction rows (which reference them) and before their
 * records (which they reference).
 */
return new class extends Migration
{
    private const FUNCTION = 'public.retention_expire_staff_attendance_employee_evidence(uuid,uuid,date,boolean)';

    /** @var list<array{0: string, 1: string}> exact old -> new fragments of the live body */
    private const PATCHES = [
        [
            "AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)) AND created_at >= v_at) THEN\n",
            "AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)) AND created_at >= v_at)\n"
            ."       OR EXISTS (SELECT 1 FROM public.staff_attendance_locked_corrections WHERE school_id = p_school_id\n"
            ."                   AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)) AND retention_recorded_at >= v_at) THEN\n",
        ],
        [
            "                 AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)))\n          INTO v_count;\n",
            "                 AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)))\n"
            ."             + (SELECT count(*) FROM public.staff_attendance_locked_corrections WHERE school_id = p_school_id\n"
            ."                 AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records)))\n          INTO v_count;\n",
        ],
        [
            "    DELETE FROM public.staff_attendance_records WHERE school_id = p_school_id AND id = ANY (v_attendance);\n",
            "    -- HR.R1-C18: locked-month requests, after the correction rows that name them.\n"
            ."    DELETE FROM public.staff_attendance_locked_corrections WHERE school_id = p_school_id\n"
            ."       AND (staff_attendance_record_id = ANY (v_attendance) OR employment_record_id = ANY (v_records));\n"
            ."    GET DIAGNOSTICS v_step = ROW_COUNT;\n"
            ."    v_count := v_count + v_step;\n"
            ."    DELETE FROM public.staff_attendance_records WHERE school_id = p_school_id AND id = ANY (v_attendance);\n",
        ],
        [
            "       OR EXISTS (SELECT 1 FROM public.staff_attendance_corrections WHERE school_id = p_school_id AND employment_record_id = ANY (v_records)) THEN\n",
            "       OR EXISTS (SELECT 1 FROM public.staff_attendance_corrections WHERE school_id = p_school_id AND employment_record_id = ANY (v_records))\n"
            ."       OR EXISTS (SELECT 1 FROM public.staff_attendance_locked_corrections WHERE school_id = p_school_id AND employment_record_id = ANY (v_records)) THEN\n",
        ],
    ];

    public function up(): void
    {
        $this->patch(forward: true);
    }

    public function down(): void
    {
        $this->patch(forward: false);
    }

    private function patch(bool $forward): void
    {
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('".self::FUNCTION."'::regprocedure) AS d")->d;
        foreach (self::PATCHES as [$old, $new]) {
            [$from, $to] = $forward ? [$old, $new] : [$new, $old];
            if (substr_count($definition, $from) !== 1) {
                throw new RuntimeException('retention_expire_staff_attendance_employee_evidence: unexpected body shape; refusing to change it.');
            }
            $definition = str_replace($from, $to, $definition);
        }
        DB::unprepared($definition);
    }
};
