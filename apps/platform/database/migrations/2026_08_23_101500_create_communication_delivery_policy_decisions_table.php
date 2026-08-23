<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.5 §20/§21 -- the append-only auditability record for a
     * SUPPRESSED (requested-but-not-attempted) channel, distinct from
     * `communication_delivery_attempts` (a real attempted send that
     * FAILED). Deliberately stores ONLY suppression decisions, never
     * ALLOW ones -- an ALLOW decision already has its own durable
     * record: the `communication_deliveries` row itself IS that
     * evidence (brief §20: "do not add this table if an existing
     * ledger already models this cleanly" -- for the allowed case, it
     * already does). This keeps row growth bounded to the genuinely
     * interesting case instead of doubling row volume for every
     * eligible recipient on every publish.
     *
     * Keyed by `(school_id, message_id, recipient_user_id, channel)`
     * -- deliberately NOT a `recipient_id` FK against
     * communication_recipients. A recipient who fails the final
     * eligibility re-check at publish time (root cause:
     * `recipient_ineligible`) never gets a CommunicationRecipient row
     * created for them at all (App\Domain\Communications\Application\AnnouncementService::publish()),
     * so a suppression decision for THAT case would have nothing to
     * FK against; a plain `recipient_user_id` (App\Models\User) works
     * for every suppression reason uniformly, eligible or not.
     *
     * APPEND-ONLY at the database privilege level
     * (TenantRls::makeAppendOnly), same as communication_delivery_attempts/
     * communication_announcement_recipients -- a policy decision is a
     * fact about what was decided at publish time and is never edited.
     * `unique(message_id, recipient_user_id, channel)` is defensive
     * idempotency (root CLAUDE.md rule 30 discipline) even though
     * App\Domain\Communications\Application\AnnouncementService::publish()'s
     * own atomic claim already prevents this table from being written
     * twice for the same message under normal operation.
     */
    public function up(): void
    {
        Schema::create('communication_delivery_policy_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('message_id');
            $table->foreignUuid('recipient_user_id')->constrained('users');
            $table->string('channel');
            $table->string('reason');
            $table->timestamps();

            $table->unique(['message_id', 'recipient_user_id', 'channel'], 'cdpd_message_recipient_channel_unique');
            $table->index('school_id');
            $table->index(['message_id']);

            $table->foreign(['message_id', 'school_id'], 'cdpd_message_school_foreign')
                ->references(['id', 'school_id'])->on('communication_messages')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT communication_delivery_policy_decisions_channel_check '.
            "CHECK (channel IN ('in_app', 'email', 'sms', 'whatsapp', 'push'))"
        );
        DB::statement(
            'ALTER TABLE communication_delivery_policy_decisions ADD CONSTRAINT communication_delivery_policy_decisions_reason_check '.
            "CHECK (reason IN ('recipient_ineligible', 'unsupported_channel', 'school_optional_channel_disabled', 'school_required_channel_disabled', 'recipient_preference_disabled'))"
        );

        TenantRls::enable('communication_delivery_policy_decisions');
        TenantRls::makeAppendOnly('communication_delivery_policy_decisions');
    }

    public function down(): void
    {
        TenantRls::disable('communication_delivery_policy_decisions');
        Schema::dropIfExists('communication_delivery_policy_decisions');
    }
};
