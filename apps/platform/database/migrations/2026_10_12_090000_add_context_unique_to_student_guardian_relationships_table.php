<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4D-P2 (Student Processing Authorization Registry):
     * `student_processing_authorizations.student_guardian_relationship_id`
     * needs a composite foreign key proving the referenced relationship
     * belongs to the SAME School AND the SAME Student the authorization
     * is being recorded for -- not merely the same School. The existing
     * `unique(school_id, student_id, guardian_id)` does not expose `id`
     * as a referenceable parent key, so this additive migration widens
     * it exactly the way `examinations_context_unique`/
     * `syllabus_units_offering_context_unique` already established for
     * this same "wider composite unique key on the parent, for one
     * specific child's structural FK" need (CLAUDE.md rule 70).
     */
    public function up(): void
    {
        Schema::table('student_guardian_relationships', function (Blueprint $table) {
            $table->unique(['id', 'school_id', 'student_id'], 'student_guardian_relationships_context_unique');
        });
    }

    public function down(): void
    {
        Schema::table('student_guardian_relationships', function (Blueprint $table) {
            $table->dropUnique('student_guardian_relationships_context_unique');
        });
    }
};
