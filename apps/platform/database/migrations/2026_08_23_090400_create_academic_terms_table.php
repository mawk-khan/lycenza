<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D sections 19-21. `academic_year_id` is protected by a
     * COMPOSITE foreign key against `academic_years(id, school_id)`
     * (section 36) -- a School A term row can never reference a School
     * B academic year, structurally, not just by application
     * convention. Term-within-year date bounds (section 20) and
     * non-overlap between terms of the same year (section 21) are
     * enforced at the application layer
     * (App\Domain\AcademicStructure\Application\CreateAcademicTerm) --
     * the same "application + tested DB strategy" tradeoff as Academic
     * Year overlap (see that migration's docblock).
     */
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_year_id');
            $table->string('name');
            $table->string('code');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('sequence');
            $table->timestamps();

            $table->unique(['school_id', 'academic_year_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index(['academic_year_id']);

            $table->foreign(['academic_year_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_years')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE academic_terms ADD CONSTRAINT academic_terms_date_range_check CHECK (starts_on < ends_on)');

        TenantRls::enable('academic_terms');
    }

    public function down(): void
    {
        TenantRls::disable('academic_terms');
        Schema::dropIfExists('academic_terms');
    }
};
