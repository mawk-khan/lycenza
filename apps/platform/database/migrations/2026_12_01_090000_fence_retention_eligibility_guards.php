<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.7 (ADR 0066 §15): the rollback fence for the E21-RH.6 eligibility
 * guards (2026_11_30_090100).
 *
 * Owner decision (2026-10-05): security eligibility guards fail closed on
 * rollback. 2026_11_30_090100 is published with a reversible `down()` that
 * drops its guards -- rolling it back would knowingly give `school_os_app`
 * back the ability to manufacture retention eligibility (backdate an
 * employment or enrollment end, reopen and re-close it, backdate an erasure
 * case, age a Guardian or legacy terminal application). Published migration
 * history is not rewritten; instead this migration sits after it:
 *
 * - `up()` verifies the RH.6 protections are present and enabled, and
 *   refuses to record itself otherwise (a fence over a missing guard would
 *   be a false assurance);
 * - `down()` always refuses. `migrate:rollback` stops here, so it can never
 *   reach 2026_11_30_090100's `down()` (nor any earlier security slice).
 *
 * Operator recovery (docs/operations/DATABASE-BOOTSTRAP.md, "Retention
 * security fences"): there is no routine rollback. A defective guard is
 * replaced by a reviewed FORWARD migration (with an ADR 0066 amendment) that
 * installs its corrected successor in the same transaction. Disabling a
 * guard by hand (owner session, `ALTER TABLE ... DISABLE TRIGGER`) is an
 * incident, never maintenance: `platform:verify-database` then fails
 * (`retention_eligibility_guards`, `retention_rollback_fences`).
 * `migrate:fresh` (test and development resets) drops tables rather than
 * running `down()`, and is unaffected.
 */
return new class extends Migration
{
    /** table => trigger: the RH.6 eligibility guards. */
    private const TRIGGERS = [
        'employment_records' => 'trg_employment_records_eligibility_guard',
        'student_enrollments' => 'trg_student_enrollments_eligibility_guard',
        'erasure_cases' => 'trg_erasure_cases_guard_transition',
        'guardians' => 'guardians_guard_no_relationship_since',
        'admission_applications' => 'admission_applications_guard_terminal_at',
    ];

    /** function => fragment its body must contain (the RH.6 owner-only marker backfill). */
    private const BODIES = [
        'guardians_guard_no_relationship_since' => 'pg_has_role',
        'admission_applications_guard_terminal_at' => 'pg_has_role',
        'retention_guard_end_record' => 'retention_eligibility_guard',
        'erasure_cases_guard_transition' => 'retention_eligibility_guard',
    ];

    public function up(): void
    {
        $missing = [];
        foreach (self::TRIGGERS as $table => $trigger) {
            $enabled = DB::selectOne(
                "SELECT t.tgenabled FROM pg_trigger t WHERE t.tgrelid = ('public.'||?)::regclass AND t.tgname = ? AND NOT t.tgisinternal",
                [$table, $trigger],
            )?->tgenabled;
            if ($enabled !== 'O' && $enabled !== 'A') {
                $missing[] = "{$table}.{$trigger}";
            }
        }
        foreach (self::BODIES as $function => $fragment) {
            $body = DB::selectOne("SELECT p.prosrc FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname = ?", [$function])?->prosrc;
            if (! is_string($body) || ! str_contains($body, $fragment)) {
                $missing[] = "{$function}()";
            }
        }
        foreach (['employment_records', 'student_enrollments'] as $table) {
            if (! DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = ('public.'||?)::regclass AND attname = 'ended_recorded_at' AND NOT attisdropped) AS x", [$table])->x) {
                $missing[] = "{$table}.ended_recorded_at";
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('E21-RH.7 fence: the RH.6 eligibility protections are not all in place ('.implode(', ', $missing).'); refusing to fence an unsafe state.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'E21-RH.7 (ADR 0066 §15): the retention eligibility guards are a security fence and are deliberately irreversible. '
            .'Rolling back would let the runtime role manufacture retention eligibility again. Replace a defective guard with a reviewed forward migration '
            .'(docs/operations/DATABASE-BOOTSTRAP.md, "Retention security fences").'
        );
    }
};
