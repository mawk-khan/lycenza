<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A closure correction ("EmployeeNote"). One of the 14
     * entities the original 8A.0 entity model named
     * (`EmployeeNote (1:N -- Restricted/Confidential, HR-authored)`),
     * left unbuilt while `hr.employees.notes.view`/`.manage` were
     * pre-registered at 8A.10 as a "capability exists, data does not
     * yet" placeholder (the same precedent 8A.0 itself established for
     * `.sensitive.*`). This migration finally builds the table those
     * capabilities were always meant to gate.
     *
     * `classification_tier` (`sensitive`|`confidential`, plain
     * application-validated string, matching every other status-shaped
     * column in this module) is set PER-NOTE at write time, not a
     * fixed table-wide tier (docs/modules/HR.md Privacy Classification
     * Matrix: "a note referencing a disciplinary matter or health
     * information inherits that higher tier"). Both tiers are gated by
     * the SAME `hr.employees.notes.view`/`.manage` pair -- the plan
     * never defined a separate, higher capability for `confidential`
     * specifically, and this correction does not invent one (root
     * CLAUDE.md rule 2).
     *
     * `author_user_id` is NOT nullable -- a Note is always HR-authored
     * by a real actor (`restrictOnDelete()`, matching `employees.user_id`'s
     * own delete-protection choice, never `cascadeOnDelete()`, since a
     * User being removed must not silently destroy HR note history).
     *
     * `employee_id` composite-FKs to `employees(id, school_id)` (rule
     * 70 pattern) with cascadeOnDelete, matching
     * `employee_addresses`/`employee_qualifications`, etc.
     */
    public function up(): void
    {
        Schema::create('employee_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->string('classification_tier')->default('sensitive'); // sensitive|confidential
            $table->timestamps();

            $table->index('employee_id');
            $table->index('school_id');

            $table->foreign(['employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->cascadeOnDelete();
        });

        TenantRls::enable('employee_notes');
    }

    public function down(): void
    {
        TenantRls::disable('employee_notes');
        Schema::dropIfExists('employee_notes');
    }
};
