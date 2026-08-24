<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.1 §2.6: one row per (recipient, channel) LOGICAL delivery
     * -- claimed via the UNIQUE constraint below BEFORE any send is
     * attempted, exactly the same idempotency shape
     * webhook_deliveries(webhook_endpoint_id, event_id) already
     * established (root CLAUDE.md rule 30/31; foundation doc §2.6). A
     * logical delivery may have MANY real send attempts -- those live in
     * their own append-only communication_delivery_attempts table, never
     * inline here.
     *
     * `status` is the full closed state set from the Phase 5A brief §11:
     *
     *   pending -> queued -> sending -> delivered            (success, in_app today)
     *                               \-> failed/bounced/rejected/expired  (future real channels)
     *                     -> cancelled                                    (future)
     *
     * `processing_lease_expires_at`/`next_attempt_at` mirror
     * webhook_deliveries' atomic-claim/backoff columns so the same
     * App\Jobs\ProcessCommunicationDeliveryJob code path a future real
     * provider channel uses is already exercised by `in_app` today, even
     * though `in_app` can never transiently fail.
     *
     * `destination_snapshot` is unused for `in_app` (no external
     * destination) -- reserved for a future channel to record exactly
     * what address/number a provider was asked to deliver to, for audit
     * purposes independent of whatever the person's stored contact
     * details later become.
     */
    public function up(): void
    {
        Schema::create('communication_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('recipient_id');
            $table->string('channel'); // in_app|email|sms|whatsapp|push
            $table->string('status')->default('pending');
            $table->jsonb('destination_snapshot')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processing_lease_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->unique(['recipient_id', 'channel']);
            $table->index('school_id');
            $table->index(['status', 'next_attempt_at']);

            $table->foreign(['recipient_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_recipients')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_deliveries ADD CONSTRAINT communication_deliveries_channel_check '.
            "CHECK (channel IN ('in_app', 'email', 'sms', 'whatsapp', 'push'))"
        );
        DB::statement(
            'ALTER TABLE communication_deliveries ADD CONSTRAINT communication_deliveries_status_check '.
            "CHECK (status IN ('pending', 'queued', 'sending', 'accepted', 'sent', 'delivered', 'read', 'failed', 'bounced', 'rejected', 'expired', 'cancelled'))"
        );

        TenantRls::enable('communication_deliveries');
    }

    public function down(): void
    {
        TenantRls::disable('communication_deliveries');
        Schema::dropIfExists('communication_deliveries');
    }
};
