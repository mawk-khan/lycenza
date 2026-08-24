<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5B.1 §11: resolves the explicit Student list authored in
 * `communication_announcement_domain_audience_members` (`student_id`
 * rows) for `audience_type = student`. Re-validates same-school +
 * `status = 'active'` at resolve time (Phase 5A.2's own
 * IndividualMembersAudienceResolver pattern) since a Student's status
 * can change between authoring and publishing. A Student has NO
 * reachable channel today (root CLAUDE.md non-negotiable identity
 * rule -- no User/SchoolMembership link, no canonical contact
 * endpoint) -- this resolver still produces a valid LOGICAL audience;
 * reachability is a separate concern AnnouncementService::publish()
 * decides when it snapshots these targets (docs/communication-hub/
 * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §9).
 */
class StudentAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::Student;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement) {
            $studentIds = DB::table('communication_announcement_domain_audience_members as caddam')
                ->join('students as s', 's.id', '=', 'caddam.student_id')
                ->where('caddam.announcement_id', $announcement->id)
                ->where('caddam.school_id', $announcement->school_id)
                ->where('s.status', 'active')
                ->distinct()
                ->pluck('s.id')
                ->all();

            return new ResolvedAudience(
                userIds: [],
                categoryBreakdown: $studentIds === [] ? [] : ['Students' => count($studentIds)],
                studentIds: $studentIds,
            );
        });
    }
}
