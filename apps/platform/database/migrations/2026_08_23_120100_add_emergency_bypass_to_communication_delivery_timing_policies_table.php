<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.10 -- per-School, per-channel opt-in allowing an
     * explicitly-declared Emergency communication to bypass that
     * channel's quiet-hours window. `default(false)` is mandatory
     * (brief §37): deploying this checkpoint must not cause any
     * existing School to begin bypassing its own configured quiet
     * hours. No migration ever sets this true for an existing row --
     * only an explicit School-admin write via
     * App\Domain\Communications\Application\Policy\SchoolDeliveryTimingPolicyService::setPolicy()
     * does.
     */
    public function up(): void
    {
        Schema::table('communication_delivery_timing_policies', function (Blueprint $table) {
            $table->boolean('emergency_bypass_allowed')->default(false)->after('quiet_hours_end');
        });
    }

    public function down(): void
    {
        Schema::table('communication_delivery_timing_policies', function (Blueprint $table) {
            $table->dropColumn('emergency_bypass_allowed');
        });
    }
};
