<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10D -- one independently assignable physical Bed belonging
     * to exactly one Room (docs/modules/HOSTEL.md "HostelBed model").
     * Deliberately carries NO `student_id`/`current_occupant_id`
     * column -- current occupancy is always derived from the active
     * `hostel_residency_assignments` row referencing this Bed, never
     * duplicated here (the exact same "avoid a second source of
     * truth" discipline `visitor_visits`'s absence of a
     * `visitors.current_visit_id` column already established).
     *
     * `code` is unique per Room, not per School or per Hostel -- two
     * different Rooms may both have a "Bed 1".
     */
    public function up(): void
    {
        Schema::create('hostel_beds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('hostel_room_id');
            $table->string('code', 64);
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['hostel_room_id', 'code']);
            $table->index(['school_id', 'status']);

            $table->foreign(['hostel_room_id', 'school_id'])
                ->references(['id', 'school_id'])->on('hostel_rooms')
                ->restrictOnDelete();
        });

        TenantRls::enable('hostel_beds');
    }

    public function down(): void
    {
        TenantRls::disable('hostel_beds');
        Schema::dropIfExists('hostel_beds');
    }
};
