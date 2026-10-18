<?php

namespace App\Domain\Platform\Application\Modules;

use App\Domain\Platform\Infrastructure\SchoolModuleEntitlement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * ORG.5 (ADR 0076 §12.2, §12.7, §12.8): the ONE reader of a School's
 * module entitlements -- the Platform Application contract
 * CapabilityResolver consumes.
 *
 * - Core modules are always available; an optional module is available
 *   only with an `enabled` row. A missing row is DISABLED (fail closed, the
 *   new-School default); an unknown key is never available.
 * - Read inside the target School's TenantContext (FORCE RLS), restoring
 *   whatever context was there before.
 * - entitledModules() is cached per School for at most 60 s and forgotten
 *   the moment SchoolModuleEntitlementService commits a change, so a change
 *   takes effect immediately for every user. Execution-time checks
 *   (ModuleExecutionGuard) use isEnabledFresh(), never the cache.
 */
class SchoolModuleEntitlements
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly TenantContext $context) {}

    /** @return list<string> core modules plus the enabled optional ones */
    public function entitledModules(School $school): array
    {
        return Cache::remember(self::cacheKey($school->id), self::CACHE_TTL_SECONDS, fn (): array => $this->load($school));
    }

    public function isEnabled(School $school, string $module): bool
    {
        return ModuleCatalogue::exists($module) && in_array($module, $this->entitledModules($school), true);
    }

    /** Uncached: for background work that must see the current state. */
    public function isEnabledFresh(School $school, string $module): bool
    {
        return ModuleCatalogue::exists($module) && in_array($module, $this->load($school), true);
    }

    /** @return array<string, bool> every optional module => its persisted state (false when no row) */
    public function optionalStates(School $school): array
    {
        $read = fn (): array => SchoolModuleEntitlement::query()
            ->where('school_id', $school->id)
            ->pluck('enabled', 'module_key')
            ->all();
        // Already inside this School's context (a job's execution point): read
        // directly; otherwise enter it and restore whatever was there.
        $rows = $this->context->school()?->id === $school->id ? $read() : $this->context->withSchool($school, $read);

        $states = [];
        foreach (ModuleCatalogue::optionalKeys() as $module) {
            $states[$module] = (bool) ($rows[$module] ?? false);
        }

        return $states;
    }

    public function forget(string $schoolId): void
    {
        Cache::forget(self::cacheKey($schoolId));
    }

    private static function cacheKey(string $schoolId): string
    {
        return "school:{$schoolId}:entitled-modules";
    }

    /** @return list<string> */
    private function load(School $school): array
    {
        $enabled = array_keys(array_filter($this->optionalStates($school)));

        return [...ModuleCatalogue::coreKeys(), ...$enabled];
    }
}
