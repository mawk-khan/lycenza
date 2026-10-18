<?php

namespace App\Domain\Automation\Application;

use App\Models\School;
use App\Support\FeatureFlags\FeatureFlagResolver;

/**
 * The School-wide Automation opt-in (owner decision 2026-09-24): the
 * `automation.rules` flag, default OFF (`<module>.<feature>`). While off,
 * no execution is created or run for the School, whatever its rule
 * instances say. A product switch only -- it grants no capability
 * (FeatureFlagResolver).
 *
 * ORG.5 (ADR 0076 §13, §31): kept as an operational/rollout switch layered
 * IN ADDITION to the `automation` module entitlement. Execution needs both;
 * the flag never creates an entitlement and an entitlement never overrides
 * the flag.
 */
class AutomationFeatureGate
{
    public const FLAG = 'automation.rules';

    public function __construct(private readonly FeatureFlagResolver $flags) {}

    public function isEnabledFor(School $school): bool
    {
        return $this->flags->isEnabledForSchool(self::FLAG, $school);
    }
}
