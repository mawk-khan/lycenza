<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementAcademicCohort;
use App\Domain\Students\Application\SubjectOfferingRosterReadService;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5C.1: resolves a SubjectOffering academic-cohort audience
 * (`audience_type = subject_offering`) through Phase 1C's
 * SubjectOfferingRosterReadService -- never against
 * `student_enrollments`/`student_subject_enrollments` directly.
 * Communications is deliberately ignorant of the required-vs-elective
 * distinction: the single `currentRosterStudentIds()`/
 * `currentRosterCount()` call already handles both (see
 * docs/students/PHASE-1C-STUDENT-SUBJECT-ENROLLMENT-FOUNDATION.md §26).
 *
 * Same "re-resolved fresh on every call, never a materialized/frozen
 * membership list" discipline as GradeAudienceResolver/
 * SectionAudienceResolver -- see their docblocks. An inactive
 * SubjectOffering therefore naturally resolves to zero recipients
 * (the roster service's own short-circuit), with no second
 * inactive-status policy duplicated here.
 */
class SubjectOfferingAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubjectOfferingRosterReadService $rosterReadService,
        private readonly GuardianProjectionResolver $guardianProjection,
    ) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::SubjectOffering;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        $cohort = $this->context->withSchool(
            $announcement->school,
            fn () => CommunicationAnnouncementAcademicCohort::query()->where('announcement_id', $announcement->id)->first(),
        );

        if ($cohort === null || $cohort->subject_offering_id === null) {
            return new ResolvedAudience(userIds: [], categoryBreakdown: []);
        }

        $studentIds = $this->context->withSchool($announcement->school, function () use ($announcement, $cohort) {
            $offering = SubjectOffering::query()
                ->where('school_id', $announcement->school_id)
                ->find($cohort->subject_offering_id);

            return $offering === null ? [] : $this->rosterReadService->currentRosterStudentIds($offering);
        });

        if ($cohort->recipientKindEnum() === CommunicationAcademicCohortRecipientKind::Student) {
            return new ResolvedAudience(
                userIds: [],
                categoryBreakdown: $studentIds === [] ? [] : ['Students in Subject Offering' => count($studentIds)],
                studentIds: $studentIds,
            );
        }

        $guardianIds = $this->guardianProjection->forStudentIds($announcement->school, $studentIds);

        return new ResolvedAudience(
            userIds: [],
            categoryBreakdown: $guardianIds === [] ? [] : ['Guardians of Subject Offering' => count($guardianIds)],
            guardianIds: $guardianIds,
        );
    }
}
