<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.2 §9: resolves the explicit member list authored in
 * `communication_announcement_audience_members` (§24). Re-validates
 * eligibility at resolve time (§13, not just at authoring time --
 * `sm.status = 'active'`) since a member's status can change between
 * drafting and publishing an announcement, and excludes the
 * announcement's own creator (the same "sender is not their own
 * recipient" invariant Phase 5A.1's CommunicationMessageService
 * already established for thread messages).
 */
class IndividualMembersAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::Individual;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement) {
            $userIds = DB::table('communication_announcement_audience_members as caam')
                ->join('school_memberships as sm', 'sm.id', '=', 'caam.school_membership_id')
                ->where('caam.announcement_id', $announcement->id)
                ->where('sm.status', 'active')
                ->where('sm.user_id', '!=', $announcement->created_by_user_id)
                ->distinct()
                ->pluck('sm.user_id')
                ->all();

            return new ResolvedAudience($userIds, $userIds === [] ? [] : ['Selected Members' => count($userIds)]);
        });
    }
}
