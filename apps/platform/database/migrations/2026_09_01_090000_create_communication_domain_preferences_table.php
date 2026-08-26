<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5D.2 -- a Guardian/Student domain identity's own current
     * channel preference for an EXTERNAL channel (email today), the
     * domain-recipient counterpart to `communication_preferences`
     * (Phase 5A.5, scoped to a `school_membership_id`). Row absence
     * means "inherit existing default behavior" (brief §11) -- never
     * "opted out" -- exactly mirroring `communication_preferences`'
     * own "row absence = inherit default" semantics so introducing
     * this table changes nothing for a Guardian who never sets one.
     *
     * Deliberately NEVER read for `in_app` (root CLAUDE.md-equivalent
     * brief §7/§38): a linked Guardian/Student's IN_APP eligibility
     * continues to come exclusively from their linked SchoolMembership's
     * own `communication_preferences` row. This table exists only for
     * channels a SchoolMembership has no reachability model for at all.
     *
     * Scoped to the Guardian/Student identity and channel, never to an
     * individual GuardianContact row (brief §46) -- a Guardian changing
     * their primary email address never resets this preference.
     *
     * `num_nonnulls(guardian_id, student_id) = 1` mirrors every other
     * "exactly one typed domain target" table in this lineage
     * (`communication_announcement_domain_audience_members`,
     * `communication_thread_participants`' provenance columns).
     * Composite FKs against `guardians(id, school_id)`/
     * `students(id, school_id)`, `RESTRICT` on delete -- consistent
     * with the identical choice for
     * `communication_thread_participants`' provenance columns (Phase
     * 5D.1): Guardian/Student rows are deactivated, never hard-deleted
     * (root CLAUDE.md rule 73), so this should never actually fire.
     *
     * One deterministic current preference per (school, identity,
     * channel) -- partial unique indexes are the database-level
     * "exactly one row wins" guarantee (brief §27), matching
     * `communication_preferences`' own `updateOrCreate()`-driven
     * single-row-per-membership-per-channel shape (this table is
     * likewise mutated in place via `updateOrCreate()`, never appended
     * to -- current state, not history; consent's own append-only
     * ledger is the separate `communication_domain_consent_events`
     * table for exactly the history this one deliberately does not
     * keep).
     */
    public function up(): void
    {
        Schema::create('communication_domain_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('guardian_id')->nullable();
            $table->uuid('student_id')->nullable();
            $table->string('channel');
            $table->string('preference'); // enabled|disabled
            $table->timestamps();

            $table->index('school_id');

            $table->foreign(['guardian_id', 'school_id'], 'cdp_guardian_school_foreign')
                ->references(['id', 'school_id'])->on('guardians')
                ->restrictOnDelete();

            $table->foreign(['student_id', 'school_id'], 'cdp_student_school_foreign')
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_domain_preferences ADD CONSTRAINT cdp_exactly_one_identity_check '.
            'CHECK (num_nonnulls(guardian_id, student_id) = 1)'
        );
        DB::statement(
            'ALTER TABLE communication_domain_preferences ADD CONSTRAINT cdp_preference_check '.
            "CHECK (preference IN ('enabled', 'disabled'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX cdp_one_current_per_guardian_channel ON communication_domain_preferences (school_id, guardian_id, channel) '.
            'WHERE guardian_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX cdp_one_current_per_student_channel ON communication_domain_preferences (school_id, student_id, channel) '.
            'WHERE student_id IS NOT NULL'
        );

        TenantRls::enable('communication_domain_preferences');
    }

    public function down(): void
    {
        TenantRls::disable('communication_domain_preferences');
        Schema::dropIfExists('communication_domain_preferences');
    }
};
