<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0D sections 29-33, 62: School-wide reference data (like
     * GradeLevel, not Campus/AcademicYear-scoped -- see
     * docs/modules/ACADEMIC-STRUCTURE.md). `academic_department_id` is
     * nullable (section 33: not every Subject needs a Department) and
     * composite-FK-protected against `academic_departments(id,
     * school_id)` (section 36).
     */
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('academic_department_id')->nullable();
            $table->string('name');
            $table->string('code');
            $table->string('short_name')->nullable();
            $table->string('subject_type')->default('core'); // core|elective|co_scholastic|language|other
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->unique(['id', 'school_id']);
            $table->index(['academic_department_id']);

            // restrictOnDelete, not nullOnDelete: a composite FK's SET
            // NULL action would try to null BOTH columns, but school_id
            // is NOT nullable -- an AcademicDepartment still referenced
            // by a Subject must be reference-safe (section 58) rather
            // than deletable at all.
            $table->foreign(['academic_department_id', 'school_id'])
                ->references(['id', 'school_id'])->on('academic_departments')
                ->restrictOnDelete();
        });

        TenantRls::enable('subjects');
    }

    public function down(): void
    {
        TenantRls::disable('subjects');
        Schema::dropIfExists('subjects');
    }
};
