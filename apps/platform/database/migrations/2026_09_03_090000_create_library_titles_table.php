<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10A -- the Library module's bibliographic/catalogue layer.
     * See docs/modules/LIBRARY.md ("Catalogue vs. copy modeling
     * decision") for the full reasoning; summary: a title/copy split
     * (Option B from the checkpoint brief), not one flat `library_items`
     * row per physical object (Option A) -- a real School library
     * routinely holds several physical copies of the identical title,
     * and duplicating title/author across N rows both wastes entry
     * effort and creates an "editing copy 2 doesn't update copy 1's
     * title" data-integrity hazard the moment more than one copy of
     * anything exists. `library_titles` is the bibliographic record
     * (what the book IS); `library_copies` (next migration) is the
     * individually loanable physical object (one row per physical
     * copy).
     *
     * School-scoped, not Campus-scoped -- a catalogue entry describes
     * bibliographic data that is the same regardless of which Campus
     * physically holds a copy of it (see the next migration for where
     * Campus scoping actually belongs: on the physical Copy, not here).
     *
     * Deliberately minimal fields, matching every other reference-entity
     * table in this repository (Subject/GradeLevel/Department): title,
     * a nullable author (not every catalogued item has a single
     * identifiable author -- e.g. an atlas, a reference set), a nullable
     * ISBN (genuinely useful for real books, meaningless for many
     * others, so never required), and the standard active/inactive
     * lifecycle (rule 73 -- no delete endpoint, ever, since a
     * `library_loans` row may reference a Copy of this Title
     * historically). No publisher/edition/publication-year/genre/
     * external-catalogue-id fields -- none of these are needed to prove
     * the checkout/check-in workflow this checkpoint scopes, and adding
     * them now would be exactly the speculative-field pattern CLAUDE.md
     * rule 2 forbids; they are trivially additive later if a real need
     * appears. No external ISBN/metadata lookup of any kind.
     *
     * No uniqueness constraint on `title` -- real libraries legitimately
     * catalogue distinct physical works (different editions, donated
     * duplicates catalogued independently) that may share an identical
     * title string; the individually-identifying value lives on
     * `library_copies.accession_code` (next migration), not here.
     */
    public function up(): void
    {
        Schema::create('library_titles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn')->nullable();
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']); // enables composite FKs from library_copies
            $table->index(['school_id', 'title']);
            $table->index(['status']);
        });

        TenantRls::enable('library_titles');
    }

    public function down(): void
    {
        TenantRls::disable('library_titles');
        Schema::dropIfExists('library_titles');
    }
};
