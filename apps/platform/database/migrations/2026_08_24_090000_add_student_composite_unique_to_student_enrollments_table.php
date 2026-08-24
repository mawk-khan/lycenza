<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B.7A: a small, purely additive supporting index on the
     * already-accepted `student_enrollments` table (Phase 1B.1) --
     * classified explicitly per this checkpoint's brief section 17.
     *
     * `id` alone is already globally unique (the primary key), so
     * `unique(['id', 'student_id'])` is trivially satisfied by every
     * existing row and changes no existing behavior, no existing
     * constraint, and no existing query plan for any Phase 1B.1-1B.6
     * code. Its sole purpose is enabling a NEW composite foreign key
     * from `enrollment_rollover_items.source_enrollment_id` that
     * structurally guarantees "this item's claimed source Enrollment
     * really belongs to this item's claimed Student" -- the same class
     * of guarantee `unique(['id', 'school_id'])` already gives every
     * tenant-owned table for the School dimension, applied here to the
     * Student dimension specifically because nothing else in this
     * schema can express "column A's row must belong to column B's
     * value" without either this composite unique + FK pair or a
     * trigger, and this codebase has already established (in this same
     * table's own original migration docblock) that a supporting
     * unique index is the preferred structural tool over a trigger.
     *
     * This migration touches ONLY the index; it does not add, remove,
     * or modify any column, and does not alter `student_enrollments`'
     * pre-existing RLS policy or any other constraint.
     */
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->unique(['id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique(['id', 'student_id']);
        });
    }
};
