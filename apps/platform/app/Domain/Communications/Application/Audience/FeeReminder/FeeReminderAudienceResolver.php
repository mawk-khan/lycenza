<?php

namespace App\Domain\Communications\Application\Audience\FeeReminder;

use App\Domain\Communications\Application\Audience\CommunicationAudienceResolver;
use App\Domain\Communications\Application\Audience\ResolvedAudience;
use App\Domain\Communications\Application\Exceptions\FeeReminderAuthorityException;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Identity\Application\Portal\CurrentGuardianAccounts;
use App\Domain\Students\Application\StudentIdentityLookup;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ORG.9D-2 (ADR 0076 §74, PV1-11): a fee-due reminder's audience, resolved
 * in the order Finance truth -> recipients:
 *
 * 1. Finance (FeeReminderBalanceSource, implemented by Payments): every
 *    Student in the saved selection with a strictly positive CURRENT
 *    outstanding, under the CREATOR's current Finance authority and Campus
 *    scope -- the initiator's, re-checked on every resolution; losing it
 *    refuses (FeeReminderAuthorityException), never widens or narrows
 *    silently.
 * 2. Recipients (Identity's CurrentGuardianAccounts): the current Guardian
 *    User accounts of those Students -- never a Student account, a raw
 *    Guardian contact, an emergency contact or an inferred parent.
 * 3. One set of lines per User: a Guardian of several selected Students
 *    gets them all in one email; a User reached through several Students
 *    is one recipient. Each recipient keeps the Guardian persona it was
 *    reached as, so publish applies that Guardian's own email preference
 *    and consent (a fee reminder is never Required). Students with no eligible Guardian account are
 *    counted (`studentsWithoutRecipient`), never emailed some other way.
 *
 * Preview and publish both call this; only publish persists anything
 * (AnnouncementService::publish()). The creator is NOT excluded: a staff
 * member who is also a current Guardian receives their own reminder as a
 * Guardian.
 */
class FeeReminderAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(
        private readonly FeeReminderBalanceSource $balances,
        private readonly CurrentGuardianAccounts $guardians,
        private readonly StudentIdentityLookup $students,
    ) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::FeeReminder;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        $school = $announcement->school;
        $selection = $announcement->feeReminderSelection();
        $initiator = User::query()->findOrFail($announcement->created_by_user_id);
        $asOf = CarbonImmutable::now('UTC');

        try {
            $balances = $this->balances->balances($school, $initiator, $selection);
        } catch (AuthorizationException) {
            throw new FeeReminderAuthorityException;
        }

        $byStudent = [];
        foreach ($balances as $balance) {
            $byStudent[$balance->studentId] = $balance->outstanding;
        }
        $studentIds = array_keys($byStudent);
        $accounts = $this->guardians->byStudent($school, array_map('strval', $studentIds));
        $names = $this->students->labels($school, array_map('strval', array_keys($accounts)));

        $linesByUser = [];
        $guardianByUser = [];
        foreach ($accounts as $studentId => $guardianOfUser) {
            $amount = $byStudent[$studentId];
            foreach ($guardianOfUser as $userId => $guardianId) {
                $guardianByUser[(string) $userId] = $guardianId;
                $linesByUser[$userId][] = [
                    'studentName' => $names[$studentId]->displayName ?? '',
                    'amount' => $amount->amount(),
                    'currency' => $amount->currency(),
                ];
            }
        }
        foreach ($linesByUser as &$lines) {
            usort($lines, fn (array $a, array $b) => [$a['studentName'], $a['amount']] <=> [$b['studentName'], $b['amount']]);
        }
        unset($lines);
        ksort($linesByUser);

        $currency = $balances === [] ? 'INR' : $balances[0]->outstanding->currency();
        $total = array_reduce($balances, fn (Money $sum, FeeReminderBalance $b) => $sum->add($b->outstanding), Money::of('0.00', $currency));
        $userIds = array_map('strval', array_keys($linesByUser));

        return new ResolvedAudience(
            userIds: $userIds,
            categoryBreakdown: $userIds === [] ? [] : ['Guardians (fee reminder)' => count($userIds)],
            feeReminder: new FeeReminderAudience(
                asOf: $asOf,
                linesByUser: $linesByUser,
                guardianByUser: $guardianByUser,
                studentCount: count($studentIds),
                studentsWithoutRecipient: count($studentIds) - count($accounts),
                totalOutstanding: $total->amount(),
                currency: $currency,
            ),
        );
    }
}
