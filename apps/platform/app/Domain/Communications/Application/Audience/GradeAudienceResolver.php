<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAcademicCohort;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5B.3 §6: resolves a GradeLevel academic-cohort audience
 * (`audience_type = grade`) against Phase 1B's `student_enrollments`
 * -- the sole authoritative source of current Student placement.
 * Communications never maintains a second enrollment model.
 *
 * `academic_year_id` is REQUIRED alongside `grade_level_id` -- a
 * GradeLevel is School-wide reference data reusable across every
 * AcademicYear (docs/communication-hub/
 * PHASE-5B-3-ACADEMIC-COHORT-AUDIENCES.md §"GradeLevel audience"), so
 * `grade_level_id` alone is never sufficient to identify a cohort.
 *
 * Re-resolved fresh on every call (publish, scheduled due-publish,
 * preview) -- never a materialized/frozen membership list (brief §3):
 * a Student who left the Grade before publication is excluded: one who
 * joined is included.
 */
class GradeAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianProjectionResolver $guardianProjection,
    ) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::Grade;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        $cohort = $this->context->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementAcademicCohort::query()->where('announcement_id', $announcement->id)->first(),
        );

        if ($cohort === null) {
            return new ResolvedAudience(userIds: [], categoryBreakdown: []);
        }

        $studentIds = $this->context->withSchool($announcement->school, fn () => StudentEnrollment::query()
            ->where('school_id', $announcement->school_id)
            ->where('grade_level_id', $cohort->grade_level_id)
            ->where('academic_year_id', $cohort->academic_year_id)
            ->where('status', 'active')
            ->distinct()
            ->pluck('student_id')
            ->all());

        if ($cohort->recipientKindEnum() === CommunicationAcademicCohortRecipientKind::Student) {
            return new ResolvedAudience(
                userIds: [],
                categoryBreakdown: $studentIds === [] ? [] : ['Students in Grade' => count($studentIds)],
                studentIds: $studentIds,
            );
        }

        $guardianIds = $this->guardianProjection->forStudentIds($announcement->school, $studentIds);

        return new ResolvedAudience(
            userIds: [],
            categoryBreakdown: $guardianIds === [] ? [] : ['Guardians of Grade' => count($guardianIds)],
            guardianIds: $guardianIds,
        );
    }
}
