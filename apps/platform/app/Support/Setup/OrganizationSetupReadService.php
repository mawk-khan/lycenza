<?php

namespace App\Support\Setup;

use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ORG.12A (ADR 0080 §9): evaluates the Organization Control Plane's setup
 * catalogue for ONE School, for one viewer. Read-only.
 *
 * - Authority: the viewer must hold the setup floor (`school.profile.view`,
 *   the capability the existing School setup progress already uses, ADR
 *   0070 §9.6) in THIS School, through an active membership. A platform
 *   role, an elevation or a Group grant confers no School capability, so
 *   none of them can read a School's readiness here.
 * - Visibility per area: an area whose optional modules are not all
 *   entitled is NOT_APPLICABLE (shown, without evaluation -- an unentitled
 *   module never makes the School "incomplete"). An applicable area is
 *   returned only to a viewer who also holds ALL of its view capabilities.
 * - Dependencies: every area is evaluated in catalogue order (dependencies
 *   first) inside TenantContext::withSchool($school). An area whose own
 *   answer is incomplete -- or that has no setup of its own -- while a
 *   dependency is unsatisfied is BLOCKED; one configured by its own checks
 *   while a dependency is unsatisfied needs ATTENTION.
 *   Blockers are setup KEYS only -- code constants, never tenant data --
 *   and only for areas the viewer can see; any other blocker is counted
 *   (`hiddenBlockers`), never named (ORG.12B).
 * - Tenancy: one School per call, an ACTIVE School only (rule 86);
 *   nothing is aggregated across Schools. Readiness itself is never cached;
 *   the capability and entitlement readers it calls keep their existing
 *   tenant-namespaced 60 s caches.
 * - Read-only: nothing here or in any provider writes, audits or stores a
 *   result (OrganizationSetupReadServiceTest).
 */
class OrganizationSetupReadService
{
    public const string FLOOR_CAPABILITY = 'school.profile.view';

    public function __construct(
        private readonly OrganizationSetupRegistry $registry,
        private readonly SetupReadinessProviders $providers,
        private readonly CapabilityResolver $capabilities,
        private readonly SchoolModuleEntitlements $entitlements,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{schoolId: string, evaluatedAt: string, items: list<array<string, mixed>>}
     *
     * @throws AuthorizationException when the School is not active or the viewer lacks the setup floor in it
     */
    public function evaluate(School $school, User $actor): array
    {
        // Rule 86: a School operates only while active. The School entry
        // middlewares already refuse others; this keeps a non-HTTP caller
        // (console, job, a later route) to the same rule.
        if (! $school->isActive()) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $held = $this->capabilities->schoolCapabilities($actor, $school);
        if (! in_array(self::FLOOR_CAPABILITY, $held, true)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $entitled = $this->entitlements->entitledModules($school);
        $evaluatedAt = now()->toIso8601String();
        $results = $this->context->withSchool($school, fn (): array => $this->evaluateAll($school, $entitled));

        $visible = [];
        foreach ($this->registry->all() as $definition) {
            $status = $results[$definition->key]['status'];
            $visible[$definition->key] = $status === SetupStatus::NOT_APPLICABLE || array_diff($definition->viewCapabilities, $held) === [];
        }

        $items = [];
        foreach ($this->registry->all() as $definition) {
            if (! $visible[$definition->key]) {
                continue;
            }
            $result = $results[$definition->key];
            // ORG.12B: a prerequisite the viewer cannot see is never named,
            // only counted (ORG.12A L-2 closed).
            $blockers = $result['blockers'];
            $named = array_values(array_filter($blockers, fn (string $key): bool => $visible[$key]));
            $items[] = [
                ...$definition->toArray(),
                'status' => $result['status']->value,
                'explanation' => $result['explanation'],
                'completedChecks' => $result['completedChecks'],
                'totalChecks' => $result['totalChecks'],
                'blockers' => $named,
                'hiddenBlockers' => count($blockers) - count($named),
            ];
        }

        return ['schoolId' => $school->id, 'evaluatedAt' => $evaluatedAt, 'items' => $items];
    }

    /**
     * @param  list<string>  $entitled
     * @return array<string, array{status: SetupStatus, explanation: string, completedChecks: ?int, totalChecks: ?int, blockers: list<string>}>
     */
    private function evaluateAll(School $school, array $entitled): array
    {
        $results = [];
        foreach ($this->registry->all() as $definition) {
            if (array_diff($definition->entitlementModules, $entitled) !== []) {
                $results[$definition->key] = [
                    'status' => SetupStatus::NOT_APPLICABLE,
                    'explanation' => 'This optional module is not enabled for the School.',
                    'completedChecks' => null,
                    'totalChecks' => null,
                    'blockers' => [],
                ];

                continue;
            }

            $readiness = $this->providers->for($definition->key)->evaluate($definition->key, $school);
            $blockers = array_values(array_filter(
                $definition->dependsOn,
                fn (string $dependency): bool => ! $results[$dependency]['status']->satisfiesDependents(),
            ));

            $results[$definition->key] = [
                'status' => self::compose($readiness, $blockers),
                'explanation' => $readiness->explanation,
                'completedChecks' => $readiness->completedChecks,
                'totalChecks' => $readiness->totalChecks,
                'blockers' => $blockers,
            ];
        }

        return $results;
    }

    /**
     * An area's own answer combined with its unsatisfied dependencies. An
     * area that needs no setup of its own (no checks) IS its dependencies,
     * so it is BLOCKED by them; an area configured by its own checks keeps
     * its configuration and needs ATTENTION.
     *
     * @param  list<string>  $blockers
     */
    public static function compose(SetupReadiness $own, array $blockers): SetupStatus
    {
        if ($blockers === []) {
            return $own->status;
        }

        return match ($own->status) {
            SetupStatus::NOT_CONFIGURED, SetupStatus::PARTIALLY_CONFIGURED => SetupStatus::BLOCKED,
            SetupStatus::CONFIGURED => $own->totalChecks === null ? SetupStatus::BLOCKED : SetupStatus::ATTENTION_REQUIRED,
            default => $own->status,
        };
    }
}
