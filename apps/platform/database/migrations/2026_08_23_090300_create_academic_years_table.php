<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D sections 13-16: School-owned tenant data. `starts_on`/
     * `ends_on` are plain `date` columns (section 67) -- an academic
     * year boundary is a local calendar date, never a UTC timestamp.
     *
     * Two constraints enforced by PostgreSQL itself, not application
     * code alone (section 14/16):
     *
     * - A CHECK constraint that `starts_on < ends_on` -- no assumption
     *   about calendar convention (Jan-Dec/Apr-Mar/Jun-May), just that
     *   the range itself is non-empty and correctly ordered.
     * - A partial unique index on `school_id` WHERE `status = 'active'`
     *   -- the actual database-enforced "only one active Academic Year
     *   per School" guarantee (section 16). This is what makes
     *   concurrent activation attempts race safely: two transactions
     *   both trying to set a row `active` for the same School cannot
     *   both commit; PostgreSQL's unique index enforcement is what
     *   rejects the loser, not an application-level check-then-update
     *   (see App\Domain\AcademicStructure\Application\ActivateAcademicYear
     *   and its real-concurrency test).
     *
     * Overlap between two Academic Years of the same School is
     * rejected at the APPLICATION layer (CreateAcademicYear), not via a
     * PostgreSQL exclusion/range constraint -- the added complexity of
     * a `daterange` generated column + `EXCLUDE USING gist` was judged
     * not worth it for reference data with a low write rate; see
     * docs/modules/ACADEMIC-STRUCTURE.md ("Academic Year overlap").
     */
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft'); // draft|active|closed|archived
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index('school_id');
        });

        DB::statement('ALTER TABLE academic_years ADD CONSTRAINT academic_years_date_range_check CHECK (starts_on < ends_on)');
        DB::statement("CREATE UNIQUE INDEX academic_years_one_active_per_school ON academic_years (school_id) WHERE status = 'active'");

        TenantRls::enable('academic_years');
    }

    public function down(): void
    {
        TenantRls::disable('academic_years');
        Schema::dropIfExists('academic_years');
    }
};
