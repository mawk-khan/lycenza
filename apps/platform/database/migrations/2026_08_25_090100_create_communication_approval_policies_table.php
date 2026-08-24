<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.12 §6/§7/§8 -- the School's approval-REQUIREMENT policy,
     * deliberately separate from the approval REQUEST/DECISION ledger
     * (communication_approval_requests, next migration): this table
     * answers "which announcements require approval," never "was this
     * specific one reviewed." One row per School (never per-channel --
     * mirrors App\Domain\Communications\Infrastructure\CommunicationChannelPolicy's
     * "no row means the safe default" shape, not its per-channel key).
     *
     * No row for a School (or every flag false) means "approval not
     * required for anything" -- brief §8's mandatory safe default: a
     * fresh Phase 5A.12 deployment never blocks a single existing
     * Announcement until a School explicitly opts in.
     *
     * `require_non_privileged_sender_approval` reuses the existing
     * `communications.manage` capability seam (brief §9's "only if
     * current role/capability architecture supports a clean
     * definition" -- it does: `communications.manage` is already the
     * exact "senior/trusted sender" boundary
     * AnnouncementController::create() uses for `canMarkRequired`) --
     * never a hard-coded role-name check (root CLAUDE.md rule 24).
     */
    public function up(): void
    {
        Schema::create('communication_approval_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->boolean('require_school_wide_approval')->default(false);
            $table->boolean('require_required_communication_approval')->default(false);
            $table->boolean('require_non_privileged_sender_approval')->default(false);
            $table->timestamps();

            $table->unique('school_id');
        });

        TenantRls::enable('communication_approval_policies');
    }

    public function down(): void
    {
        TenantRls::disable('communication_approval_policies');
        Schema::dropIfExists('communication_approval_policies');
    }
};
