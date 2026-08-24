<?php

namespace App\Domain\Communications\Application\Audience;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5B.3 §10: the ONE centralized "given these Student ids, which
 * Guardians are eligible general-communication recipients" projection
 * -- extracted out of GuardiansOfStudentsAudienceResolver (Phase 5B.1)
 * so a third caller (GradeAudienceResolver/SectionAudienceResolver,
 * both projecting an academic cohort's Students to their Guardians)
 * never duplicates this predicate a second time.
 *
 * Relationship-eligibility rule (unchanged from Phase 5B.1, brief
 * §10/§17): a StudentGuardianRelationship is eligible only when
 * `is_primary = true` OR `is_legal_guardian = true` --
 * `is_emergency_contact`/`is_authorized_pickup`-only relationships are
 * excluded (docs/communication-hub/
 * PHASE-5B-1-STUDENT-GUARDIAN-AUDIENCE-REACHABILITY.md §11).
 *
 * A Guardian shared by multiple input Students collapses to ONE
 * logical recipient (`distinct()` on `g.id`).
 */
class GuardianProjectionResolver
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<int, string>  $studentIds
     * @return array<int, string>
     */
    public function forStudentIds(School $school, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn () => DB::table('student_guardian_relationships as sgr')
            ->join('students as s', 's.id', '=', 'sgr.student_id')
            ->join('guardians as g', 'g.id', '=', 'sgr.guardian_id')
            ->whereIn('sgr.student_id', $studentIds)
            ->where('s.school_id', $school->id)
            ->where('s.status', 'active')
            ->where('g.status', 'active')
            ->where(fn ($query) => $query->where('sgr.is_primary', true)->orWhere('sgr.is_legal_guardian', true))
            ->distinct()
            ->pluck('g.id')
            ->all());
    }
}
