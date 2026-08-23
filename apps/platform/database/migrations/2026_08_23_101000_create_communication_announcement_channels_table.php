<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.3 §16/§17/§34 -- the explicit, normalized set of
     * delivery channels an Announcement requests, replacing what the
     * brief explicitly warns against (a hard-coded `send_email`
     * boolean). Same shape as
     * communication_announcement_audience_members: a small per-
     * announcement child table, composite FK, unique constraint doing
     * double duty as both "no duplicate channel request" AND part of
     * the publish-idempotency story.
     *
     * A row existing here means "this channel was explicitly
     * requested for this Announcement" -- NOT "a delivery for this
     * channel exists yet" (that's communication_deliveries, created at
     * publish time per requested channel per resolved recipient).
     * `channel` is deliberately CHECK-restricted to
     * ('in_app','email') even though
     * App\Domain\Communications\Domain\CommunicationChannel already
     * has Sms/WhatsApp/Push cases -- this table is where "requestable
     * today" is enforced, not the channel enum itself (widening this
     * CHECK later, when a real SMS/WhatsApp/Push driver lands, is
     * additive, not destructive).
     *
     * Critical invariant (brief §15): this table's ABSENCE of an
     * 'email' row for an Announcement is what keeps every
     * pre-Phase-5A.3 and IN_APP-only Announcement from suddenly
     * emailing anyone --
     * App\Domain\Communications\Application\AnnouncementService::createDraft()
     * always writes an explicit 'in_app' row (and an 'email' row ONLY
     * when the composer explicitly requested it), so publish() never
     * has to guess a default that could silently widen delivery.
     */
    public function up(): void
    {
        Schema::create('communication_announcement_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('announcement_id');
            $table->string('channel');
            $table->timestamps();

            $table->unique(['announcement_id', 'channel'], 'cac_announcement_channel_unique');
            $table->index('school_id');

            $table->foreign(['announcement_id', 'school_id'], 'cac_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_announcement_channels ADD CONSTRAINT communication_announcement_channels_channel_check '.
            "CHECK (channel IN ('in_app', 'email'))"
        );

        TenantRls::enable('communication_announcement_channels');
    }

    public function down(): void
    {
        TenantRls::disable('communication_announcement_channels');
        Schema::dropIfExists('communication_announcement_channels');
    }
};
