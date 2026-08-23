<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D section 11: platform/central reference catalog, NOT
     * School-owned data -- a School references a board, but does not
     * own the board's identity, the same architectural shape as
     * `capabilities`/`roles` (central catalogs seeded once, referenced
     * by every tenant). Carries no `school_id` and no RLS -- meaningless
     * for a table every School reads the same rows from.
     *
     * Deliberately does NOT attempt to model the entire Indian
     * education-board landscape as an enum -- `code` is an open string
     * so a future custom/local board can be added without a schema
     * migration (see docs/modules/ACADEMIC-STRUCTURE.md's "Board /
     * Curriculum decision").
     */
    public function up(): void
    {
        Schema::create('education_boards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_boards');
    }
};
