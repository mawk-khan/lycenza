<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Application\Coverage\AcademicYearOption;
use App\Domain\CurriculumDelivery\Application\Coverage\CurriculumCoverageCounts;
use App\Domain\CurriculumDelivery\Application\Coverage\OfferingCoverageCounts;
use App\Domain\CurriculumDelivery\Application\Coverage\SectionCoverageCounts;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 0L.2-1 -- Curriculum Delivery's AGGREGATE read contract, the
 * one sanctioned way another module (today: Analytics, ADR 0040 §3)
 * reads syllabus coverage. Analytics never queries
 * `curriculum_deliveries`/`syllabus_units` itself.
 *
 * WHAT IT RETURNS: unit counts only -- per required, active
 * SubjectOffering, its active SyllabusUnit count and, per active
 * Section sharing the Offering's AcademicYear/Campus/GradeLevel
 * context, how many of those units are completed / in progress / not
 * started. No delivery dates, no entity collections, and no person of
 * any kind: the source rows store no Student, Employee or teacher
 * identity (CurriculumDeliveryArchitectureGuardTest pins that), so no
 * number here counts people.
 *
 * SAME PROJECTION AS THE OPERATIONAL PAGE: required Offerings only (an
 * elective has no Section-wide cohort), active Offerings/Sections/Units
 * only, and "not started" computed from the absence of a row -- the
 * rules App\Http\Controllers\App\CurriculumDelivery\CurriculumDeliveryController
 * already applies, so the aggregate always agrees with what a
 * `curriculum.delivery.view` holder sees unit by unit. A delivery for a
 * since-deactivated unit is not counted, exactly as that page no
 * longer lists it.
 *
 * AUTHORIZATION belongs to the caller. The data is Confidential
 * (docs/security/DATA-CLASSIFICATION.md) and read-only, and ADR 0040 §5
 * makes analytics access deliberately independent of source-record
 * access -- so this contract does not re-check
 * `curriculum.delivery.view`; the Analytics read gate checks
 * `analytics.view` before calling it. TENANCY is enforced here: every
 * query runs inside TenantContext::withSchool() (SchoolScope + RLS) and
 * is additionally filtered by the School's id.
 */
class CurriculumCoverageReadService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<AcademicYearOption> newest first
     */
    public function academicYearOptions(School $school): array
    {
        return $this->context->withSchool($school, fn () => AcademicYear::query()
            ->where('school_id', $school->id)
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (AcademicYear $y) => new AcademicYearOption($y->id, $y->name, $y->code, $y->status === 'active'))
            ->values()->all());
    }

    public function coverageForAcademicYear(School $school, string $academicYearId): CurriculumCoverageCounts
    {
        return $this->context->withSchool($school, function () use ($school, $academicYearId): CurriculumCoverageCounts {
            $offerings = SubjectOffering::query()
                ->with(['subject', 'gradeLevel', 'campus'])
                ->where('school_id', $school->id)
                ->where('academic_year_id', $academicYearId)
                ->where('status', 'active')
                ->where('is_required', true)
                ->get();

            if ($offerings->isEmpty()) {
                return new CurriculumCoverageCounts($academicYearId, []);
            }

            /** @var array<string, int> $unitCounts */
            $unitCounts = SyllabusUnit::query()
                ->where('school_id', $school->id)
                ->whereIn('subject_offering_id', $offerings->pluck('id'))
                ->where('status', 'active')
                ->groupBy('subject_offering_id')
                ->selectRaw('subject_offering_id, count(*) as units')
                ->pluck('units', 'subject_offering_id')
                ->map(fn ($n) => (int) $n)
                ->all();

            $sectionsByContext = Section::query()
                ->where('school_id', $school->id)
                ->where('academic_year_id', $academicYearId)
                ->where('status', 'active')
                ->orderByRaw('upper(code)')
                ->get()
                ->groupBy(fn (Section $s) => $s->campus_id.'|'.$s->grade_level_id);

            // One grouped query: (offering, section, status) -> count,
            // counting only deliveries of still-active units.
            $deliveryCounts = [];
            CurriculumDelivery::query()
                ->join('syllabus_units', 'syllabus_units.id', '=', 'curriculum_deliveries.syllabus_unit_id')
                ->where('curriculum_deliveries.school_id', $school->id)
                ->where('curriculum_deliveries.academic_year_id', $academicYearId)
                ->where('syllabus_units.status', 'active')
                ->groupBy('curriculum_deliveries.subject_offering_id', 'curriculum_deliveries.section_id', 'curriculum_deliveries.status')
                ->selectRaw('curriculum_deliveries.subject_offering_id, curriculum_deliveries.section_id, curriculum_deliveries.status, count(*) as units')
                ->toBase()
                ->get()
                ->each(function (object $row) use (&$deliveryCounts): void {
                    $deliveryCounts[$row->subject_offering_id][$row->section_id][$row->status] = (int) $row->units;
                });

            $result = $offerings->map(function (SubjectOffering $offering) use ($unitCounts, $sectionsByContext, $deliveryCounts): OfferingCoverageCounts {
                $planned = $unitCounts[$offering->id] ?? 0;
                $sections = $sectionsByContext->get($offering->campus_id.'|'.$offering->grade_level_id, collect());

                return new OfferingCoverageCounts(
                    subjectOfferingId: $offering->id,
                    subjectCode: (string) $offering->subject?->code,
                    subjectName: (string) $offering->subject?->name,
                    gradeLevelName: (string) $offering->gradeLevel?->name,
                    gradeLevelSequence: (int) $offering->gradeLevel?->sequence,
                    campusName: (string) $offering->campus?->name,
                    activeSyllabusUnits: $planned,
                    sections: $sections->map(function (Section $section) use ($offering, $planned, $deliveryCounts): SectionCoverageCounts {
                        $counts = $deliveryCounts[$offering->id][$section->id] ?? [];
                        $completed = $counts[CurriculumDelivery::STATUS_COMPLETED] ?? 0;
                        $inProgress = $counts[CurriculumDelivery::STATUS_IN_PROGRESS] ?? 0;

                        return new SectionCoverageCounts(
                            sectionId: $section->id,
                            sectionName: $section->name,
                            sectionCode: $section->code,
                            plannedUnits: $planned,
                            completedUnits: $completed,
                            inProgressUnits: $inProgress,
                            notStartedUnits: max(0, $planned - $completed - $inProgress),
                        );
                    })->values()->all(),
                );
            })
                ->sortBy([
                    fn (OfferingCoverageCounts $a, OfferingCoverageCounts $b) => $a->gradeLevelSequence <=> $b->gradeLevelSequence,
                    fn (OfferingCoverageCounts $a, OfferingCoverageCounts $b) => strcmp($a->campusName, $b->campusName),
                    fn (OfferingCoverageCounts $a, OfferingCoverageCounts $b) => strcmp($a->subjectCode, $b->subjectCode),
                ])
                ->values()->all();

            return new CurriculumCoverageCounts($academicYearId, $result);
        });
    }
}
