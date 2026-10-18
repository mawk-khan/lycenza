<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.5 (ADR 0076 §13, §31): retires the `students.processing_authorizations`
     * feature flag. It was seeded default-off by
     * 2026_10_12_090200_seed_student_processing_authorizations_feature_flag
     * but no code ever read it (ADR 0038 drift note, RES.0B; ADR 0076 §13):
     * the registry is gated by its capabilities plus MFA, and its
     * availability is now the core `student_records` module. Removing the
     * catalogue row cascades any per-School override row (the FK is
     * ON DELETE CASCADE); such rows never had an effect.
     *
     * down() restores the catalogue row exactly as seeded. Per-School
     * overrides are not recreated -- deliberately: they were inert, and no
     * reader exists to which their absence could matter. The
     * `students.processing_authorizations.*` CAPABILITIES are untouched.
     */
    public function up(): void
    {
        DB::table('feature_flags')->where('key', 'students.processing_authorizations')->delete();
    }

    public function down(): void
    {
        DB::table('feature_flags')->insertOrIgnore([
            'key' => 'students.processing_authorizations',
            'label' => 'Student Processing Authorization Registry',
            'description' => 'Staff-facing recording/withdrawal of Student data-processing authorization (Guardian consent, adult Student consent, statutory School purpose) -- a Phase 0H.4D-P2 platform prerequisite for the future StudentMark checkpoint.',
            'default_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
