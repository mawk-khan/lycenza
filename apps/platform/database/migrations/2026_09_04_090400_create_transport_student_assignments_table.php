<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10B -- a Student's Transport assignment
     * (docs/modules/TRANSPORT.md "Student assignment model" and
     * "Direction/AM-PM modeling"). `student_id` cascades on delete --
     * an assignment has no meaning independent of its Student, the
     * exact `library_loans.student_id` precedent.
     * `route_id`/`pickup_stop_id`/`dropoff_stop_id` restrict (none of
     * their parent tables expose a delete endpoint).
     *
     * ONE row per assignment period carries BOTH an optional pickup
     * Stop and an optional drop-off Stop (checkpoint brief section 7:
     * "a Student may have different pickup and drop-off Stops" on the
     * SAME Route) -- deliberately NOT two separate AM/PM assignment
     * rows, and deliberately NOT a "direction" column. Supporting a
     * Student riding genuinely DIFFERENT Routes inbound vs. outbound
     * is explicitly out of scope for this checkpoint (documented
     * assumption, not a repository requirement) -- see
     * docs/modules/TRANSPORT.md for the full reasoning and what a
     * future checkpoint would need to add this without a redesign.
     *
     * `pickup_stop_id`/`dropoff_stop_id` are composite FKs against
     * `transport_stops(id, route_id, school_id)` -- NOT the plain
     * `(id, school_id)` shape every other composite FK in this
     * codebase uses. This is what makes checkpoint brief section 13's
     * "a Stop from Route A cannot be used in a Student assignment for
     * Route B" invariant a real, database-enforced fact: the 3-column
     * FK requires the referenced `transport_stops` row's own
     * `route_id` to equal THIS row's `route_id` exactly, or the INSERT
     * is rejected by PostgreSQL itself. PostgreSQL's default FK MATCH
     * SIMPLE means a NULL `pickup_stop_id` (a Student with no fixed
     * pickup point recorded) is not checked at all -- exactly the
     * desired behavior for an optional reference.
     *
     * ONE active assignment per Student at a time -- a partial unique
     * index, the same `library_loans_one_active_per_copy` pattern.
     * `TransportStudentAssignmentService::assign()` REJECTS if the
     * Student already has an active assignment (the caller must
     * explicitly `end()` the current one first) -- deliberately the
     * Library checkout precedent (explicit-end-required), not the
     * AcademicYear auto-replace precedent
     * (`TransportRouteAssignmentService::assign()` uses the opposite,
     * documented separately) -- a Student's Transport assignment is a
     * discrete fact worth an explicit transition, not administrative
     * housekeeping.
     */
    public function up(): void
    {
        Schema::create('transport_student_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->uuid('route_id');
            $table->uuid('pickup_stop_id')->nullable();
            $table->uuid('dropoff_stop_id')->nullable();
            $table->string('status')->default('active'); // active|ended
            $table->timestamp('starts_on');
            $table->timestamp('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index(['school_id', 'student_id']);
            $table->index(['route_id']);
            $table->index(['status']);

            $table->foreign(['student_id', 'school_id'])
                ->references(['id', 'school_id'])->on('students')
                ->cascadeOnDelete();

            $table->foreign(['route_id', 'school_id'])
                ->references(['id', 'school_id'])->on('transport_routes')
                ->restrictOnDelete();

            $table->foreign(['pickup_stop_id', 'route_id', 'school_id'])
                ->references(['id', 'route_id', 'school_id'])->on('transport_stops')
                ->restrictOnDelete();

            $table->foreign(['dropoff_stop_id', 'route_id', 'school_id'])
                ->references(['id', 'route_id', 'school_id'])->on('transport_stops')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE transport_student_assignments ADD CONSTRAINT transport_student_assignments_end_after_start_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement('ALTER TABLE transport_student_assignments ADD CONSTRAINT transport_student_assignments_status_end_consistency_check CHECK ((status = \'ended\') = (ends_on IS NOT NULL))');
        DB::statement(
            'CREATE UNIQUE INDEX transport_student_assignments_one_active_per_student '.
            "ON transport_student_assignments (student_id) WHERE status = 'active'"
        );

        TenantRls::enable('transport_student_assignments');
    }

    public function down(): void
    {
        TenantRls::disable('transport_student_assignments');
        Schema::dropIfExists('transport_student_assignments');
    }
};
