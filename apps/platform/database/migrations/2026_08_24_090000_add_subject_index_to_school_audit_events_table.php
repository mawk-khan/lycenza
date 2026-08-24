<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.11 -- the Communication audit timeline
     * (App\Domain\Communications\Application\CommunicationAuditReadModel)
     * is the first caller to filter school_audit_events by
     * (school_id, subject_type, subject_id) rather than
     * (school_id, event_type)/(school_id) alone -- "every event
     * recorded against THIS specific Announcement" is exactly a
     * subject lookup, not an event-type lookup. The existing
     * `school_id` index still lets every OTHER query on this
     * general-purpose ledger execute correctly; this is purely an
     * additive index for a query shape that did not previously exist
     * (root CLAUDE.md rule 41: add indexes only after inspecting
     * actual query patterns).
     */
    public function up(): void
    {
        Schema::table('school_audit_events', function (Blueprint $table) {
            $table->index(['school_id', 'subject_type', 'subject_id'], 'school_audit_events_subject_index');
        });
    }

    public function down(): void
    {
        Schema::table('school_audit_events', function (Blueprint $table) {
            $table->dropIndex('school_audit_events_subject_index');
        });
    }
};
