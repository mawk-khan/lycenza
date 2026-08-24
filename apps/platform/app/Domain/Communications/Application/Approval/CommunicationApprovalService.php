<?php

namespace App\Domain\Communications\Application\Approval;

use App\Domain\Communications\Application\Exceptions\ApprovalAlreadyDecidedException;
use App\Domain\Communications\Application\Exceptions\ApprovalAlreadyPendingException;
use App\Domain\Communications\Application\Exceptions\ApprovalNotRequiredException;
use App\Domain\Communications\Application\Exceptions\EmergencyCannotUseApprovalWorkflowException;
use App\Domain\Communications\Application\Exceptions\InvalidAnnouncementTransitionException;
use App\Domain\Communications\Application\Exceptions\RejectionReasonRequiredException;
use App\Domain\Communications\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.12 §51 -- the sole write path for the approval workflow
 * (submit/approve/reject/withdraw/invalidate), mirroring
 * App\Domain\Communications\Application\AnnouncementService's own
 * shape: validate -> atomic conditional-UPDATE claim -> write -> audit,
 * one transaction. Controllers never manipulate
 * CommunicationApprovalRequest rows directly (brief §51).
 *
 * Keeps `communication_announcements.status` and
 * `communication_approval_requests.status` consistent BY CONSTRUCTION
 * -- every method here that changes one changes the other in the SAME
 * transaction (brief §16's "avoid duplicating approval state ...
 * without a clear source of truth": `communication_announcements.status`
 * is the source of truth for "what state is this Announcement in
 * right now"; `communication_approval_requests` is the source of truth
 * for "who requested/decided, when, and against what reviewed
 * content").
 */
class CommunicationApprovalService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CommunicationApprovalPolicyService $policy,
        private readonly CommunicationApprovalFingerprint $fingerprint,
    ) {}

    /**
     * Brief §27 -- valid only from `draft`, only when current policy
     * actually requires approval, and never for Emergency.
     */
    public function submit(CommunicationAnnouncement $announcement, User $actor): CommunicationApprovalRequest
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            if ($announcement->isEmergency()) {
                throw new EmergencyCannotUseApprovalWorkflowException;
            }

            $requirement = $this->policy->evaluate($announcement->school, $announcement);

            if (! $requirement->required) {
                throw new ApprovalNotRequiredException;
            }

            return DB::transaction(function () use ($announcement, $actor, $requirement) {
                $claimed = CommunicationAnnouncement::query()
                    ->where('id', $announcement->id)
                    ->where('status', 'draft')
                    ->update(['status' => 'pending_approval']);

                $fresh = $announcement->fresh();

                if ($claimed === 0) {
                    throw new InvalidAnnouncementTransitionException($fresh->status, 'submit for approval');
                }

                $snapshot = $this->fingerprint->snapshot($fresh);
                $hash = $this->fingerprint->hash($snapshot);

                try {
                    $request = CommunicationApprovalRequest::query()->create([
                        'school_id' => $fresh->school_id,
                        'announcement_id' => $fresh->id,
                        'requested_by_user_id' => $actor->id,
                        'requested_at' => now(),
                        'fingerprint' => $hash,
                        'snapshot' => $snapshot,
                        'status' => 'pending',
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Brief §27/§61: defensive only -- the announcement
                    // claim above already guarantees this can't
                    // actually happen (only one caller can ever win
                    // 'draft' -> 'pending_approval' for a given row),
                    // the same "database constraint is the real
                    // guarantee, not the check" discipline as
                    // App\Domain\Communications\Application\CommunicationDeliveryFactory::createDelivery().
                    throw new ApprovalAlreadyPendingException;
                }

                $this->audit->school($fresh->school, 'announcement.approval_requested', actor: $actor, subject: $fresh, metadata: [
                    'reasons' => $requirement->reasons,
                    'fingerprint' => $hash,
                ]);

                return $request;
            });
        });
    }

    /**
     * Brief §13/§31 -- separation of duties + atomic single-winner
     * decision. `$note` is optional for an approval.
     */
    public function approve(CommunicationApprovalRequest $request, User $approver, ?string $note = null): CommunicationApprovalRequest
    {
        return $this->decide($request, $approver, 'approved', $note);
    }

    /**
     * Brief §30/§65 -- rejecting always requires a non-empty, bounded
     * reason.
     */
    public function reject(CommunicationApprovalRequest $request, User $approver, string $reason): CommunicationApprovalRequest
    {
        if (trim($reason) === '') {
            throw new RejectionReasonRequiredException;
        }

        return $this->decide($request, $approver, 'rejected', $reason);
    }

    private function decide(CommunicationApprovalRequest $request, User $approver, string $outcome, ?string $note): CommunicationApprovalRequest
    {
        return $this->context->withSchool($request->school, function () use ($request, $approver, $outcome, $note) {
            // Brief §13: mandatory foundation rule, no self-approval
            // path exists in 5A.12.
            if ($request->requested_by_user_id === $approver->id) {
                throw new SelfApprovalNotAllowedException;
            }

            return DB::transaction(function () use ($request, $approver, $outcome, $note) {
                // Brief §31/§61/§78: the conditional UPDATE's
                // `WHERE status = 'pending'` is the actual concurrency
                // guarantee -- two simultaneous decision attempts race
                // on this ONE row, Postgres serializes them, and
                // exactly one UPDATE affects a row.
                $claimed = CommunicationApprovalRequest::query()
                    ->where('id', $request->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => $outcome,
                        'decided_by_user_id' => $approver->id,
                        'decided_at' => now(),
                        'decision_note' => $note,
                    ]);

                if ($claimed === 0) {
                    throw new ApprovalAlreadyDecidedException;
                }

                $announcementStatus = $outcome === 'approved' ? 'approved' : 'rejected';

                CommunicationAnnouncement::query()
                    ->where('id', $request->announcement_id)
                    ->where('status', 'pending_approval')
                    ->update(['status' => $announcementStatus]);

                $fresh = $request->fresh();
                $announcement = $fresh->announcement;

                $this->audit->school($announcement->school, "announcement.{$announcementStatus}", actor: $approver, subject: $announcement, metadata: array_filter([
                    'fingerprint' => $fresh->fingerprint,
                    'decisionNote' => $note,
                ], fn ($value) => $value !== null));

                return $fresh;
            });
        });
    }

    /**
     * Brief §29/§37 -- the requester (or a communications.manage-
     * capable actor) may withdraw a pending request, returning the
     * Announcement to editable Draft. Historical evidence (the request
     * row itself, now `cancelled`) is preserved, never deleted (brief
     * §37).
     */
    public function withdraw(CommunicationAnnouncement $announcement, User $actor): CommunicationAnnouncement
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            $pending = CommunicationApprovalRequest::query()
                ->where('announcement_id', $announcement->id)
                ->where('status', 'pending')
                ->first();

            if ($pending === null) {
                throw new InvalidAnnouncementTransitionException($announcement->status, 'withdraw approval for');
            }

            return DB::transaction(function () use ($announcement, $actor, $pending) {
                $claimed = CommunicationApprovalRequest::query()
                    ->where('id', $pending->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'decided_by_user_id' => $actor->id,
                        'decided_at' => now(),
                    ]);

                if ($claimed === 0) {
                    throw new ApprovalAlreadyDecidedException;
                }

                CommunicationAnnouncement::query()
                    ->where('id', $announcement->id)
                    ->where('status', 'pending_approval')
                    ->update(['status' => 'draft']);

                $fresh = $announcement->fresh();

                $this->audit->school($fresh->school, 'announcement.approval_withdrawn', actor: $actor, subject: $fresh, metadata: [
                    'requestId' => $pending->id,
                ]);

                return $fresh;
            });
        });
    }

    /**
     * Brief §35/§21-§25 -- called by
     * App\Domain\Communications\Application\AnnouncementService::updateDraft()
     * and App\Domain\Communications\Application\CommunicationAttachmentService
     * after ANY content mutation. Fast no-op path for the overwhelming
     * majority of Schools that never use approval at all (brief §8's
     * safe default: one cheap indexed lookup, nothing more, when there
     * is no active approved request). No semantic-diff heuristics
     * (brief §35) -- recomputes the full canonical fingerprint and
     * compares it byte-for-byte against the active request's stored
     * one.
     */
    public function invalidateIfFingerprintChanged(CommunicationAnnouncement $announcement, User $actor): void
    {
        $this->context->withSchool($announcement->school, function () use ($announcement, $actor) {
            $fresh = $announcement->fresh();

            if (! in_array($fresh->status, ['approved', 'scheduled'], true)) {
                return;
            }

            $active = CommunicationApprovalRequest::query()
                ->where('announcement_id', $fresh->id)
                ->where('status', 'approved')
                ->orderByDesc('decided_at')
                ->first();

            if ($active === null) {
                return;
            }

            $currentHash = $this->fingerprint->hash($this->fingerprint->snapshot($fresh));

            if ($currentHash === $active->fingerprint) {
                return;
            }

            DB::transaction(function () use ($fresh, $actor, $active) {
                $claimed = CommunicationApprovalRequest::query()
                    ->where('id', $active->id)
                    ->where('status', 'approved')
                    ->update(['status' => 'invalidated']);

                if ($claimed === 0) {
                    // Raced with a concurrent decision/invalidation --
                    // whichever transaction actually won already
                    // handles the announcement-status side effect.
                    return;
                }

                $wasScheduled = $fresh->status === 'scheduled';

                CommunicationAnnouncement::query()
                    ->where('id', $fresh->id)
                    ->whereIn('status', ['approved', 'scheduled'])
                    ->update(array_merge(
                        ['status' => 'draft'],
                        $wasScheduled ? ['scheduled_at' => null, 'scheduled_by_user_id' => null] : [],
                    ));

                $this->audit->school($fresh->school, 'announcement.approval_invalidated', actor: $actor, subject: $fresh, metadata: [
                    'requestId' => $active->id,
                    'previousFingerprint' => $active->fingerprint,
                ]);
            });
        });
    }

    /**
     * Brief §32/§62/§82 -- true only when an `approved` request exists
     * for this announcement AND its stored fingerprint still matches
     * the announcement's CURRENT content, recomputed fresh right now.
     * Never trusts `status = 'approved'` alone (brief §32's "Do not
     * trust an `approved` flag alone") -- this is the defense-in-depth
     * check App\Domain\Communications\Application\AnnouncementService::publish()/
     * schedule() call immediately before their own atomic claim, so
     * even a hypothetical future mutation path that forgot to call
     * invalidateIfFingerprintChanged() cannot authorize publishing
     * changed content.
     */
    public function currentlyApprovedAndValid(CommunicationAnnouncement $announcement): bool
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement) {
            $fresh = $announcement->fresh();

            $active = CommunicationApprovalRequest::query()
                ->where('announcement_id', $fresh->id)
                ->where('status', 'approved')
                ->orderByDesc('decided_at')
                ->first();

            if ($active === null) {
                return false;
            }

            return $this->fingerprint->hash($this->fingerprint->snapshot($fresh)) === $active->fingerprint;
        });
    }

    /**
     * @see CommunicationApprovalPolicyService::evaluate()
     */
    public function requirement(CommunicationAnnouncement $announcement): CommunicationApprovalRequirement
    {
        return $this->context->withSchool(
            $announcement->school,
            fn () => $this->policy->evaluate($announcement->school, $announcement->fresh()),
        );
    }

    /**
     * Brief §42-§44 -- the latest pending-or-decided request for
     * display (announcement detail, approval queue/detail pages).
     */
    public function latestRequest(CommunicationAnnouncement $announcement): ?CommunicationApprovalRequest
    {
        return $this->context->withSchool($announcement->school, fn () => CommunicationApprovalRequest::query()
            ->where('announcement_id', $announcement->id)
            ->orderByDesc('requested_at')
            ->first());
    }
}
