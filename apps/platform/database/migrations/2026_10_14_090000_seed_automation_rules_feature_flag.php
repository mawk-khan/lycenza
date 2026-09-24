<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 0L.6: registers the School-wide `automation.rules` rollout flag,
     * default OFF (ADR 0043 amendment, 2026-09-24). Automation executes
     * nothing for a School unless its override enables this flag. A product
     * visibility switch only -- never an authorization check
     * (FeatureFlagResolver's docblock); `automation.view`/`automation.manage`
     * remain the authorization.
     */
    public function up(): void
    {
        DB::table('feature_flags')->insert([
            'key' => 'automation.rules',
            'label' => 'Automation rules',
            'description' => 'School-wide opt-in for Automation (ADR 0043): while off, no Automation rule executes for the School, whatever each rule instance says.',
            'default_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'automation.rules')->delete();
    }
};
