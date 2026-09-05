<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 0H.4D-P2: registers the `students.processing_authorizations`
     * feature-flag catalog entry, default-off. The backend/capabilities
     * may publish while a School's UI/functionality stays disabled
     * until that School is explicitly ready (App\Support\FeatureFlags\
     * FeatureFlagResolver::isEnabledForSchool()) -- this is a product
     * visibility switch, never a substitute for the capability+mfa
     * authorization gate on the routes themselves (FeatureFlagResolver's
     * own docblock: "a flag hides/disables product capability -- it is
     * NEVER an authorization check").
     */
    public function up(): void
    {
        DB::table('feature_flags')->insert([
            'key' => 'students.processing_authorizations',
            'label' => 'Student Processing Authorization Registry',
            'description' => 'Staff-facing recording/withdrawal of Student data-processing authorization (Guardian consent, adult Student consent, statutory School purpose) -- a Phase 0H.4D-P2 platform prerequisite for the future StudentMark checkpoint.',
            'default_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'students.processing_authorizations')->delete();
    }
};
