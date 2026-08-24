<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5B.1 -- widens `communication_recipients` (the message-
     * centric logical "this Message is meant for this person" record,
     * Phase 5A.1 §2.5) so a Guardian can be a real logical recipient
     * that flows through the EXISTING `communication_deliveries` +
     * App\Jobs\ProcessCommunicationDeliveryJob pipeline unchanged
     * (docs/communication-hub/
     * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §9).
     *
     * Deliberately does NOT add a `recipient_student_id` column here --
     * a Student has no reachable channel at all in this checkpoint (no
     * canonical destination endpoint exists), so a Student never gets
     * a CommunicationRecipient/CommunicationDelivery row; its logical
     * audience membership is fully represented by
     * `communication_announcement_recipients` alone.
     *
     * Same additive, backward-compatible shape as the sibling migration
     * widening `communication_announcement_recipients` in this same
     * checkpoint: `recipient_user_id` becomes nullable, a new nullable
     * `recipient_guardian_id` composite-FK column is added,
     * `num_nonnulls(...) = 1` is the database-level "exactly one party"
     * guarantee, and a second unique index adds the Guardian-side dedup
     * guarantee alongside the untouched User-side one.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_recipients ALTER COLUMN recipient_user_id DROP NOT NULL');
        DB::statement('ALTER TABLE communication_recipients ADD COLUMN recipient_guardian_id uuid NULL');

        DB::statement(
            'ALTER TABLE communication_recipients ADD CONSTRAINT cr_guardian_school_foreign '.
            'FOREIGN KEY (recipient_guardian_id, school_id) REFERENCES guardians (id, school_id) ON DELETE RESTRICT'
        );

        DB::statement(
            'ALTER TABLE communication_recipients ADD CONSTRAINT cr_exactly_one_party_check '.
            'CHECK (num_nonnulls(recipient_user_id, recipient_guardian_id) = 1)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX cr_message_guardian_unique ON communication_recipients (message_id, recipient_guardian_id)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX cr_message_guardian_unique');
        DB::statement('ALTER TABLE communication_recipients DROP CONSTRAINT cr_exactly_one_party_check');
        DB::statement('ALTER TABLE communication_recipients DROP CONSTRAINT cr_guardian_school_foreign');
        DB::statement('ALTER TABLE communication_recipients DROP COLUMN recipient_guardian_id');
        DB::statement('ALTER TABLE communication_recipients ALTER COLUMN recipient_user_id SET NOT NULL');
    }
};
