<?php

namespace App\Domain\Communications\Application\Audience;

/**
 * Phase 5A.2 §8/§14: the output of resolving an Announcement's
 * audience definition into concrete, deduplicated identities -- never
 * persisted by itself (the caller decides whether this is a read-only
 * preview or the input to a durable snapshot,
 * App\Domain\Communications\Application\AnnouncementService::publish()
 * vs ::previewAudience()). `categoryBreakdown` only contains labels
 * that reflect real, currently-assignable School roles (brief §14:
 * "Only show categories that truly exist in current identity/role
 * architecture").
 *
 * Phase 5B.1: `guardianIds`/`studentIds` are separate identity spaces
 * from `userIds` -- a Guardian/Student id is NEVER a User id (root
 * CLAUDE.md's non-negotiable Phase 1A identity separation). A resolver
 * populates exactly one of the three arrays; AnnouncementService's
 * publish() handles each separately (the membership loop is unchanged
 * from Phase 5A, see docs/communication-hub/
 * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §6).
 */
final class ResolvedAudience
{
    /**
     * @param  array<int, string>  $userIds  deduplicated
     * @param  array<string, int>  $categoryBreakdown  label => count, sums to count($userIds) + count($guardianIds) + count($studentIds)
     * @param  array<int, string>  $guardianIds  deduplicated
     * @param  array<int, string>  $studentIds  deduplicated
     */
    public function __construct(
        public readonly array $userIds,
        public readonly array $categoryBreakdown,
        public readonly array $guardianIds = [],
        public readonly array $studentIds = [],
    ) {}

    public function count(): int
    {
        return count($this->userIds) + count($this->guardianIds) + count($this->studentIds);
    }

    public function isEmpty(): bool
    {
        return $this->userIds === [] && $this->guardianIds === [] && $this->studentIds === [];
    }
}
