<?php

namespace App\Domain\Analytics\Application;

use InvalidArgumentException;

/**
 * What an Analytics read model must state about itself before
 * App\Domain\Analytics\Application\AnalyticsReadGate will run it
 * (docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md §6.2).
 *
 * `countsPeople` is the load-bearing field: TRUE whenever any cell
 * value OR denominator counts people, or could disclose information
 * about people (Students, Guardians, Employees, applicants, visitors,
 * ...). Such a read model is subject to the minimum person-cohort
 * size, which has not been approved, so the gate refuses it
 * (CohortSuppressionPolicy). FALSE is only for pure object/process
 * aggregates -- e.g. syllabus units covered -- which are outside the
 * cohort-size gate but still classified, authorized, tenant-scoped and,
 * where the tier requires it, audited (ADR 0040 §6, as amended
 * 2026-09-23).
 *
 * `filters` is the closed list of request filters the read model
 * accepts; the gate rejects any other key, so no report takes
 * unrestricted arbitrary filters.
 */
final readonly class ReadModelDeclaration
{
    /**
     * @param  list<string>  $sourceModules  owning modules whose read contracts it consumes
     * @param  list<string>  $filters  the only accepted filter keys
     */
    public function __construct(
        public string $key,
        public ClassificationTier $tier,
        public bool $countsPeople,
        public array $sourceModules,
        public array $filters,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid Analytics read model key [{$key}].");
        }

        if ($sourceModules === []) {
            throw new InvalidArgumentException("Analytics read model [{$key}] must declare its source module(s).");
        }
    }
}
