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
 * Relationship-eligibility rule (documented, deliberately narrow --
 * brief §13's "if product semantics are ambiguous, defer rather than
 * guess"): a StudentGuardianRelationship is considered eligible for
 * general school communication only when `is_primary = true` OR
 * `is_legal_guardian = true`. A relationship that is ONLY
 * `is_emergency_contact`/`is_authorized_pickup` (and neither primary
 * nor legal) is excluded -- those flags authorize contact in an
 * emergency/pickup context specifically, not routine school
 * communication, and conflating them would silently over-notify
 * someone the school never intended as a general communication
 * recipient. See docs/communication-hub/
 * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §11.
 *
 * A Guardian related to multiple selected Students collapses to ONE
 * logical recipient -- the `distinct()` on `g.id` below, exactly
 * mirroring GuardianAudienceResolver's own single-Guardian-per-row
 * output shape.
 */
class GuardiansOfStudentsAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::GuardiansOfStudents;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement) {
            $guardianIds = DB::table('communication_announcement_domain_audience_members as caddam')
                ->join('students as s', 's.id', '=', 'caddam.student_id')
                ->join('student_guardian_relationships as sgr', 'sgr.student_id', '=', 's.id')
                ->join('guardians as g', 'g.id', '=', 'sgr.guardian_id')
                ->where('caddam.announcement_id', $announcement->id)
                ->where('caddam.school_id', $announcement->school_id)
                ->where('s.status', 'active')
                ->where('g.status', 'active')
                ->where(fn ($query) => $query->where('sgr.is_primary', true)->orWhere('sgr.is_legal_guardian', true))
                ->distinct()
                ->pluck('g.id')
                ->all();

            return new ResolvedAudience(
                userIds: [],
                categoryBreakdown: $guardianIds === [] ? [] : ['Guardians of Selected Students' => count($guardianIds)],
                guardianIds: $guardianIds,
            );
        });
    }
}
