<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\HR\Application\CurrentStaffMembers;

/**
 * ORG.9D-1 (ADR 0076 §73, PV1-10): the School's CURRENT staff, from HR's
 * one set-based identity read (CurrentStaffMembers) -- never a role,
 * capability, persona or email domain. Guardian-only, Student-only and
 * platform-only Users are never staff; a staff member who is also a
 * Guardian is included once, as staff. Resolved when previewed and again,
 * fresh, at publish (AnnouncementService::publish()), so a leaver is never
 * snapshotted and a new hire is. Whole-School only: Communications has no
 * Campus targeting. The creator is excluded, like SchoolWideAudienceResolver.
 * Communications receives User ids only -- no HR field.
 */
class StaffAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(private readonly CurrentStaffMembers $staff) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::Staff;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        $userIds = array_values(array_filter(
            $this->staff->userIds($announcement->school),
            fn (string $userId) => $userId !== (string) $announcement->created_by_user_id,
        ));

        return new ResolvedAudience($userIds, $userIds === [] ? [] : ['Staff' => count($userIds)]);
    }
}
