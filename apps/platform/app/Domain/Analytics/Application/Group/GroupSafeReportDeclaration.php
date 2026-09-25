<?php

namespace App\Domain\Analytics\Application\Group;

use App\Domain\Analytics\Application\ClassificationTier;
use InvalidArgumentException;

/**
 * What a report must declare before GroupSafeReportGate serves it across
 * a Group's Schools (ADR 0048 section 6). A declaration that counts
 * people is refused outright: person-counting Analytics stays closed and
 * Group reporting is never a way around its gates.
 */
final readonly class GroupSafeReportDeclaration
{
    /**
     * @param  list<string>  $sourceModules  owning modules whose read contracts it consumes
     * @param  list<string>  $dimensions  cross-School dimensions (none: one row per School)
     * @param  list<string>  $metrics  the only figures a School summary carries
     * @param  list<string>  $nullable  metrics that may be null, and why is in the ADR
     */
    public function __construct(
        public string $key,
        public string $owner,
        public array $sourceModules,
        public array $dimensions,
        public array $metrics,
        public ClassificationTier $tier,
        public bool $countsPeople,
        public string $aggregation,
        public array $nullable,
        public string $academicYearSelection,
        public string $approval,
    ) {
        if ($countsPeople) {
            throw new InvalidArgumentException("Group-safe report [{$key}] counts people; person-counting Analytics is not Group-safe (ADR 0048 section 16).");
        }

        if ($metrics === [] || $sourceModules === []) {
            throw new InvalidArgumentException("Group-safe report [{$key}] must declare its source modules and metrics.");
        }
    }
}
