<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10D -- a Room belonging to exactly one Hostel
     * (docs/modules/HOSTEL.md "HostelRoom model"). Deliberately its
     * own table -- NOT a reuse of Academic Structure's `Room`
     * (`App\Domain\AcademicStructure\Infrastructure\Room`), which is
     * explicitly a teaching space (`room_type: classroom|laboratory|
     * auditorium|library|sports|other`) with no bed/occupancy
     * semantics whatsoever. Overloading it would be exactly the kind
     * of misfit the Phase 10D readiness audit warned against.
     *
     * `code` is unique per Hostel, not per School -- two different
     * Hostels in the same School may both have a "Room 101".
     *
     * No stored `capacity` column -- capacity for this checkpoint is
     * derived from active `hostel_beds` rows belonging to this Room
     * (VISITOR.md-style "avoid a second source of truth" precedent,
     * applied here explicitly per the checkpoint brief). No
     * `HostelBlock` table -- `floor_or_block` is a bounded, optional,
     * purely-textual attribute; a dedicated entity was not justified
     * for this checkpoint's scope.
     */
    public function up(): void
    {
        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('hostel_id');
            $table->string('code', 64);
            $table->string('floor_or_block', 64)->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['hostel_id', 'code']);
            $table->index(['school_id', 'status']);

            $table->foreign(['hostel_id', 'school_id'])
                ->references(['id', 'school_id'])->on('hostels')
                ->restrictOnDelete();
        });

        TenantRls::enable('hostel_rooms');
    }

    public function down(): void
    {
        TenantRls::disable('hostel_rooms');
        Schema::dropIfExists('hostel_rooms');
    }
};
