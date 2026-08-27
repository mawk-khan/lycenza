<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10A -- one row per individually loanable PHYSICAL object
     * (see docs/modules/LIBRARY.md). `library_title_id` is a real
     * composite FK against `library_titles(id, school_id)`, restrict-
     * on-delete (Titles have no delete endpoint -- rule 73 -- this is
     * defensive-only, matching subject_offerings' identical treatment
     * of its own parent references).
     *
     * `campus_id` is nullable and OPTIONAL -- Phase 10A's explicit
     * "Campus scoping decision" (docs/modules/LIBRARY.md): the
     * bibliographic Title is School-wide, but a physical Copy is a real
     * object that may sit at one specific Campus in a multi-campus
     * School, mirroring exactly how `rooms` is Campus-scoped while
     * `subjects`/`grade_levels` are School-wide. A single-campus School
     * simply leaves this null. Deliberately NOT a reference to
     * `rooms` -- no requirement exists yet for "which room holds this
     * copy," and the checkpoint brief explicitly says not to couple
     * Library to Room without an actual requirement.
     *
     * `code` is the copy's own individually-identifying accession
     * value (NormalizesCode/NormalizesCodeInput, exactly like
     * Subject.code/Department.code -- reusing that existing trait
     * verbatim, so this column is deliberately named `code`, not
     * `accession_code`), unique per School -- this is the real
     * "library card" identifier a physical book carries, distinct
     * from any bibliographic Title-level data.
     *
     * Deliberately NO "available"/"checked_out" status value on this
     * table. Whether a Copy is currently on loan is fully DERIVABLE
     * from `library_loans` (whether an active-status loan row
     * references this copy) via the partial unique index that migration
     * creates -- adding a second, mirrored status column here would be
     * exactly the kind of denormalized dual-source-of-truth the
     * checkpoint brief's "prevents logically impossible circulation
     * states" requirement warns against: two independent places that
     * must always agree are two places that can drift apart under a
     * missed update or a race. `status` here is only the ordinary
     * active/inactive reference-entity lifecycle (rule 73) -- "is this
     * copy still a real, usable item in the collection at all" (e.g.
     * lost/withdrawn), never "is it currently loaned out."
     */
    public function up(): void
    {
        Schema::create('library_copies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('library_title_id');
            $table->uuid('campus_id')->nullable();
            $table->string('code');
            $table->string('status')->default('active'); // active|inactive (withdrawn/lost) -- NEVER "on loan"
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables composite FKs from library_loans
            $table->unique(['school_id', 'code']);
            $table->index(['library_title_id']);
            $table->index(['campus_id']);
            $table->index(['status']);

            $table->foreign(['library_title_id', 'school_id'])
                ->references(['id', 'school_id'])->on('library_titles')
                ->restrictOnDelete();

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();
        });

        TenantRls::enable('library_copies');
    }

    public function down(): void
    {
        TenantRls::disable('library_copies');
        Schema::dropIfExists('library_copies');
    }
};
