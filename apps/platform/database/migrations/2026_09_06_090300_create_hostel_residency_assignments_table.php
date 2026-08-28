<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10D -- a Student's Hostel residency assignment to a Bed
     * (docs/modules/HOSTEL.md "ResidencyAssignment lifecycle"). This
     * is the authoritative occupancy record -- `hostel_beds` and
     * `hostel_rooms` carry no occupancy/current-resident state of
     * their own; the full hierarchy (Bed -> Room -> Hostel -> Campus)
     * is resolved by joining through `hostel_bed_id`, so `room_id`/
     * `hostel_id`/`campus_id` are deliberately NOT duplicated onto
     * this row -- no structural-invariant requirement was found that
     * would outweigh that duplication risk (unlike
     * `transport_student_assignments.pickup_stop_id`/`dropoff_stop_id`,
     * which genuinely need a 3-column FK against `(id, route_id,
     * school_id)` because TWO independent Stop references on the same
     * row must both agree with a route_id that itself isn't otherwise
     * present here).
     *
     * `student_id` RESTRICTs on delete -- a deliberate correction
     * relative to Transport's `transport_student_assignments.student_id`
     * CASCADE precedent, informed directly by the Phase 10C Visitor
     * historical-integrity correction: Students also expose no delete
     * endpoint, and residency history must survive regardless of any
     * future raw-SQL/maintenance-script/refactor deletion path.
     * `hostel_bed_id` also RESTRICTs for the same reason.
     *
     * `status` (active|ended) is tied to `ends_on` by a CHECK
     * constraint, mirroring
     * `transport_student_assignments_status_end_consistency_check`/
     * `visitor_visits_status_checkout_consistency_check` exactly. ONE
     * active assignment per Bed AND ONE active assignment per Student
     * are both enforced by partial unique indexes -- two independent
     * invariants on the same table, the same shape
     * `transport_route_assignments`/`transport_student_assignments`
     * already established as two SEPARATE tables' worth of precedent,
     * now combined onto one table because both invariants share the
     * same underlying assignment concept here.
     *
     * No `assigned_by_employee_id`/`ended_by_employee_id` columns --
     * actor identity is recorded exclusively through `AuditRecorder`
     * (`actor_user_id`), the exact Visitor precedent (VISITOR.md
     * "Lifecycle actor decision").
     */
    public function up(): void
    {
        Schema::create('hostel_residency_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('hostel_bed_id');
            $table->string('status')->default('active'); // active|ended
            $table->timestamp('starts_on');
            $table->timestamp('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'student_id']);
            $table->index(['hostel_bed_id']);
            $table->index(['status']);

            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();

            $table->foreign(['hostel_bed_id', 'school_id'])
                ->references(['id', 'school_id'])->on('hostel_beds')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE hostel_residency_assignments ADD CONSTRAINT hostel_residency_assignments_end_after_start_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement('ALTER TABLE hostel_residency_assignments ADD CONSTRAINT hostel_residency_assignments_status_end_consistency_check CHECK ((status = \'ended\') = (ends_on IS NOT NULL))');
        DB::statement(
            'CREATE UNIQUE INDEX hostel_residency_assignments_one_active_per_bed '.
            "ON hostel_residency_assignments (hostel_bed_id) WHERE status = 'active'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX hostel_residency_assignments_one_active_per_student '.
            "ON hostel_residency_assignments (student_id) WHERE status = 'active'"
        );

        TenantRls::enable('hostel_residency_assignments');
    }

    public function down(): void
    {
        TenantRls::disable('hostel_residency_assignments');
        Schema::dropIfExists('hostel_residency_assignments');
    }
};
