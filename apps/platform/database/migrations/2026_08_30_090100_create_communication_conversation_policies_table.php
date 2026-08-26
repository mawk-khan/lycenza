<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5D.1 §16 -- a compact, SINGLETON-per-School override of the
     * safeguarding defaults for private Student/Guardian conversation
     * participation, mirroring the exact "no row = system default"
     * shape `communication_channel_policies` established (Phase 5A.5) --
     * one row per School (not one per channel/type, since there are
     * only two narrow booleans here, not an open catalog), so a single
     * `unique(school_id)` is sufficient rather than a compound key.
     *
     * System defaults when no row exists
     * (App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService::defaultPolicy()):
     * Guardian conversations ALLOWED (still gated by the
     * `communications.conversations.guardians` capability -- brief §16's
     * "disabled or capability-controlled" resolved as capability-
     * controlled, matching how every other communications.* capability
     * in this codebase already gates an action rather than a school
     * toggle); Student conversations DISALLOWED (brief §16's stricter,
     * conservative default -- a School must explicitly opt in, on top
     * of a staff member separately needing the
     * `communications.conversations.students` capability).
     */
    public function up(): void
    {
        Schema::create('communication_conversation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->boolean('allow_guardian_conversations')->default(true);
            $table->boolean('allow_student_conversations')->default(false);
            $table->timestamps();

            $table->unique('school_id');
        });

        TenantRls::enable('communication_conversation_policies');
    }

    public function down(): void
    {
        TenantRls::disable('communication_conversation_policies');
        Schema::dropIfExists('communication_conversation_policies');
    }
};
