<?php

namespace App\Domain\Analytics\Application\ReadModels;

use App\Domain\Analytics\Application\AnalyticsReadModel;
use App\Domain\Analytics\Application\ClassificationTier;
use App\Domain\Analytics\Application\Group\CurriculumCoverageSchoolSummary;
use App\Domain\Analytics\Application\Group\GroupSafeReport;
use App\Domain\Analytics\Application\Group\GroupSafeReportDeclaration;
use App\Domain\Analytics\Application\Group\GroupSafeSchoolSummary;
use App\Domain\Analytics\Application\ReadModelDeclaration;
use App\Domain\CurriculumDelivery\Application\Coverage\AcademicYearOption;
use App\Domain\CurriculumDelivery\Application\Coverage\CurriculumCoverageCounts;
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
 *
 * Phase 0N.11 (ADR 0048): also the one Group-safe report. Its Group
 * contract is separate from the School page above: groupSafeSummary()
 * reads the ACTIVE academic year only (no fallback), returns School-level
 * counts plus that year's name/code and nothing else, and aggregateGroup()
 * sums counts and recomputes the percentage from the sums -- never an
 * average of per-School percentages. compute() is unchanged.
 */
class CurriculumCoverageReadModel implements AnalyticsReadModel, GroupSafeReport
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

    public function groupDeclaration(): GroupSafeReportDeclaration
    {
        return new GroupSafeReportDeclaration(
            key: self::KEY,
            owner: 'Analytics',
            sourceModules: ['CurriculumDelivery'],
            dimensions: [],
            metrics: ['planned', 'completed', 'inProgress', 'notStarted', 'coveragePercent', 'offerings', 'offeringsWithoutSyllabus'],
            tier: ClassificationTier::Confidential,
            countsPeople: false,
            aggregation: 'sum_counts_recompute_percent',
            nullable: ['coveragePercent'],
            academicYearSelection: 'active_only',
            approval: 'ADR 0048',
        );
    }

    public function groupSafeSummary(School $school): GroupSafeSchoolSummary
    {
        $active = collect($this->source->academicYearOptions($school))->firstWhere('isActive', true);

        if ($active === null) {
            return CurriculumCoverageSchoolSummary::noActiveAcademicYear();
        }

        $t = $this->countTotals($this->source->coverageForAcademicYear($school, $active->id));

        return new CurriculumCoverageSchoolSummary(
            hasActiveAcademicYear: true,
            academicYearName: $active->name,
            academicYearCode: $active->code,
            planned: $t['planned'],
            completed: $t['completed'],
            inProgress: $t['inProgress'],
            notStarted: max(0, $t['planned'] - $t['completed'] - $t['inProgress']),
            offerings: $t['offerings'],
            offeringsWithoutSyllabus: $t['offeringsWithoutSyllabus'],
        );
    }

    public function aggregateGroup(array $summaries): array
    {
        $sum = ['planned' => 0, 'completed' => 0, 'inProgress' => 0, 'notStarted' => 0, 'offerings' => 0, 'offeringsWithoutSyllabus' => 0];

        foreach ($summaries as $summary) {
            if (! $summary instanceof CurriculumCoverageSchoolSummary || ! $summary->contributes()) {
                continue;
            }

            foreach (array_keys($sum) as $metric) {
                $sum[$metric] += $summary->{$metric};
            }
        }

        return $sum + ['coveragePercent' => self::percent($sum['completed'], $sum['planned'])];
    }

    /**
     * School-level totals for one academic year -- the same sums compute()
     * renders as `totals`.
     *
     * @return array{planned: int, completed: int, inProgress: int, offerings: int, offeringsWithoutSyllabus: int}
     */
    private function countTotals(CurriculumCoverageCounts $coverage): array
    {
        $totals = ['planned' => 0, 'completed' => 0, 'inProgress' => 0, 'offerings' => 0, 'offeringsWithoutSyllabus' => 0];

        foreach ($coverage->offerings as $offering) {
            /** @var OfferingCoverageCounts $offering */
            $totals['offerings']++;
            $totals['offeringsWithoutSyllabus'] += $offering->activeSyllabusUnits === 0 ? 1 : 0;

            foreach ($offering->sections as $section) {
                /** @var SectionCoverageCounts $section */
                $totals['planned'] += $section->plannedUnits;
                $totals['completed'] += $section->completedUnits;
                $totals['inProgress'] += $section->inProgressUnits;
            }
        }

        return $totals;
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
