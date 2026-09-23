<?php

namespace App\Domain\Analytics\Application\ReadModels;

use App\Domain\Analytics\Application\AnalyticsReadModel;
use App\Domain\Analytics\Application\ClassificationTier;
use App\Domain\Analytics\Application\ReadModelDeclaration;
use App\Domain\CurriculumDelivery\Application\Coverage\AcademicYearOption;
use App\Domain\CurriculumDelivery\Application\Coverage\OfferingCoverageCounts;
use App\Domain\CurriculumDelivery\Application\Coverage\SectionCoverageCounts;
use App\Domain\CurriculumDelivery\Application\CurriculumCoverageReadService;
use App\Models\School;

/**
 * Phase 0L.2-1 -- the first Analytics read model: syllabus coverage for
 * one School and one AcademicYear.
 *
 * Every figure counts SYLLABUS UNITS, or unit x Section pairs: "planned"
 * is (active units of a required Offering) x (active Sections sharing
 * its context); "completed"/"in progress"/"not started" split that
 * number; coverage % = completed / planned. No figure or denominator
 * counts a Student, Employee or any other person, so this read model
 * declares `countsPeople: false` and is outside the (unapproved)
 * person-cohort gate. Its sources -- SyllabusUnit and CurriculumDelivery
 * -- are both Confidential (DATA-CLASSIFICATION.md), so the output is
 * Confidential: capability-gated, not access-audited.
 *
 * Source data comes ONLY through Curriculum Delivery's own aggregate
 * contract (CurriculumCoverageReadService), never its tables (ADR 0040
 * §3). The only filter is `academic_year_id`, which must be one of this
 * School's own years; it defaults to the active year.
 *
 * Percentages use integer arithmetic (one decimal place, half up) so
 * the same data always renders the same figure.
 */
class CurriculumCoverageReadModel implements AnalyticsReadModel
{
    public const KEY = 'curriculum.coverage';

    public function __construct(private readonly CurriculumCoverageReadService $source) {}

    public function declaration(): ReadModelDeclaration
    {
        return new ReadModelDeclaration(
            key: self::KEY,
            tier: ClassificationTier::Confidential,
            countsPeople: false,
            sourceModules: ['CurriculumDelivery'],
            filters: ['academic_year_id'],
        );
    }

    public function compute(School $school, array $filters): array
    {
        $years = $this->source->academicYearOptions($school);

        $selected = null;
        if (isset($filters['academic_year_id'])) {
            // Only one of THIS School's own years; anything else (another
            // School's id, a random uuid) is treated as "not found".
            $selected = collect($years)->firstWhere('id', $filters['academic_year_id']);
        } else {
            $selected = collect($years)->firstWhere('isActive', true) ?? ($years[0] ?? null);
        }

        $base = [
            'academicYears' => array_map(fn (AcademicYearOption $y) => [
                'id' => $y->id, 'name' => $y->name, 'code' => $y->code, 'isActive' => $y->isActive,
            ], $years),
            'academicYearId' => $selected?->id,
        ];

        if ($selected === null) {
            return $base + ['totals' => $this->figures(0, 0, 0) + ['offerings' => 0, 'offeringsWithoutSyllabus' => 0], 'byGradeLevel' => [], 'offerings' => []];
        }

        $coverage = $this->source->coverageForAcademicYear($school, $selected->id);

        $offerings = [];
        $byGrade = [];
        $total = [0, 0, 0];
        $withoutSyllabus = 0;

        foreach ($coverage->offerings as $offering) {
            /** @var OfferingCoverageCounts $offering */
            [$planned, $completed, $inProgress] = [0, 0, 0];
            $sections = [];

            foreach ($offering->sections as $section) {
                /** @var SectionCoverageCounts $section */
                $planned += $section->plannedUnits;
                $completed += $section->completedUnits;
                $inProgress += $section->inProgressUnits;
                $sections[] = [
                    'sectionId' => $section->sectionId,
                    'sectionName' => $section->sectionName,
                    'sectionCode' => $section->sectionCode,
                ] + $this->figures($section->plannedUnits, $section->completedUnits, $section->inProgressUnits);
            }

            if ($offering->activeSyllabusUnits === 0) {
                $withoutSyllabus++;
            }

            $offerings[] = [
                'subjectOfferingId' => $offering->subjectOfferingId,
                'subjectCode' => $offering->subjectCode,
                'subjectName' => $offering->subjectName,
                'gradeLevelName' => $offering->gradeLevelName,
                'campusName' => $offering->campusName,
                'activeSyllabusUnits' => $offering->activeSyllabusUnits,
                'sectionCount' => count($sections),
                'sections' => $sections,
            ] + $this->figures($planned, $completed, $inProgress);

            $gradeKey = $offering->gradeLevelSequence.'|'.$offering->gradeLevelName;
            $byGrade[$gradeKey] ??= ['gradeLevelName' => $offering->gradeLevelName, 'counts' => [0, 0, 0]];
            $byGrade[$gradeKey]['counts'][0] += $planned;
            $byGrade[$gradeKey]['counts'][1] += $completed;
            $byGrade[$gradeKey]['counts'][2] += $inProgress;

            $total[0] += $planned;
            $total[1] += $completed;
            $total[2] += $inProgress;
        }

        return $base + [
            'totals' => $this->figures(...$total) + [
                'offerings' => count($offerings),
                'offeringsWithoutSyllabus' => $withoutSyllabus,
            ],
            'byGradeLevel' => array_values(array_map(
                fn (array $g) => ['gradeLevelName' => $g['gradeLevelName']] + $this->figures(...$g['counts']),
                $byGrade,
            )),
            'offerings' => $offerings,
        ];
    }

    /**
     * @return array{planned: int, completed: int, inProgress: int, notStarted: int, coveragePercent: string|null}
     */
    private function figures(int $planned, int $completed, int $inProgress): array
    {
        return [
            'planned' => $planned,
            'completed' => $completed,
            'inProgress' => $inProgress,
            'notStarted' => max(0, $planned - $completed - $inProgress),
            'coveragePercent' => self::percent($completed, $planned),
        ];
    }

    /**
     * One decimal place, rounded half up, integer arithmetic only; null
     * when there is nothing planned (no syllabus or no Section).
     */
    public static function percent(int $part, int $whole): ?string
    {
        if ($whole <= 0) {
            return null;
        }

        $tenths = intdiv($part * 1000 + intdiv($whole, 2), $whole);

        return intdiv($tenths, 10).'.'.($tenths % 10);
    }
}
