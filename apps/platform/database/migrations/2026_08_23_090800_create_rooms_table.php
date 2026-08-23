<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D sections 34-36: Campus-owned learning spaces.
     * `campus_id` is composite-FK-protected against `campuses(id,
     * school_id)` -- a School A Room can never reference a School B
     * Campus, structurally (section 36).
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id');
            $table->string('name');
            $table->string('code');
            $table->string('room_type')->default('classroom'); // classroom|laboratory|auditorium|library|sports|other
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'campus_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index(['campus_id']);

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();
        });

        TenantRls::enable('rooms');
    }

    public function down(): void
    {
        TenantRls::disable('rooms');
        Schema::dropIfExists('rooms');
    }
};
