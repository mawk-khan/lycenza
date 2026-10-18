<?php

namespace App\Domain\Platform\Application\Modules;

use App\Models\School;

/**
 * ORG.5 (ADR 0076 §12.7(3)): the execution-time entitlement check for
 * background work (ExecutesModuleWork). Uncached: it reads the School's
 * current state inside that one School's TenantContext, so a module disabled
 * after the work was queued is seen when the work runs. A School that no
 * longer exists, or an unknown module, answers false (fail closed).
 */
class ModuleExecutionGuard
{
    public function __construct(private readonly SchoolModuleEntitlements $entitlements) {}

    public function allows(School|string $school, string $module): bool
    {
        $model = $school instanceof School ? $school : School::query()->find($school);

        return $model !== null && $this->entitlements->isEnabledFresh($model, $module);
    }
}
