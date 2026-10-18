<?php

namespace Tests\Support\Authorization;

use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Domain\Platform\Application\Modules\SchoolModuleEntitlementService;
use App\Domain\Platform\Application\Roles\PlatformRoleGovernanceService;
use App\Domain\Platform\Application\Schools\SchoolLifecycleService;
use App\Support\Authorization\CapabilityResolver;
use Tests\Feature\Platform\Elevation\SchoolElevationStartTest;

/**
 * ORG.4 (ADR 0076 §28.7 follow-up, §30): the authorization contract of every
 * platform route (RouteUniverse category A) that does not carry its own
 * platform-mode `capability:` middleware -- and the real authority of the
 * mutation routes whose middleware is only a read floor (Group governance:
 * `platform.school_groups.view` on the route, `.manage` in the service).
 * Checked by PlatformRouteCoverageGuardTest with the same CoverageEvidence
 * the School routes use.
 */
final class PlatformRouteCoverage
{
    /** @return list<Coverage> */
    public static function entries(): array
    {
        return [
            // Diagnostics and account administration (platform-scoped Gate calls).
            Coverage::gate('api.internal.operations.status', 'platform.operations.view'),
            Coverage::gate('app.account.admin.mfa.reset', 'platform.users.mfa.reset'),

            // Elevation (ADR 0044): the service authorizes platform.schools.elevate.
            Coverage::service('app.platform.elevation.confirm', SchoolElevationService::class, 'prepare'),
            Coverage::service('app.platform.elevation.store', SchoolElevationService::class, 'start'),
            Coverage::owned('app.platform.elevation.exit', SchoolElevationService::class, 'finishActiveFor', "self: ends the actor's own active elevation"),
            Coverage::special(
                'app.platform.elevation.confirm.show',
                'ADR 0044',
                'Redirect-only GET for the confirmation form (a reload lands back on the start page); it reads and changes nothing.',
                SchoolElevationStartTest::class,
            ),

            // Platform-role governance (ADR 0046, ORG.1A, ORG.4).
            Coverage::service('app.platform.roles.grants.store', PlatformRoleGovernanceService::class, 'grant'),
            Coverage::service('app.platform.roles.grants.revoke', PlatformRoleGovernanceService::class, 'revoke'),

            // School onboarding and lifecycle (ADR 0047, ADR 0076 §7.2).
            Coverage::service('app.platform.schools.store', SchoolLifecycleService::class, 'create'),
            Coverage::service('app.platform.schools.perform', SchoolLifecycleService::class, 'activate'),

            // School module entitlements (ADR 0076 §31): the service authorizes .manage.
            Coverage::service('app.platform.schools.modules.perform', SchoolModuleEntitlementService::class, 'enable'),

            // Group governance: the route floor is view; the service requires manage.
            Coverage::service('app.platform.groups.store', SchoolGroupGovernanceService::class, 'create'),
            Coverage::service('app.platform.groups.rename', SchoolGroupGovernanceService::class, 'rename'),
            Coverage::service('app.platform.groups.archive', SchoolGroupGovernanceService::class, 'archive'),
            Coverage::service('app.platform.groups.schools.store', SchoolGroupGovernanceService::class, 'addSchool'),
            Coverage::service('app.platform.groups.schools.destroy', SchoolGroupGovernanceService::class, 'removeSchool'),
            Coverage::service('app.platform.groups.grants.store', SchoolGroupGovernanceService::class, 'grant'),
            Coverage::service('app.platform.groups.grants.revoke', SchoolGroupGovernanceService::class, 'revoke'),

            // Group scope (ADR 0045, rule 84): resolved only through canInGroup().
            Coverage::owned('app.groups.index', CapabilityResolver::class, 'groupsWith', "Group scope: the actor's own Group grants"),
            Coverage::owned('app.groups.show', CapabilityResolver::class, 'canInGroup', 'Group scope: group.schools.view in that Group, else 404'),
            Coverage::owned('app.groups.elevation.create', CapabilityResolver::class, 'canInGroup', 'Group scope: group.schools.elevate in that Group, else 404'),
            Coverage::service('app.groups.elevation.confirm', SchoolElevationService::class, 'prepare'),
            Coverage::service('app.groups.elevation.store', SchoolElevationService::class, 'start'),
            Coverage::service('app.groups.reports.curriculum-coverage', GroupCurriculumCoverageReportService::class, 'generate'),
        ];
    }
}
