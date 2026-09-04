<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0I.3 -- the second extension of the Documents module's
     * exclusive-arc owner arc since 0E.1 (ADR 0012, ADR 0037 decision
     * 8, docs/modules/LMS.md §9), and identical in shape to Phase
     * 0I.2's `learning_content_id` extension: a new nullable
     * `assignment_id` owner column, its own composite FK to
     * `assignments(id, school_id)`, and the existing
     * `documents_exactly_one_owner_check` widened to a fifth term.
     *
     * `submission_id` is deliberately NOT added here -- it remains
     * blocked on ADR 0037 §4's Submission legal-review gate (Phase
     * 0I.4), exactly as `docs/modules/LMS.md` §9 anticipated. Adding a
     * dormant `submission_id` column now "for later" would be exactly
     * the speculative-schema pattern CLAUDE.md rule 2 forbids -- and
     * would put a real, unenforced legal-review gate one migration
     * away from being silently bypassed by a future careless change.
     *
     * `assignment_id` uses `cascadeOnDelete()`, matching
     * `employee_id`/`student_id`/`guardian_id`/`learning_content_id`
     * exactly -- even though, in current application-layer reality,
     * `assignments` rows are never hard-deleted (status-based
     * retirement only). PostgreSQL has no `ALTER CONSTRAINT ... CHECK`,
     * so widening a CHECK is DROP + re-ADD with the new full
     * expression, the same mechanism the `learning_content_id`
     * migration already used.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('assignment_id')->nullable()->after('learning_content_id');

            $table->index('assignment_id');

            $table->foreign(['assignment_id', 'school_id'])
                ->references(['id', 'school_id'])->on('assignments')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_exactly_one_owner_check');

        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_exactly_one_owner_check '.
            'CHECK ('.
            '(CASE WHEN employee_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN student_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN guardian_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN learning_content_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN assignment_id IS NOT NULL THEN 1 ELSE 0 END) = 1'.
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
            '(CASE WHEN guardian_id IS NOT NULL THEN 1 ELSE 0 END) + '.
            '(CASE WHEN learning_content_id IS NOT NULL THEN 1 ELSE 0 END) = 1'.
            ')'
        );

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['assignment_id', 'school_id']);
            $table->dropIndex(['assignment_id']);
            $table->dropColumn('assignment_id');
        });
    }
};
