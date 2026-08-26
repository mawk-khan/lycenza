<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5D.2 §13/§14 -- an APPEND-ONLY (TenantRls::makeAppendOnly,
     * mirroring `communication_delivery_attempts`' exact pattern)
     * ledger of explicit consent/withdrawal decisions for a Guardian/
     * Student domain identity's channel. A row is a fact about what was
     * decided and when -- never edited, never deleted, at the database
     * privilege level. "Current" consent state for (school, identity,
     * channel) is always derived by the application as the row with
     * the latest `recorded_at` (ties broken by `id`, a UUIDv7 and
     * therefore itself chronologically sortable) -- there is
     * deliberately no separate "current state" column that could drift
     * out of sync with the ledger.
     *
     * Distinct from `communication_domain_preferences` (brief §12):
     * that table is current-state-only, mutated in place, for an
     * ordinary "I'd rather not" channel preference; this table is
     * history-preserving evidence of an explicit consent decision.
     * Neither table is the other's history.
     *
     * `recorded_by_user_id` is nullable-less (always required) --
     * every consent event has an actor, mirroring
     * `student_guardian_account_links.linked_by_user_id`'s "who did
     * this" precedent, not a system-attributed event. `source` is a
     * short free-form label (e.g. `admin_recorded`) for provenance,
     * never a legal/compliance classification. `note` is optional,
     * short, operator-facing context -- never a document/legal
     * artifact (brief §28's explicit exclusion).
     */
    public function up(): void
    {
        Schema::create('communication_domain_consent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('guardian_id')->nullable();
            $table->uuid('student_id')->nullable();
            $table->string('channel');
            $table->string('status'); // granted|withdrawn
            $table->timestamp('recorded_at');
            $table->foreignUuid('recorded_by_user_id')->constrained('users');
            $table->string('source')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index(['school_id', 'guardian_id', 'channel']);
            $table->index(['school_id', 'student_id', 'channel']);

            $table->foreign(['guardian_id', 'school_id'], 'cdce_guardian_school_foreign')
                ->references(['id', 'school_id'])->on('guardians')
                ->restrictOnDelete();

            $table->foreign(['student_id', 'school_id'], 'cdce_student_school_foreign')
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_domain_consent_events ADD CONSTRAINT cdce_exactly_one_identity_check '.
            'CHECK (num_nonnulls(guardian_id, student_id) = 1)'
        );
        DB::statement(
            'ALTER TABLE communication_domain_consent_events ADD CONSTRAINT cdce_status_check '.
            "CHECK (status IN ('granted', 'withdrawn'))"
        );

        TenantRls::enable('communication_domain_consent_events');
        TenantRls::makeAppendOnly('communication_domain_consent_events');
    }

    public function down(): void
    {
        TenantRls::disable('communication_domain_consent_events');
        Schema::dropIfExists('communication_domain_consent_events');
    }
};
