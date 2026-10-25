<?php

namespace App\Domain\Communications\Application\Audience\FeeReminder;

use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ORG.9D-2 (ADR 0076 §74): Finance's answer to "who owes what" for a fee
 * reminder -- a Communications-owned port that Payments implements
 * (Payments -> Communications, the documented direction: Communications
 * never reads a Finance table or service). The implementation reads the ONE
 * canonical outstanding read (the Outstanding fees page's), under the
 * initiator's CURRENT Finance authority and Campus scope, and never
 * computes a balance of its own.
 */
interface FeeReminderBalanceSource
{
    /**
     * Refuses unless `$initiator` may, right now, see the balances this
     * selection names (including the class filter's whole-School
     * enrollments authority).
     *
     * @throws AuthorizationException
     */
    public function assertCanSelect(School $school, User $initiator, FeeReminderSelection $selection): void;

    /**
     * A stable description of the initiator's CURRENT Finance scope for
     * balances ("school", "campus:<sorted ids>", or "none") -- bound into
     * the approval fingerprint, so a scope that widens or narrows after
     * approval makes that approval stale instead of silently changing the
     * population. Never throws.
     */
    public function scopeKey(School $school, User $initiator): string;

    /**
     * Every Student in the selection whose current outstanding is strictly
     * positive, read now.
     *
     * @return list<FeeReminderBalance>
     *
     * @throws AuthorizationException
     */
    public function balances(School $school, User $initiator, FeeReminderSelection $selection): array;
}
