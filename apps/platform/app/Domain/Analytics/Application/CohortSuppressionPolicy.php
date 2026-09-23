<?php

namespace App\Domain\Analytics\Application;

use App\Domain\Analytics\Application\Exceptions\AnalyticsReportUnavailableException;

/**
 * ADR 0040 §6's small-cohort gate, as code. FAIL CLOSED:
 *
 * - A read model that counts people is refused unless a minimum
 *   person-cohort size is configured
 *   (`config('analytics.minimum_person_cohort_size')`). None has been
 *   approved (docs/security/ANALYTICS-SMALL-COHORT-POLICY-GATE.md), the
 *   setting deliberately has NO default, and anything other than a
 *   positive whole number is treated as unset -- never as "no
 *   suppression".
 * - A read model that counts no people (pure object/process
 *   aggregates) is outside the cohort-size gate (ADR 0040 §6 as amended
 *   2026-09-23) and is served regardless of the setting.
 *
 * Configuring a value is necessary, NOT sufficient: this checkpoint
 * implements no per-cell suppression, complementary suppression or
 * suppression marker, because the suppression mode is itself an open
 * decision (gate document §7.2). Tests\Feature\Analytics\
 * AnalyticsArchitectureGuardTest therefore also refuses to let any
 * REGISTERED read model count people. The first person-counting read
 * model must bring that suppression with it, after the decisions.
 */
class CohortSuppressionPolicy
{
    public function minimumPersonCohortSize(): ?int
    {
        $raw = config('analytics.minimum_person_cohort_size');

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && preg_match('/^[1-9][0-9]*$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        return null;
    }

    public function isPersonCohortPolicyConfigured(): bool
    {
        return $this->minimumPersonCohortSize() !== null;
    }

    public function assertServable(ReadModelDeclaration $declaration): void
    {
        if ($declaration->countsPeople && ! $this->isPersonCohortPolicyConfigured()) {
            throw AnalyticsReportUnavailableException::personCohortPolicyNotConfigured($declaration->key);
        }
    }
}
