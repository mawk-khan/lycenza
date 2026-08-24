<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5B.2 -- widens `communication_delivery_policy_decisions`
     * (already widened once, for Guardian, by Phase 5B.1) to also
     * accept a `recipient_student_id`, needed now that a linked
     * Student can be evaluated for IN_APP eligibility
     * (App\Domain\Communications\Application\AnnouncementService::deliverInAppForLinkedDomainParty()) --
     * an unlinked/inactively-linked Student's `recipient_ineligible`
     * suppression needs a party to record itself against, exactly like
     * the Guardian case Phase 5B.1 already handles.
     *
     * Same additive shape as every other widening migration in this
     * lineage: the CHECK constraint grows to
     * `num_nonnulls(recipient_user_id, recipient_guardian_id,
     * recipient_student_id) = 1`, and a third unique index adds the
     * Student-side per-message-per-channel dedup guarantee alongside
     * the two existing ones.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_delivery_policy_decisions ADD COLUMN recipient_student_id uuid NULL');

        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT cdpd_student_school_foreign '.
            'FOREIGN KEY (recipient_student_id, school_id) REFERENCES students (id, school_id) ON DELETE RESTRICT'
        );

        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT cdpd_exactly_one_party_check');
        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT cdpd_exactly_one_party_check '.
            'CHECK (num_nonnulls(recipient_user_id, recipient_guardian_id, recipient_student_id) = 1)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX cdpd_message_student_channel_unique ON communication_delivery_policy_decisions (message_id, recipient_student_id, channel)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX cdpd_message_student_channel_unique');
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT cdpd_exactly_one_party_check');
        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT cdpd_exactly_one_party_check '.
            'CHECK (num_nonnulls(recipient_user_id, recipient_guardian_id) = 1)'
        );
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT cdpd_student_school_foreign');
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP COLUMN recipient_student_id');
    }
};
