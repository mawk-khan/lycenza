<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0I.2 -- the first extension of the Documents module's
     * exclusive-arc owner arc since its 0E.1 foundation (ADR 0012, ADR
     * 0037 decision 8, docs/modules/DOCUMENTS.md). Additive: a new
     * nullable `learning_content_id` owner column, its own composite FK
     * to `learning_content(id, school_id)`, and the existing
     * `documents_exactly_one_owner_check` widened to a fourth term.
     *
     * This is the IDENTICAL three-part pattern every owner arm has used
     * since 0E.1 (new column + composite FK + widen the CHECK) -- no
     * polymorphic redesign, no change to `employee_id`/`student_id`/
     * `guardian_id`, no change to `classification_tier`/`status`/
     * `storage_disk`/`storage_path` or any other existing column. RLS
     * (already ENABLED and FORCED on `documents`) needs no change here:
     * a fifth nullable column adds no new tenant-isolation surface, the
     * existing `school_id`-keyed policy already covers it.
     *
     * `learning_content_id` uses `cascadeOnDelete()`, matching
     * `employee_id`/`student_id`/`guardian_id` exactly -- even though, in
     * current application-layer reality, `learning_content` rows are
     * never hard-deleted (status-based retirement only, ADR 0037
     * decision 9), the FK shape stays consistent with its three
     * siblings rather than silently diverging from the established
     * pattern for a row this checkpoint does not expect to ever fire.
     *
     * PostgreSQL has no `ALTER CONSTRAINT ... CHECK`, so widening a
     * CHECK is DROP + re-ADD with the new full expression -- the same
     * mechanism any future owner-arm addition (`assignment_id`,
     * `submission_id`) will also use. This migration touches
     * `documents_exactly_one_owner_check` ONLY; every other constraint
     * on `documents` (`documents_classification_tier_check`,
     * `documents_status_check`, both owner FKs, RLS) is untouched.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('learning_content_id')->nullable()->after('guardian_id');

            $table->index('learning_content_id');

            $table->foreign(['learning_content_id', 'school_id'])
                ->references(['id', 'school_id'])->on('learning_content')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_exactly_one_owner_check');

        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_exactly_one_owner_check '.
            'CHECK ('.
            '(CASE WHEN employee_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN student_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN guardian_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN learning_content_id IS NOT NULL THEN 1 ELSE 0 END) = 1'.
            ')'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_exactly_one_owner_check');

        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_exactly_one_owner_check '.
            'CHECK ('.
            '(CASE WHEN employee_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN student_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN guardian_id IS NOT NULL THEN 1 ELSE 0 END) = 1'.
            ')'
        );

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['learning_content_id', 'school_id']);
            $table->dropIndex(['learning_content_id']);
            $table->dropColumn('learning_content_id');
        });
    }
};
