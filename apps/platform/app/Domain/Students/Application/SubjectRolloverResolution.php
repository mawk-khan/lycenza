<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\EnrollmentRolloverSubjectMapping;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use Illuminate\Support\Collection;

/**
 * Phase 1G.3: the pure, read-only per-Item elective-rollover resolution
 * shared by EnrollmentRolloverDryRunService (batch evaluation, zero
 * writes) and EnrollmentRolloverItemExecutionService (single-Item
 * execution, real writes through StudentSubjectEnrollmentService) --
 * extracted so both callers apply IDENTICAL candidate selection, legacy
 * anchor resolution, three-state mapping resolution, and plan-internal/
 * existing-target conflict detection, rather than two subtly divergent
 * copies. Issues no queries itself and performs no writes -- every
 * caller supplies its own already-loaded reference data, batched or
 * single-Item as fits its own access pattern. See
 * docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md
 * §3/§4/§5/§6/§8 and docs/students/PHASE-1G-2-SUBJECT-ROLLOVER-DRY-RUN.md
 * for the accepted rules this implements.
 */
final class SubjectRolloverResolution
{
    /**
     * Fixed deterministic priority when more than one subject-level
     * issue is found for the same Item -- never query/iteration order.
     * `legacy_source_anchor_ambiguous` is deliberately LAST (least
     * specific signal -- a concrete, actionable problem elsewhere always
     * wins). See PHASE-1G-2 doc §10.
     */
    public const REASON_PRECEDENCE = [
        'elective_target_duplicate_mapping',
        'elective_target_group_conflict',
        'elective_target_existing_conflict',
        'missing_subject_mapping',
        'elective_target_required',
        'elective_target_inactive',
        'elective_target_context_mismatch',
        'legacy_source_anchor_ambiguous',
    ];

    /**
     * @param  Collection<int, StudentSubjectEnrollment>  $anchoredParticipations  Active rows where student_enrollment_id === $sourceEnrollmentId.
     * @param  Collection<int, StudentSubjectEnrollment>  $legacyParticipations  Active rows for $studentId with student_enrollment_id === null, for the Plan's source Academic Year.
     * @param  Collection<int, StudentEnrollment>  $sourceYearCandidates  ALL (any status) StudentEnrollment rows for ($studentId, plan.source_academic_year_id) -- the legacy-anchor proof set.
     * @param  Collection<string, EnrollmentRolloverSubjectMapping>  $mappingsBySourceOfferingId
     * @param  Collection<string, SubjectOffering>  $targetOfferingsById
     * @param  Collection<int, StudentSubjectEnrollment>  $existingActiveTargetParticipations  Active rows already on the resolved/prospective target StudentEnrollment (empty if none/not yet existing).
     * @return array{resolvedTargets: array<string, SubjectOffering>, reasons: list<string>} resolvedTargets keyed by SOURCE SubjectOffering id.
     */
    public static function resolve(
        string $sourceEnrollmentId,
        string $studentId,
        Collection $anchoredParticipations,
        Collection $legacyParticipations,
        Collection $sourceYearCandidates,
        Collection $mappingsBySourceOfferingId,
        Collection $targetOfferingsById,
        string $targetAcademicYearId,
        string $targetCampusId,
        string $targetGradeLevelId,
        Collection $existingActiveTargetParticipations,
    ): array {
        $participations = collect();
        $reasons = [];

        foreach ($anchoredParticipations as $row) {
            $participations->push($row);
        }

        foreach ($legacyParticipations as $row) {
            $offering = $row->subjectOffering;

            if ($sourceYearCandidates->count() !== 1 || $offering === null) {
                $reasons[] = 'legacy_source_anchor_ambiguous';

                continue;
            }

            $onlyCandidate = $sourceYearCandidates->first();
            $compatible = $onlyCandidate->academic_year_id === $offering->academic_year_id
                && $onlyCandidate->campus_id === $offering->campus_id
                && $onlyCandidate->grade_level_id === $offering->grade_level_id;

            if (! $compatible || $onlyCandidate->id !== $sourceEnrollmentId) {
                $reasons[] = 'legacy_source_anchor_ambiguous';

                continue;
            }

            $participations->push($row);
        }

        $resolvedTargets = [];
        foreach ($participations as $row) {
            $offering = $row->subjectOffering;

            if ($offering === null || $offering->is_required) {
                // Phase 1G.2 section 61 defense: a required-offering
                // participation is never a valid elective rollover
                // candidate, even if malformed/raw data made one exist
                // -- silently excluded, no conflict raised.
                continue;
            }

            $mapping = $mappingsBySourceOfferingId->get($offering->id);

            if ($mapping === null) {
                $reasons[] = 'missing_subject_mapping';

                continue;
            }

            if ($mapping->isExplicitOmit()) {
                continue;
            }

            $target = $targetOfferingsById->get($mapping->target_subject_offering_id);

            if ($target === null) {
                // Defensive only -- the composite same-School FK
                // guarantees a mapped target Offering exists; never
                // reachable through the sanctioned mutation service.
                $reasons[] = 'elective_target_context_mismatch';

                continue;
            }

            if ($target->is_required) {
                $reasons[] = 'elective_target_required';

                continue;
            }

            if (! $target->isActive()) {
                $reasons[] = 'elective_target_inactive';

                continue;
            }

            if ($target->academic_year_id !== $targetAcademicYearId
                || $target->campus_id !== $targetCampusId
                || $target->grade_level_id !== $targetGradeLevelId) {
                $reasons[] = 'elective_target_context_mismatch';

                continue;
            }

            $resolvedTargets[$offering->id] = $target;
        }

        $targetIdCounts = [];
        foreach ($resolvedTargets as $target) {
            $targetIdCounts[$target->id] = ($targetIdCounts[$target->id] ?? 0) + 1;
        }
        if (collect($targetIdCounts)->contains(fn ($count) => $count > 1)) {
            $reasons[] = 'elective_target_duplicate_mapping';
        }

        $byGroup = [];
        foreach ($resolvedTargets as $target) {
            if ($target->elective_group_id !== null) {
                $byGroup[$target->elective_group_id][$target->id] = true;
            }
        }
        foreach ($byGroup as $groupTargetIds) {
            if (count($groupTargetIds) > 1) {
                $reasons[] = 'elective_target_group_conflict';

                break;
            }
        }

        $existingByOffering = $existingActiveTargetParticipations->keyBy('subject_offering_id');
        $existingGroups = $existingActiveTargetParticipations->whereNotNull('elective_group_id')->groupBy('elective_group_id');

        foreach ($resolvedTargets as $target) {
            if ($existingByOffering->has($target->id)) {
                // elective_already_enrolled_match -- non-blocking,
                // idempotent no-op (PHASE-1G-2 doc §7).
                continue;
            }

            if ($target->elective_group_id !== null && $existingGroups->has($target->elective_group_id)) {
                $reasons[] = 'elective_target_existing_conflict';
            }
        }

        return ['resolvedTargets' => $resolvedTargets, 'reasons' => $reasons];
    }
}
