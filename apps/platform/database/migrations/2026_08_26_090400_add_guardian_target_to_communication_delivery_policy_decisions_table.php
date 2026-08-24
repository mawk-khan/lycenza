<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5B.1 -- widens `communication_delivery_policy_decisions`
     * (Phase 5A.5 §20/§21's append-only suppression ledger) so a
     * suppressed/unavailable decision for a Guardian recipient is
     * recorded with the same durability a SchoolMembership recipient's
     * suppression already has -- same additive shape as the sibling
     * migrations in this checkpoint.
     *
     * Also widens the `reason` CHECK to add
     * `recipient_destination_unavailable` (see
     * App\Domain\Communications\Application\Policy\CommunicationPolicyReason) --
     * distinct from every existing reason: "school policy allows this
     * channel but no usable destination endpoint exists for this
     * recipient" is neither a policy suppression nor a preference
     * suppression nor a transport failure (docs/communication-hub/
     * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §"Failure
     * terminology").
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_delivery_policy_decisions ALTER COLUMN recipient_user_id DROP NOT NULL');
        DB::statement('ALTER TABLE communication_delivery_policy_decisions ADD COLUMN recipient_guardian_id uuid NULL');

        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT cdpd_guardian_school_foreign '.
            'FOREIGN KEY (recipient_guardian_id, school_id) REFERENCES guardians (id, school_id) ON DELETE RESTRICT'
        );

        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT cdpd_exactly_one_party_check '.
            'CHECK (num_nonnulls(recipient_user_id, recipient_guardian_id) = 1)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX cdpd_message_guardian_channel_unique ON communication_delivery_policy_decisions (message_id, recipient_guardian_id, channel)'
        );

        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT communication_delivery_policy_decisions_reason_check');
        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT communication_delivery_policy_decisions_reason_check '.
            "CHECK (reason IN ('recipient_ineligible', 'unsupported_channel', 'school_optional_channel_disabled', 'school_required_channel_disabled', 'recipient_preference_disabled', 'recipient_destination_unavailable'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT communication_delivery_policy_decisions_reason_check');
        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT communication_delivery_policy_decisions_reason_check '.
            "CHECK (reason IN ('recipient_ineligible', 'unsupported_channel', 'school_optional_channel_disabled', 'school_required_channel_disabled', 'recipient_preference_disabled'))"
        );

        DB::statement('DROP INDEX cdpd_message_guardian_channel_unique');
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT cdpd_exactly_one_party_check');
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT cdpd_guardian_school_foreign');
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP COLUMN recipient_guardian_id');
        DB::statement('ALTER TABLE communication_delivery_policy_decisions ALTER COLUMN recipient_user_id SET NOT NULL');
    }
};
