<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.2 §6/§24 -- the Announcement aggregate: one-to-many
     * School communication, distinct from a Thread (conversation
     * between known participants, Phase 5A.1). `title`/`body` are the
     * DRAFT content store -- the only place it can live before
     * publish, since App\Domain\Communications\Infrastructure\
     * CommunicationMessage has no draft state (it is created,
     * immutable, at send/publish time, Phase 5A.1 §2.4). Once
     * published, `message_id` links to the canonical, immutable
     * CommunicationMessage row created at publish time -- `title`/
     * `body` here become a historical copy, never re-edited after
     * publish (no edit endpoint exists once status != 'draft').
     *
     * `status`: draft|published|cancelled only. READY/PUBLISHING/
     * PARTIALLY_DELIVERED/FAILED from the brief §7's "may include" list
     * are deliberately NOT included -- none is reachable without
     * features 5A.2 does not implement (approval workflow, async
     * publish, non-in_app channels with real failure modes). Widening
     * this CHECK constraint later is additive, not destructive, when
     * one of those features actually lands (docs/communication-hub/
     * PHASE-5A-2-ANNOUNCEMENTS-AUDIENCES.md).
     *
     * `audience_type`: individual|school_wide (§9) -- campus_wide is
     * deliberately not implemented; no reliable Campus<->SchoolMembership
     * relationship exists yet to resolve it safely (see the phase doc).
     * `campus_id` is a reserved, informational tag only in this
     * checkpoint (mirrors communication_threads.campus_id from 5A.1) --
     * it does not drive audience resolution.
     */
    public function up(): void
    {
        Schema::create('communication_announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('campus_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->string('title');
            $table->text('body');
            $table->string('priority')->default('normal'); // normal|important|urgent|critical
            $table->string('status')->default('draft'); // draft|published|cancelled
            $table->string('audience_type'); // individual|school_wide
            $table->uuid('message_id')->nullable();
            $table->unsignedInteger('recipient_count')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'school_id']);
            $table->index('school_id');
            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'published_at']);

            $table->foreign(['campus_id', 'school_id'])
                ->references(['id', 'school_id'])->on('campuses')
                ->restrictOnDelete();

            $table->foreign(['message_id', 'school_id'])
                ->references(['id', 'school_id'])->on('communication_messages')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_priority_check '.
            "CHECK (priority IN ('normal', 'important', 'urgent', 'critical'))"
        );
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_status_check '.
            "CHECK (status IN ('draft', 'published', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide'))"
        );

        TenantRls::enable('communication_announcements');
    }

    public function down(): void
    {
        TenantRls::disable('communication_announcements');
        Schema::dropIfExists('communication_announcements');
    }
};
