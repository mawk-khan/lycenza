<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A closure correction ("Work contact fields") --
     * docs/modules/HR.md's own Privacy Classification Matrix always
     * listed "work email/phone" as Directory-tier (Internal), but no
     * column existed for either (a recorded, disclosed gap from 8A.1/
     * 8A.2). Both live directly on `employees`, alongside `full_name`
     * -- Directory-tier fields, not Restricted, so they belong with
     * the other Internal-tier identity fields already on this table,
     * never on `employee_personal_details` (which is Restricted-tier
     * only). `work_phone` carries no uniqueness constraint (the
     * original plan only ever flagged `work_email` for one); NULL is
     * distinct from every other NULL under PostgreSQL's multi-column
     * unique index (the same mechanism `unique(school_id, user_id)`
     * already relies on on this same table), so any number of
     * Employees may simultaneously have no `work_email` recorded yet.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('work_email')->nullable()->after('full_name');
            $table->string('work_phone')->nullable()->after('work_email');

            $table->unique(['school_id', 'work_email'], 'employees_work_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_work_email_unique');
            $table->dropColumn(['work_email', 'work_phone']);
        });
    }
};
