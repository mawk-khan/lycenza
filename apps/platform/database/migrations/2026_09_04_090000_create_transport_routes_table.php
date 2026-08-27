<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10B -- the Transport module's reusable route definition.
     * See docs/modules/TRANSPORT.md ("Campus ownership decision") for
     * the full reasoning; summary: `campus_id` is nullable/optional,
     * exactly mirroring `library_copies.campus_id` -- a Route is the
     * kind of thing that MAY serve one specific Campus in a
     * multi-campus School, or may be School-wide shared Transport. A
     * single-campus School simply leaves it null; nothing structurally
     * prevents a School from running shared Transport across Campuses,
     * per the checkpoint brief's explicit requirement.
     *
     * `code` uses the existing `App\Support\NormalizesCode`/
     * `NormalizesCodeInput` traits verbatim (unique per School), the
     * same reuse Library's `library_copies.code` already established.
     * Deliberately no routing-engine/GPS geometry data -- see
     * docs/modules/TRANSPORT.md "Explicit non-scope".
     *
     * Reference-entity lifecycle (rule 73): active/inactive, no delete
     * endpoint -- a `transport_route_assignments` or
     * `transport_student_assignments` row may reference this Route
     * historically even after it is deactivated.
     */
    public function up(): void
    {
        Schema::create('transport_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables composite FKs from transport_stops/transport_route_assignments
            $table->unique(['school_id', 'code']);
            $table->index(['campus_id']);
            $table->index(['status']);

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();
        });

        TenantRls::enable('transport_routes');
    }

    public function down(): void
    {
        TenantRls::disable('transport_routes');
        Schema::dropIfExists('transport_routes');
    }
};
