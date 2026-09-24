<?php

namespace App\Domain\Automation\Application;

use App\Models\School;
use App\Support\FeatureFlags\FeatureFlagResolver;

/**
 * The School-wide Automation opt-in (owner decision 2026-09-24): the
 * `automation.rules` flag, default OFF (`<module>.<feature>` like
 * `students.processing_authorizations`). While off, no execution is
 * created or run for the School, whatever its rule instances say. A
 * product switch only -- it grants no capability (FeatureFlagResolver).
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
