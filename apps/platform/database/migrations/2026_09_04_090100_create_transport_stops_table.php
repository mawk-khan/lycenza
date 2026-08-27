<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10B -- an ordered pickup/drop-off point belonging to
     * exactly one Route. `sequence` follows GradeLevel's existing
     * explicit-ordering convention (rule 67 -- pedagogical/operational
     * order cannot be inferred from a name string), unique per Route
     * so two Stops can never claim the identical ordering position.
     *
     * `unique(['id', 'route_id', 'school_id'])`, IN ADDITION to the
     * standard `unique(['id', 'school_id'])`, is what makes
     * docs/modules/TRANSPORT.md's "Stop integrity" invariant
     * database-enforceable rather than application-only
     * (checkpoint brief section 13): `transport_student_assignments`'
     * pickup/dropoff Stop references are composite FKs against THIS
     * 3-column unique, `(stop_id, route_id, school_id) ->
     * transport_stops(id, route_id, school_id)` -- so a Stop can only
     * ever be referenced by an assignment that names the SAME
     * `route_id` the Stop actually belongs to. A Stop belonging to
     * Route A referenced by an assignment naming Route B is rejected
     * by PostgreSQL itself, not merely by controller validation.
     */
    public function up(): void
    {
        Schema::create('transport_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('route_id');
            $table->string('name');
            $table->unsignedInteger('sequence');
            $table->text('address')->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['id', 'route_id', 'school_id']); // enables the Stop-belongs-to-Route composite FK from transport_student_assignments
            $table->unique(['route_id', 'sequence']);
            $table->index(['status']);

            $table->foreign(['route_id', 'school_id'])
                ->references(['id', 'school_id'])->on('transport_routes')
                ->restrictOnDelete();
        });

        TenantRls::enable('transport_stops');
    }

    public function down(): void
    {
        TenantRls::disable('transport_stops');
        Schema::dropIfExists('transport_stops');
    }
};
