<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10B -- the historical Route <-> Vehicle <-> Driver
     * operational configuration (docs/modules/TRANSPORT.md "Route
     * operational assignment decision"). Deliberately NOT
     * `vehicle_id`/`driver_employee_id` columns bolted directly onto
     * `transport_routes` -- the checkpoint brief explicitly warns
     * against that when the relationship is expected to change over
     * time, and it is: a School reassigns vehicles/drivers to routes
     * routinely, and rewriting `transport_routes` in place would
     * silently destroy the historical record of who drove which
     * vehicle on which route, when.
     *
     * `vehicle_id` and `driver_employee_id` are BOTH required (not
     * nullable): an assignment row represents a COMPLETE vehicle+driver
     * configuration for a Route, not a partial state -- this checkpoint
     * does not model "vehicle assigned but driver still pending" as a
     * distinct, queryable state. All three FKs (route/vehicle/driver)
     * are restrict-on-delete -- none of the three parent tables expose
     * a delete endpoint, so this is defensive-only, matching the exact
     * precedent every other composite FK in this codebase without a
     * true ownership relationship uses.
     *
     * ONE active assignment per Route at a time -- a partial unique
     * index, the exact `library_loans_one_active_per_copy` pattern
     * applied to a structurally identical problem (a Route cannot
     * simultaneously have two "current" vehicle/driver configurations).
     * Deliberately NOT the mirror constraint (one active assignment per
     * Vehicle) -- a School may legitimately run the same physical
     * vehicle on two different Routes at different times of day
     * without this checkpoint modeling a full session/timetable engine
     * (checkpoint brief section 7's explicit permission to avoid that
     * complexity).
     */
    public function up(): void
    {
        Schema::create('transport_route_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('route_id');
            $table->uuid('vehicle_id');
            $table->uuid('driver_employee_id');
            $table->string('status')->default('active'); // active|ended
            $table->timestamp('starts_on');
            $table->timestamp('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['route_id']);
            $table->index(['vehicle_id']);
            $table->index(['driver_employee_id']);
            $table->index(['status']);

            $table->foreign(['route_id', 'school_id'])
                ->references(['id', 'school_id'])->on('transport_routes')
                ->restrictOnDelete();

            $table->foreign(['vehicle_id', 'school_id'])
                ->references(['id', 'school_id'])->on('transport_vehicles')
                ->restrictOnDelete();

            $table->foreign(['driver_employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE transport_route_assignments ADD CONSTRAINT transport_route_assignments_ends_after_starts_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement('ALTER TABLE transport_route_assignments ADD CONSTRAINT transport_route_assignments_status_end_consistency_check CHECK ((status = \'ended\') = (ends_on IS NOT NULL))');
        DB::statement(
            'CREATE UNIQUE INDEX transport_route_assignments_one_active_per_route '.
            "ON transport_route_assignments (route_id) WHERE status = 'active'"
        );

        TenantRls::enable('transport_route_assignments');
    }

    public function down(): void
    {
        TenantRls::disable('transport_route_assignments');
        Schema::dropIfExists('transport_route_assignments');
    }
};
