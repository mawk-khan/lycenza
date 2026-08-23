<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.6 §7/§18/§19 -- the narrow, Communications-scoped file
     * abstraction ADR 0012 anticipates but does not itself implement
     * ("this ADR fixes the shape future implementation must follow").
     * This table owns its own file metadata directly rather than
     * pretending a Documents module already exists (brief §4: do not
     * build the full Document Management module here).
     *
     * `communication_announcement_id` is NOT NULL: every attachment in
     * this checkpoint originates from the Announcement composer, the
     * only editable-draft surface that exists before a canonical
     * CommunicationMessage is even created (AnnouncementService::publish()
     * creates the CommunicationMessage row itself, at publish time --
     * see that method). `communication_message_id` starts NULL and is
     * backfilled, in the SAME publish() transaction, once the message
     * exists -- never re-uploaded/re-copied, so the exact same row
     * (same checksum, same storage_path) simply gains a second,
     * channel-rendering-facing FK (brief §6/§25). Direct conversation-
     * thread-message attachments (CommunicationMessageService::send(),
     * which has no draft/edit phase at all) are out of scope for this
     * checkpoint -- see docs/communication-hub/PHASE-5A-6-ATTACHMENTS-RICH-CONTENT.md
     * §"Conversation thread attachments" for why, matching brief §48's
     * explicit allowance to defer when no composer UI exists to wire.
     *
     * `storage_path` is a server-generated key (UuidV7 + a normalized
     * extension), NEVER the raw uploaded filename (brief §18) --
     * `original_filename`/`safe_display_name` are display-only metadata,
     * never used to build a filesystem/object-storage path. `checksum_sha256`
     * exists for integrity/observability only -- deliberately NOT used
     * for cross-attachment/cross-School deduplication (brief §19: doing
     * so would let one School probabilistically learn whether another
     * School has uploaded an identical file, a side channel this
     * checkpoint refuses to open).
     */
    public function up(): void
    {
        Schema::create('communication_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('communication_announcement_id');
            $table->uuid('communication_message_id')->nullable();
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('safe_display_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index('communication_announcement_id');
            $table->index('communication_message_id');

            $table->foreign(['communication_announcement_id', 'school_id'], 'comm_attachments_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->cascadeOnDelete();

            $table->foreign(['communication_message_id', 'school_id'], 'comm_attachments_message_school_foreign')
                ->references(['id', 'school_id'])->on('communication_messages')
                ->nullOnDelete();
        });

        TenantRls::enable('communication_attachments');
    }

    public function down(): void
    {
        TenantRls::disable('communication_attachments');
        Schema::dropIfExists('communication_attachments');
    }
};
