<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.9 -- a School's own quiet-hours window for a secondary
     * transport channel. This table stores EXPLICIT policy rows only;
     * a School with no row (or `enabled = false`) sends immediately,
     * exactly like the pre-5A.9 behavior -- mirrors
     * communication_channel_policies' own "no row = system default"
     * shape (Phase 5A.5).
     *
     * `channel` is CHECK-restricted to `email` -- the only channel a
     * quiet-hours window is meaningful for today (IN_APP is always
     * canonical/immediate, brief §5; SMS/WhatsApp/Push have no driver
     * yet) -- widened additively later, same pattern
     * communication_channel_policies already established.
     *
     * `quiet_hours_start`/`quiet_hours_end` are School-LOCAL
     * wall-clock times (interpreted against the School's own
     * `schools.timezone` at evaluation time via
     * App\Support\Tenancy\SchoolTimezone) -- never stored as UTC,
     * since a fixed UTC offset would silently drift across a DST
     * transition. See docs/communication-hub/PHASE-5A-9-QUIET-HOURS-DELIVERY-TIMING.md.
     */
    public function up(): void
    {
        Schema::create('communication_delivery_timing_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('channel');
            $table->boolean('enabled')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'channel']);
            $table->index('school_id');
        });

        DB::statement(
            'ALTER TABLE communication_delivery_timing_policies ADD CONSTRAINT communication_delivery_timing_policies_channel_check '.
            "CHECK (channel IN ('email'))"
        );

        TenantRls::enable('communication_delivery_timing_policies');
    }

    public function down(): void
    {
        TenantRls::disable('communication_delivery_timing_policies');
        Schema::dropIfExists('communication_delivery_timing_policies');
    }
};
