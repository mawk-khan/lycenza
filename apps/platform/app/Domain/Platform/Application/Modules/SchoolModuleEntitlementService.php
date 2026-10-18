<?php

namespace App\Domain\Platform\Application\Modules;

use App\Domain\Platform\Infrastructure\SchoolModuleEntitlement;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\MfaReverificationService;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Navigation\NavigationEntry;
use App\Support\Navigation\StaffNavigation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG.5 (ADR 0076 §12.6, §31): the ONLY writer of School module
 * entitlements. Enables or disables one OPTIONAL module for one School.
 *
 * Order (a refusal never spends a code, ORG.1A):
 *   account enabled -> `platform.school_entitlements.manage` -> the module is
 *   known and optional (core is never managed) -> the School is not closed
 *   -> a closed reason code -> a real change -> dependencies (enable: every
 *   optional dependency enabled; disable: no enabled dependant) -> explicit
 *   confirmation -> an enrolled factor -> a FRESH code (MfaReverificationService)
 *   -> one transaction: the School row FOR SHARE and a per-School advisory
 *   lock, everything re-checked, the row written, the platform audit event.
 *
 * Disabling deletes, revokes and archives nothing: grants, data, files and
 * configuration stay; the module's capabilities simply resolve to nothing
 * (CapabilityResolver), and re-enabling restores their effect. Refusals are
 * audited once as `platform.school_entitlement.denied` (outcome code only);
 * plain input validation is not. Never School authority, never the School
 * audit ledger, never a lifecycle change.
 */
class SchoolModuleEntitlementService
{
    public const VIEW_CAPABILITY = 'platform.school_entitlements.view';

    public const MANAGE_CAPABILITY = 'platform.school_entitlements.manage';

    /** Closed reason codes: platform configuration intent, not billing. */
    public const REASONS = ['onboarding_configuration', 'school_request', 'platform_policy', 'operational_correction'];

    public const ENABLED_EVENT = 'platform.school_entitlement.enabled';

    public const DISABLED_EVENT = 'platform.school_entitlement.disabled';

    public const DENIED_EVENT = 'platform.school_entitlement.denied';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolModuleEntitlements $entitlements,
        private readonly MfaReverificationService $mfa,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function canView(User $actor): bool
    {
        return ! $actor->isDisabled() && $this->capabilities->canPlatform($actor, self::VIEW_CAPABILITY);
    }

    public function canManage(User $actor): bool
    {
        return ! $actor->isDisabled() && $this->capabilities->canPlatform($actor, self::MANAGE_CAPABILITY);
    }

    /**
     * The School's modules for the Platform overview -- metadata only.
     *
     * @return list<array{key: string, label: string, description: string, core: bool, enabled: bool, dependsOn: list<string>, dependents: list<string>}>
     */
    public function overview(School $school): array
    {
        $states = $this->entitlements->optionalStates($school);
        $modules = [];
        foreach (ModuleCatalogue::MODULES as $key => $module) {
            $modules[] = [
                'key' => $key,
                'label' => $module['label'],
                'description' => $module['description'],
                'core' => $module['core'],
                'enabled' => $module['core'] || ($states[$key] ?? false),
                'dependsOn' => $module['dependsOn'],
                'dependents' => ModuleCatalogue::dependentsOf($key),
            ];
        }

        return $modules;
    }

    /**
     * Advisory impact of disabling $module: dependants still enabled, the
     * capabilities that would resolve to nothing, how many active School
     * role grants carry one of them, and the navigation entries that would
     * disappear. Counts and keys only -- no names. The mutation re-checks
     * everything at execution time.
     *
     * @return array{dependentsEnabled: list<string>, capabilities: list<string>, activeGrants: int, navigation: list<string>}
     */
    public function impact(School $school, string $module): array
    {
        $states = $this->entitlements->optionalStates($school);
        $catalogue = DB::table('capabilities')->whereIn('namespace', ['school', 'guardian'])->orderBy('key')->pluck('key')->all();
        $capabilities = ModuleCatalogue::capabilitiesOf($module, $catalogue);

        $activeGrants = $capabilities === [] ? 0 : (int) $this->context->withSchool($school, fn () => DB::table('membership_role_assignments as a')
            ->where('a.school_id', $school->id)
            ->whereNull('a.revoked_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('role_capabilities as rc')->whereColumn('rc.role_id', 'a.role_id')->whereIn('rc.capability_key', $capabilities))
            ->count());

        $navigation = [];
        foreach (StaffNavigation::entries() as $entry) {
            $required = $entry->capabilities();
            if ($entry->scope === NavigationEntry::SCOPE_SCHOOL && $required !== [] && array_diff($required, $capabilities) === []) {
                $navigation[] = $entry->label;
            }
        }

        return [
            'dependentsEnabled' => array_values(array_filter(ModuleCatalogue::dependentsOf($module), fn (string $d): bool => $states[$d] ?? false)),
            'capabilities' => $capabilities,
            'activeGrants' => $activeGrants,
            'navigation' => $navigation,
        ];
    }

    public function enable(Request $request, User $actor, School $school, string $module, mixed $reason, mixed $confirmed, mixed $code): void
    {
        $this->change($request, $actor, $school, $module, true, $reason, $confirmed, $code);
    }

    public function disable(Request $request, User $actor, School $school, string $module, mixed $reason, mixed $confirmed, mixed $code): void
    {
        $this->change($request, $actor, $school, $module, false, $reason, $confirmed, $code);
    }

    private function change(Request $request, User $actor, School $school, string $module, bool $enable, mixed $reason, mixed $confirmed, mixed $code): void
    {
        $this->requireManage($actor, $school, $module);
        if (! ModuleCatalogue::exists($module)) {
            $this->deny($actor, $school, $module, 'unknown_module', 422, 'module', 'There is no such module.');
        }
        if (ModuleCatalogue::isCore($module)) {
            $this->deny($actor, $school, $module, 'core_module', 422, 'module', 'A core module is always available and cannot be changed.');
        }
        if ($school->fresh()?->isClosed() !== false) {
            $this->deny($actor, $school, $module, 'school_closed', 409, 'module', 'A closed School\'s modules cannot be changed. Reopen it first.');
        }
        if (! is_string($reason) || ! in_array($reason, self::REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => 'Choose a reason.']);
        }

        $states = $this->entitlements->optionalStates($school);
        if ($states[$module] === $enable) {
            throw ValidationException::withMessages(['module' => $enable ? 'This module is already enabled.' : 'This module is already disabled.']);
        }
        $this->checkDependencies($actor, $school, $module, $enable, $states);

        $this->confirmAndReverify($request, $actor, $school, $module, $confirmed, $code);

        $impact = $enable ? null : $this->impact($school, $module);

        $outcome = DB::transaction(function () use ($actor, $school, $module, $enable, $reason, $impact): ?string {
            $locked = School::query()->whereKey($school->id)->sharedLock()->first();
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['school_entitlements:'.$school->id]);

            if ($locked === null || $locked->isClosed()) {
                return 'school_closed';
            }

            $states = $this->entitlements->optionalStates($locked);
            if ($states[$module] === $enable) {
                return 'state_changed';
            }
            if ($this->dependencyFailure($module, $enable, $states) !== null) {
                return $enable ? 'dependency_disabled' : 'dependents_enabled';
            }

            $this->context->withSchool($locked, function () use ($locked, $module, $enable, $reason): void {
                $row = SchoolModuleEntitlement::query()->where('school_id', $locked->id)->where('module_key', $module)->first()
                    ?? new SchoolModuleEntitlement;
                $row->forceFill([
                    'school_id' => $locked->id,
                    'module_key' => $module,
                    'enabled' => $enable,
                    'reason_code' => $reason,
                    'changed_at' => now(),
                ])->save();
            });

            $this->audit->platform($enable ? self::ENABLED_EVENT : self::DISABLED_EVENT, actor: $actor, subject: $locked, metadata: array_filter([
                'module_key' => $module,
                'from' => $enable ? 'disabled' : 'enabled',
                'to' => $enable ? 'enabled' : 'disabled',
                'reason_code' => $reason,
                'affected_capabilities' => $impact === null ? null : count($impact['capabilities']),
                'affected_active_grants' => $impact['activeGrants'] ?? null,
            ], fn ($value) => $value !== null));

            DB::afterCommit(fn () => $this->entitlements->forget($locked->id));

            return null;
        });

        if ($outcome !== null) {
            $this->deny($actor, $school, $module, $outcome, 409, 'module', 'The School\'s modules changed meanwhile. Review them and try again.');
        }

        $this->entitlements->forget($school->id);
    }

    /** An enabled account holding `platform.school_entitlements.manage`, else an audited 403. */
    private function requireManage(User $actor, School $school, string $module): void
    {
        if ($actor->isDisabled()) {
            $this->deny($actor, $school, $module, 'actor_disabled', 403, 'module', 'This account cannot manage School modules.');
        }
        if (! $this->capabilities->canPlatform($actor, self::MANAGE_CAPABILITY)) {
            $this->deny($actor, $school, $module, 'capability_missing', 403, 'module', 'This account cannot manage School modules.');
        }
    }

    /** @param  array<string, bool>  $states */
    private function checkDependencies(User $actor, School $school, string $module, bool $enable, array $states): void
    {
        $failure = $this->dependencyFailure($module, $enable, $states);
        if ($failure === null) {
            return;
        }

        $labels = implode(', ', array_map(fn (string $m) => ModuleCatalogue::MODULES[$m]['label'], $failure));
        $enable
            ? $this->deny($actor, $school, $module, 'dependency_disabled', 422, 'module', "Enable {$labels} first.")
            : $this->deny($actor, $school, $module, 'dependents_enabled', 422, 'module', "Disable {$labels} first: they depend on this module.");
    }

    /**
     * @param  array<string, bool>  $states
     * @return list<string>|null the blocking modules, or null when none
     */
    private function dependencyFailure(string $module, bool $enable, array $states): ?array
    {
        $blocking = $enable
            ? array_filter(ModuleCatalogue::dependenciesOf($module), fn (string $d): bool => ! ModuleCatalogue::isCore($d) && ! ($states[$d] ?? false))
            : array_filter(ModuleCatalogue::dependentsOf($module), fn (string $d): bool => $states[$d] ?? false);

        return $blocking === [] ? null : array_values($blocking);
    }

    private function confirmAndReverify(Request $request, User $actor, School $school, string $module, mixed $confirmed, mixed $code): void
    {
        if (! in_array($confirmed, [true, 1, '1', 'true', 'on', 'yes'], true)) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm this change to continue.']);
        }
        if (! $this->mfa->hasActiveFactor($actor)) {
            $this->deny($actor, $school, $module, 'mfa_not_enrolled', 403, 'mfa_code', 'This action requires multi-factor authentication. Enroll a factor under Account security first.');
        }
        if (! is_string($code) || trim($code) === '') {
            throw ValidationException::withMessages(['mfa_code' => 'Enter a current authentication code.']);
        }
        if (! $this->mfa->reverify($request, $actor, trim($code))) {
            $this->deny($actor, $school, $module, 'mfa_verification_failed', 422, 'mfa_code', 'That code is not valid.');
        }
    }

    private function deny(User $actor, School $school, string $module, string $outcome, int $status, string $field, string $message): never
    {
        $this->audit->platform(self::DENIED_EVENT, actor: $actor, subject: $school, metadata: array_filter([
            'module_key' => ModuleCatalogue::exists($module) ? $module : null,
            'outcome_code' => $outcome,
        ], fn ($value) => $value !== null));

        throw new SchoolModuleEntitlementDeniedException($message, $status, $field, $outcome);
    }
}
