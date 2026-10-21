<?php

namespace App\Support\Approval;

use InvalidArgumentException;

/**
 * ORG.8A (ADR 0076 §58): the Users who MATERIALLY took part in one approval
 * item -- created, requested, prepared, calculated or amended it -- keyed by
 * how they took part. The owning domain builds it from durable facts on its
 * own rows (never today's role or Campus, audit text or display names).
 * Per item: taking part in one item never disqualifies anyone from another.
 */
final readonly class ApprovalParticipants
{
    /**
     * @param  array<string, list<string>>  $participants  participation (e.g. 'requester') => User ids
     */
    public function __construct(
        public string $workflow,
        public string $itemId,
        private array $participants,
    ) {
        if ($participants === []) {
            throw new InvalidArgumentException("{$workflow}: an approval item always has at least one participant.");
        }
    }

    /** The first participation of $userId in this item, or null when they took no part. */
    public function participationOf(string $userId): ?string
    {
        foreach ($this->participants as $participation => $userIds) {
            if (in_array($userId, $userIds, true)) {
                return $participation;
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->participants;
    }
}
