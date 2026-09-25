<?php

namespace App\Domain\Analytics\Application\Group;

use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;

/**
 * Phase 0N.11 (ADR 0048 section 6): the CLOSED catalog of Group-safe
 * reports -- separate from AnalyticsReadModelRegistry, so registering a
 * School read model never makes it reachable across a Group. Adding an
 * entry is an ADR 0048 amendment (Tests\Feature\Analytics\GroupSafeReportGuardTest).
 * v1: curriculum.coverage only.
 */
final class GroupSafeReportRegistry
{
    /** @var array<string, class-string<GroupSafeReport>> report key => class */
    public const REPORTS = [
        CurriculumCoverageReadModel::KEY => CurriculumCoverageReadModel::class,
    ];

    public function find(string $key): ?GroupSafeReport
    {
        $class = self::REPORTS[$key] ?? null;

        if ($class === null) {
            return null;
        }

        $report = app($class);

        // The declared key must be the registered key -- no aliasing.
        return $report->groupDeclaration()->key === $key ? $report : null;
    }
}
