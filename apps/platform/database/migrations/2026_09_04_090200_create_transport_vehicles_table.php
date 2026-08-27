<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10B -- a School Transport vehicle. `campus_id` is nullable/
     * optional, same reasoning as `transport_routes.campus_id`. `code`
     * (unique per School, NormalizesCode/NormalizesCodeInput reused
     * verbatim) is the School's own internal identifier;
     * `registration_number` (also unique per School) is the real-world
     * government-issued plate -- kept as a separate field because it
     * is reference data a School did not choose, not an internal code
     * a School assigns.
     *
     * `capacity` is informational/reporting only in this checkpoint
     * (docs/modules/TRANSPORT.md "Vehicle capacity invariant") -- no
     * seat-count enforcement against `transport_student_assignments`
     * is implemented; storing it now costs nothing and avoids a future
     * migration if reporting needs it, but adding real enforcement
     * would need its own concurrency-safe design and is explicitly
     * deferred (checkpoint brief section 10's explicit permission).
     *
     * Deliberately no fuel/service/insurance/tyre/GPS fields -- see
     * docs/modules/TRANSPORT.md "Explicit non-scope".
     */
    public function up(): void
    {
        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id')->nullable();
            $table->string('code');
            $table->string('registration_number');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables composite FKs from transport_route_assignments
            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'registration_number']);
            $table->index(['campus_id']);
            $table->index(['status']);

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();
        });

        TenantRls::enable('transport_vehicles');
    }

    public function down(): void
    {
        TenantRls::disable('transport_vehicles');
        Schema::dropIfExists('transport_vehicles');
    }
};
