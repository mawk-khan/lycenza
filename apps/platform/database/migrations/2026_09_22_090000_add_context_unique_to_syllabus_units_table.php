<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.3B (Curriculum Delivery): additive, non-destructive --
     * the exact same shape and reasoning as this migration set's
     * `add_context_unique_to_subject_offerings_table`/
     * `add_context_unique_to_sections_table` siblings, applied to
     * `syllabus_units`.
     *
     * `syllabus_units` already carries `unique(['id', 'school_id'])`
     * (the standard 2-column composite-FK target) from its own creation
     * migration -- deliberately kept there so "a future Curriculum
     * Delivery row can reference this table tenant-pinned". This adds
     * the richer 3-column key so the LATER
     * `create_curriculum_deliveries_table` migration can declare
     * `curriculum_deliveries_syllabus_unit_fk`, which structurally pins
     * a delivery row's SyllabusUnit to the SAME SubjectOffering the row
     * also declares for its Section context, rather than merely
     * trusting the application layer to keep them consistent
     * (CLAUDE.md rule 70).
     *
     * Without this key, tenant isolation alone would still permit a
     * same-School Section teaching Mathematics to record delivery
     * against a Science SyllabusUnit. See docs/modules/ACADEMICS.md.
     *
     * Purely additive: no Syllabus data is transformed, no existing
     * constraint is dropped or weakened, and
     * `syllabus_units_offering_code_ci_unique`, the status CHECK, the
     * composite SubjectOffering FK and RLS are all untouched. Adding a
     * unique key over `(id, school_id, subject_offering_id)` can never
     * fail on existing data either, because `id` is already unique on
     * its own.
     *
     * Rollback ordering: `create_curriculum_deliveries_table` is dated
     * AFTER this migration, so `migrate:rollback` drops
     * `curriculum_deliveries` (and with it the FK that depends on this
     * key) BEFORE this `down()` runs. Dropping the key first would be
     * rejected by PostgreSQL while a dependent foreign key exists.
     */
    public function up(): void
    {
        Schema::table('syllabus_units', function (Blueprint $table) {
            $table->unique(
                ['id', 'school_id', 'subject_offering_id'],
                'syllabus_units_offering_context_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_units', function (Blueprint $table) {
            $table->dropUnique('syllabus_units_offering_context_unique');
        });
    }
};
