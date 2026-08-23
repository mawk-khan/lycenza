<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.4 §4/§7 -- reusable, School-owned source content an
     * Announcement may be created FROM. Deliberately no
     * `communication_template_versions` table (brief §7: "do not
     * over-engineer versioning merely for theoretical future
     * requirements") -- `communication_announcements` already owns its
     * own `title`/`body`/`priority` columns (Phase 5A.2), and applying
     * a template simply COPIES `subject`/`body`/`priority` into those
     * columns once, at creation time. That copy, plus this table's
     * `id` kept only as `communication_announcements.source_template_id`
     * (a plain informational reference, not a live join), is the
     * entire snapshot story: editing a template afterward can never
     * reach an already-created Announcement's own columns.
     *
     * `template_type` is CHECK-restricted to a single value today
     * (`announcement`) -- brief §5 explicitly warns against seeding
     * business-specific types (fees/attendance/exams/...) into this
     * foundation; a future module reuses this same table by widening
     * the CHECK, not by inventing a parallel template concept.
     *
     * `status`: `active`/`inactive` only, matching every other
     * reference-entity lifecycle in this codebase (root CLAUDE.md rule
     * 73 -- GradeLevel/Subject/Room/... all use this exact shape) --
     * no DELETE endpoint exists or will exist, since a past
     * Announcement's `source_template_id` must remain a valid
     * reference even after the template is retired.
     */
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('template_type')->default('announcement');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('priority')->nullable(); // normal|important|urgent|critical, null = no suggested priority
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index(['school_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE communication_templates ADD CONSTRAINT communication_templates_template_type_check '.
            "CHECK (template_type IN ('announcement'))"
        );
        DB::statement(
            'ALTER TABLE communication_templates ADD CONSTRAINT communication_templates_priority_check '.
            "CHECK (priority IS NULL OR priority IN ('normal', 'important', 'urgent', 'critical'))"
        );
        DB::statement(
            'ALTER TABLE communication_templates ADD CONSTRAINT communication_templates_status_check '.
            "CHECK (status IN ('active', 'inactive'))"
        );

        TenantRls::enable('communication_templates');
    }

    public function down(): void
    {
        TenantRls::disable('communication_templates');
        Schema::dropIfExists('communication_templates');
    }
};
