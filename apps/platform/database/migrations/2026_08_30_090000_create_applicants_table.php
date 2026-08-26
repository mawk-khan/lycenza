<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1D.1 -- an Applicant is a pre-Student identity, owned
     * entirely by Admissions (docs/modules/ADMISSIONS.md §2/§3/§4).
     * Deliberately mirrors `students`' own identity field shape exactly
     * (name/date_of_birth only) -- these are the literal facts copied
     * verbatim into `Student` at conversion (a future checkpoint, not
     * this one). No contact fields (email/phone) on this table --
     * ADMISSIONS.md §9 (hardened at 1D.0A) explains why Admissions
     * stores no guardian/applicant contact data of any kind in v1.
     *
     * `unique(['id', 'school_id'])` is added now so
     * `admission_applications` (this same checkpoint) and any future
     * Admissions-owned child table can reference `(id, school_id)`
     * exactly like every other tenant-owned parent table in this
     * codebase (Phase 0B/0C.3/0D's established pattern) -- see
     * `students`' own migration for the identical precedent.
     */
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('date_of_birth');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
        });

        TenantRls::enable('applicants');
    }

    public function down(): void
    {
        TenantRls::disable('applicants');
        Schema::dropIfExists('applicants');
    }
};
