<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10C -- a Visitor's check-in/check-out visit to a Campus
     * (docs/modules/VISITOR.md "Visit lifecycle"). `visitor_id`
     * cascades on delete -- a Visit has no meaning independent of its
     * Visitor, the exact `transport_student_assignments.student_id`
     * precedent. `campus_id`/`host_employee_id` restrict (neither
     * Campus nor Employee exposes a delete endpoint).
     *
     * `host_employee_id` is OPTIONAL -- a Visitor may visit an office
     * or the School generally without naming a specific host
     * (checkpoint brief section 12). There is deliberately NO Student
     * host relationship and NO polymorphic `host_type`/`host_id` --
     * out of scope for this checkpoint (checkpoint brief section 13).
     *
     * `purpose` is bounded to 500 characters and treated as Sensitive
     * free text -- never copied into audit metadata (VISITOR.md
     * "Audit").
     *
     * `status` (checked_in|checked_out) is tied to `checked_out_at` by
     * a CHECK constraint, mirroring
     * `transport_student_assignments_status_end_consistency_check`
     * exactly. ONE active (checked_in) Visit per Visitor is enforced by
     * a partial unique index, the same
     * `transport_student_assignments_one_active_per_student` pattern.
     *
     * `gate_pass_number` is optional. Chosen semantics (VISITOR.md
     * "Gate-pass number"): one locally-recorded identifier per
     * historical Visit, never reused -- so a permanent
     * `(school_id, gate_pass_number)` partial unique index (only when
     * present) is the correct rule, not a reusable/recyclable pass
     * pool. Deliberately NOT normalized via `NormalizesCode` -- like
     * `transport_vehicles.registration_number`, this is real-world
     * reference data (a physical/locally-issued pass), not a
     * School-chosen identifier.
     *
     * No `checked_in_by`/`checked_out_by` columns -- actor identity is
     * recorded exclusively through `AuditRecorder` (`actor_user_id`),
     * matching the fact that neither `transport_student_assignments`
     * nor `library_loans` carry an actor column either. See
     * VISITOR.md "Lifecycle actor decision".
     */
    public function up(): void
    {
        Schema::create('visitor_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('visitor_id');
            $table->uuid('campus_id');
            $table->uuid('host_employee_id')->nullable();
            $table->string('purpose', 500);
            $table->string('gate_pass_number')->nullable();
            $table->string('status')->default('checked_in'); // checked_in|checked_out
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'visitor_id']);
            $table->index(['campus_id']);
            $table->index(['status']);

            $table->foreign(['visitor_id', 'school_id'])
                ->references(['id', 'school_id'])->on('visitors')
                ->cascadeOnDelete();

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['host_employee_id', 'school_id'])
                ->references(['id', 'school_id'])->on('employees')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE visitor_visits ADD CONSTRAINT visitor_visits_status_checkout_consistency_check CHECK ((status = \'checked_out\') = (checked_out_at IS NOT NULL))');
        DB::statement('ALTER TABLE visitor_visits ADD CONSTRAINT visitor_visits_checkout_after_checkin_check CHECK (checked_out_at IS NULL OR checked_out_at >= checked_in_at)');
        DB::statement(
            'CREATE UNIQUE INDEX visitor_visits_one_active_per_visitor '.
            "ON visitor_visits (visitor_id) WHERE status = 'checked_in'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX visitor_visits_school_gate_pass_unique '.
            'ON visitor_visits (school_id, gate_pass_number) WHERE gate_pass_number IS NOT NULL'
        );

        TenantRls::enable('visitor_visits');
    }

    public function down(): void
    {
        TenantRls::disable('visitor_visits');
        Schema::dropIfExists('visitor_visits');
    }
};
