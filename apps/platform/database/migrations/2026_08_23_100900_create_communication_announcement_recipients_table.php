<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.2 §10/§24 -- the durable, IMMUTABLE record of exactly
     * who an Announcement's audience resolved to AT PUBLISH TIME,
     * distinct from `communication_recipients` (Phase 5A.1's
     * message-centric "this message is meant for this person" table).
     * If three members later leave the School, this table must still
     * show the original resolved count -- never re-resolved on view.
     *
     * APPEND-ONLY at the database privilege level
     * (TenantRls::makeAppendOnly), same as
     * communication_delivery_attempts. `unique(announcement_id,
     * user_id)` is both the deduplication guarantee (brief §11) and,
     * combined with the Announcement's own atomic draft->published
     * status claim in App\Domain\Communications\Application\
     * AnnouncementService::publish(), the idempotent-publish guarantee
     * (brief §18) -- a second publish attempt for an already-published
     * Announcement never reaches this insert at all, since the status
     * claim itself is the single-winner gate.
     *
     * `school_membership_id` restricts deletion (the audit trail must
     * survive even if a membership row were ever hard-deleted, unlike
     * the child join table above); `user_id` is a plain FK for direct
     * correlation with communication_recipients/communication_messages,
     * the same identity shape the rest of the Communications domain
     * already uses.
     */
    public function up(): void
    {
        Schema::create('communication_announcement_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('announcement_id');
            $table->uuid('school_membership_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id'], 'car_announcement_user_unique');
            $table->index('school_id');
            $table->index('announcement_id');

            $table->foreign(['announcement_id', 'school_id'], 'car_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->cascadeOnDelete();

            $table->foreign(['school_membership_id', 'school_id'], 'car_membership_school_foreign')
                ->references(['id', 'school_id'])->on('school_memberships')
                ->restrictOnDelete();
        });

        TenantRls::enable('communication_announcement_recipients');
        TenantRls::makeAppendOnly('communication_announcement_recipients');
    }

    public function down(): void
    {
        TenantRls::disable('communication_announcement_recipients');
        Schema::dropIfExists('communication_announcement_recipients');
    }
};
