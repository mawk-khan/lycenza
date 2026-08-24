<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5A.12 §14/§15/§17/§64/§66/§67 -- the approval REQUEST/
     * DECISION ledger: "was THIS specific reviewed version approved or
     * rejected, by whom, when." One row per submission cycle, never
     * reused across cycles -- an Announcement rejected then resubmitted
     * gets a brand-new row with a brand-new `fingerprint` (brief §64's
     * "Request #1 -> Rejected ... Request #2 -> Approved" example), so
     * every prior decision remains permanently, individually
     * inspectable (never overwritten).
     *
     * `status` transitions in place (pending -> approved|rejected|
     * cancelled|invalidated) via the SAME atomic conditional-UPDATE
     * discipline `communication_deliveries`/`communication_announcements`
     * already use -- NOT append-only at the database privilege level
     * (unlike `communication_delivery_attempts`), because a real
     * single-row transition genuinely needs to happen here, mirroring
     * `communication_deliveries.status` exactly (mutable, RLS-protected,
     * never hand-rewritten after the fact by anything other than this
     * one authoritative transition).
     *
     * `fingerprint` (SHA-256 hex, 64 chars) is computed server-side by
     * App\Domain\Communications\Application\Approval\CommunicationApprovalFingerprint
     * over the announcement's approval-sensitive fields at submission
     * time -- never client-supplied (brief §17).
     *
     * `snapshot` (brief §66/§67's documented tradeoff): a COMPACT,
     * already-safe structured JSON snapshot of the same approval-
     * sensitive fields the fingerprint was computed from (title, body,
     * audience definition, channels, priority, requirement, dispatch
     * mode, attachment checksums) -- never a full Eloquent dump, never
     * attachment BYTES (only their already-computed
     * `checksum_sha256` identities). This is what lets the approval
     * detail/history page show "what was actually reviewed" for an
     * OLD request even after the live Announcement has since been
     * edited past it (the live `communication_announcements.title`/
     * `body` are overwritten in place by `updateDraft()`, so without
     * this snapshot a superseded request's reviewed content would be
     * unrecoverable). No new privacy exposure: every field here is
     * already stored in plaintext on the Announcement/attachment rows
     * themselves.
     *
     * `unique(announcement_id) where status = 'pending'` (a Postgres
     * partial unique index) is the authoritative, concurrency-safe "at
     * most one active pending request per Announcement" guarantee
     * (brief §27/§61) -- the same partial-unique-index pattern
     * `academic_years_one_active_per_school` already established
     * (root CLAUDE.md rule 64), reused here rather than a hand-written
     * check-then-insert.
     *
     * Composite FK against `communication_announcements(id, school_id)`
     * -- root CLAUDE.md rule 70, the same pattern
     * `communication_announcement_recipients` already uses.
     */
    public function up(): void
    {
        Schema::create('communication_approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('announcement_id');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->timestamp('requested_at');
            $table->string('fingerprint', 64);
            $table->jsonb('snapshot');
            $table->string('status')->default('pending');
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index('school_id');
            $table->index(['announcement_id', 'status']);
            $table->index(['school_id', 'status', 'requested_at']);

            $table->foreign(['announcement_id', 'school_id'], 'car_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')
                ->cascadeOnDelete();
        });

        // Brief §27/§61: a real Postgres partial unique index -- only
        // rows with status = 'pending' participate, so a School may
        // freely accumulate many terminal (approved/rejected/cancelled/
        // invalidated) requests for the same Announcement over time,
        // but never two simultaneously-pending ones.
        DB::statement(
            'CREATE UNIQUE INDEX communication_approval_requests_one_pending_per_announcement '.
            'ON communication_approval_requests (announcement_id) WHERE (status = \'pending\')'
        );

        DB::statement(
            'ALTER TABLE communication_approval_requests ADD CONSTRAINT communication_approval_requests_status_check '.
            "CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'invalidated'))"
        );
        DB::statement(
            'ALTER TABLE communication_approval_requests ADD CONSTRAINT communication_approval_requests_fingerprint_check '.
            'CHECK (char_length(fingerprint) = 64)'
        );

        TenantRls::enable('communication_approval_requests');
    }

    public function down(): void
    {
        TenantRls::disable('communication_approval_requests');
        Schema::dropIfExists('communication_approval_requests');
    }
};
