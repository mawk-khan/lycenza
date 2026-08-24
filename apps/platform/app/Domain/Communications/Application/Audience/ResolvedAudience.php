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
 * architecture" -- there is no Teacher/Staff/Student identity to break
 * out yet, see docs/communication-hub/PHASE-5A-2-ANNOUNCEMENTS-AUDIENCES.md).
 */
final class ResolvedAudience
{
    /**
     * @param  array<int, string>  $userIds  deduplicated
     * @param  array<string, int>  $categoryBreakdown  label => count, sums to count($userIds)
     */
    public function __construct(
        public readonly array $userIds,
        public readonly array $categoryBreakdown,
    ) {}

    public function count(): int
    {
        return count($this->userIds);
    }

    public function isEmpty(): bool
    {
        return $this->userIds === [];
    }
}
