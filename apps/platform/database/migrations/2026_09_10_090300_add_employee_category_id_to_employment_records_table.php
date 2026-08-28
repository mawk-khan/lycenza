<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A closure correction ("EmployeeCategory") -- see
     * `2026_09_10_090200_create_employee_categories_table.php`'s
     * docblock for why `employment_records`, not `employees`, is the
     * chosen attachment point. Nullable (a School not yet using
     * categories, or an Employment predating this migration, is valid
     * and uncategorized) and uses the established composite `(id,
     * school_id)` foreign key pattern (rule 70) -- never RLS/
     * SchoolScope alone -- so a cross-School `employee_category_id` is
     * rejected at INSERT/UPDATE time by the database itself, matching
     * every other child->parent reference in this module
     * (Assignment->Position/Department/Campus).
     */
    public function up(): void
    {
        Schema::table('employment_records', function (Blueprint $table) {
            $table->foreignUuid('employee_category_id')->nullable()->after('employment_type');

            $table->foreign(['employee_category_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employee_categories')
                ->restrictOnDelete();

            $table->index('employee_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('employment_records', function (Blueprint $table) {
            $table->dropForeign(['employee_category_id', 'school_id']);
            $table->dropColumn('employee_category_id');
        });
    }
};
