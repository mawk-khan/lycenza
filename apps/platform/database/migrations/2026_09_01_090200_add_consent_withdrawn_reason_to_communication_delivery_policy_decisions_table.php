<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5D.2 -- widens `communication_delivery_policy_decisions`'
     * `reason` CHECK (Phase 5A.5, last widened by Phase 5B.1 to add
     * `recipient_destination_unavailable`) to also accept
     * `consent_withdrawn` (see
     * App\Domain\Communications\Application\Policy\CommunicationPolicyReason).
     * Same additive shape as every prior widening of this constraint --
     * no existing row's reason value is affected.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT communication_delivery_policy_decisions_reason_check');
        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT communication_delivery_policy_decisions_reason_check '.
            "CHECK (reason IN ('recipient_ineligible', 'unsupported_channel', 'school_optional_channel_disabled', 'school_required_channel_disabled', 'recipient_preference_disabled', 'recipient_destination_unavailable', 'consent_withdrawn'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE communication_delivery_policy_decisions DROP CONSTRAINT communication_delivery_policy_decisions_reason_check');
        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT communication_delivery_policy_decisions_reason_check '.
            "CHECK (reason IN ('recipient_ineligible', 'unsupported_channel', 'school_optional_channel_disabled', 'school_required_channel_disabled', 'recipient_preference_disabled', 'recipient_destination_unavailable'))"
        );
    }
};
