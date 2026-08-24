<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5B.1 §12: resolves the explicit Guardian list authored in
 * `communication_announcement_domain_audience_members` (`guardian_id`
 * rows) for `audience_type = guardian`. Re-validates same-school +
 * `status = 'active'` at resolve time, the identical pattern
 * StudentAudienceResolver just established.
 */
class GuardianAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::Guardian;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement) {
            $guardianIds = DB::table('communication_announcement_domain_audience_members as caddam')
                ->join('guardians as g', 'g.id', '=', 'caddam.guardian_id')
                ->where('caddam.announcement_id', $announcement->id)
                ->where('caddam.school_id', $announcement->school_id)
                ->where('g.status', 'active')
                ->distinct()
                ->pluck('g.id')
                ->all();

            return new ResolvedAudience(
                userIds: [],
                categoryBreakdown: $guardianIds === [] ? [] : ['Guardians' => count($guardianIds)],
                guardianIds: $guardianIds,
            );
        });
    }
}
