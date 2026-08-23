<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A: a Student is a permanent School-level identity,
     * deliberately independent of admission/enrollment/grade/section/
     * attendance/fee-account/portal-login state (all separate concerns
     * owned by future Layer 2/3 modules, docs/architecture/DOMAIN-MAP.md).
     * `student_number` is unique within a School only (never globally),
     * stable across academic years, and carries no grade/class/roll-number
     * meaning -- see docs/modules/STUDENT-GUARDIAN-IDENTITY.md.
     *
     * `unique(['id', 'school_id'])` is added now (unused by any child
     * table yet) so a future composite foreign key -- e.g. from
     * StudentGuardianRelationship or StudentIdentifier -- can reference
     * (id, school_id) exactly like every other tenant-owned parent table
     * in this codebase (Phase 0B/0C.3/0D's established pattern), without
     * a later migration to add it retroactively.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('student_number');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('date_of_birth');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'student_number']);
            $table->unique(['id', 'school_id']);
        });

        TenantRls::enable('students');
    }

    public function down(): void
    {
        TenantRls::disable('students');
        Schema::dropIfExists('students');
    }
};
