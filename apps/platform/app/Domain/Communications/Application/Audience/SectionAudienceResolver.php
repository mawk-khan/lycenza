<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAcademicCohort;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5B.3 §7: resolves a Section academic-cohort audience
 * (`audience_type = section`) against Phase 1B's `student_enrollments`.
 *
 * Filters on BOTH `section_id` AND `academic_year_id` -- a Section
 * already belongs to exactly one AcademicYear (structurally, via
 * `sections.academic_year_id`), so this is defense-in-depth (brief
 * §7: "validate the Section belongs to the intended... AcademicYear"),
 * not a correctness requirement `section_id` alone would lack.
 *
 * Re-resolved fresh on every call, identical discipline to
 * GradeAudienceResolver -- see its docblock.
 */
class SectionAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianProjectionResolver $guardianProjection,
    ) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::Section;
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
            ->where('section_id', $cohort->section_id)
            ->where('academic_year_id', $cohort->academic_year_id)
            ->where('status', 'active')
            ->distinct()
            ->pluck('student_id')
            ->all());

        if ($cohort->recipientKindEnum() === CommunicationAcademicCohortRecipientKind::Student) {
            return new ResolvedAudience(
                userIds: [],
                categoryBreakdown: $studentIds === [] ? [] : ['Students in Section' => count($studentIds)],
                studentIds: $studentIds,
            );
        }

        $guardianIds = $this->guardianProjection->forStudentIds($announcement->school, $studentIds);

        return new ResolvedAudience(
            userIds: [],
            categoryBreakdown: $guardianIds === [] ? [] : ['Guardians of Section' => count($guardianIds)],
            guardianIds: $guardianIds,
        );
    }
}
