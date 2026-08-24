<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5B.1 §13/§14: resolves the Guardians of the Student list
 * authored in `communication_announcement_domain_audience_members`
 * (`student_id` rows -- the INPUT is Students, the OUTPUT is their
 * Guardians) for `audience_type = guardians_of_students`.
 *
 * Phase 5B.3 §10: the actual Student-ids-to-Guardian-ids projection
 * (relationship eligibility + dedup) now lives in the shared
 * GuardianProjectionResolver, reused by GradeAudienceResolver/
 * SectionAudienceResolver too rather than triplicated.
 */
class GuardiansOfStudentsAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianProjectionResolver $guardianProjection,
    ) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::GuardiansOfStudents;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        $studentIds = $this->context->withSchool($announcement->school, fn () => DB::table('communication_announcement_domain_audience_members as caddam')
            ->join('students as s', 's.id', '=', 'caddam.student_id')
            ->where('caddam.announcement_id', $announcement->id)
            ->where('caddam.school_id', $announcement->school_id)
            ->where('s.status', 'active')
            ->distinct()
            ->pluck('s.id')
            ->all());

        $guardianIds = $this->guardianProjection->forStudentIds($announcement->school, $studentIds);

        return new ResolvedAudience(
            userIds: [],
            categoryBreakdown: $guardianIds === [] ? [] : ['Guardians of Selected Students' => count($guardianIds)],
            guardianIds: $guardianIds,
        );
    }
}
