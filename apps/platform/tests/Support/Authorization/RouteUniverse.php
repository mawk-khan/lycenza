<?php

namespace Tests\Support\Authorization;

use Illuminate\Routing\Route;

/**
 * ORG.2 (ADR 0076 §28): every registered HTTP route belongs to exactly one
 * place in the route universe.
 *
 * A route is SCHOOL-FACING when it carries `school-context` (signed-in
 * School web) or `school-membership` (School API), or is one of the School
 * context-establishment routes (`SchoolRouteCoverage::special()`). Every
 * School-facing route needs a coverage contract (C1 from its own
 * `capability:` middleware, otherwise a SchoolRouteCoverage entry).
 *
 * Every other route is listed below by identity with its category and why
 * it is not a School route -- a new route of any kind fails the guard until
 * it is classified. No prefix, wildcard or HTTP-verb exemption exists.
 */
final class RouteUniverse
{
    public const PLATFORM = 'A';

    public const SCHOOL_WEB = 'B';

    public const SCHOOL_API = 'C';

    public const OWNED = 'D';

    public const PARTNER_INTERNAL = 'E';

    public const CONTEXT = 'F';

    public const GATED = 'G';

    public const PUBLIC = 'H';

    /** Availability middleware (legal / environment gates), category G. */
    public const AVAILABILITY_MIDDLEWARE = [
        'portal-development-only',
        'marks-development-only',
        'teacher-marks-development-only',
        'teacher-attendance-api',
    ];

    /**
     * Non-School routes: identity => [category, authority / why not a School route].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const NON_SCHOOL = [
        // A. Platform context -- platform or Group authority, never School (ADR 0044-0047, 0054).
        'app.platform.audit-log' => [self::PLATFORM, 'capability:platform.audit.view,platform + MFA (ADR 0046)'],
        'app.platform.elevation.create' => [self::PLATFORM, 'capability:platform.schools.elevate,platform (ADR 0044)'],
        'app.platform.elevation.store' => [self::PLATFORM, 'SchoolElevationService authorizes platform.schools.elevate + MFA (ADR 0044)'],
        'app.platform.elevation.confirm' => [self::PLATFORM, 'SchoolElevationService authorizes + MFA re-verification (ADR 0044)'],
        'app.platform.elevation.confirm.show' => [self::PLATFORM, 'redirect-only GET for the confirm form (ADR 0044)'],
        'app.platform.elevation.exit' => [self::PLATFORM, 'ends the actor\'s own elevation (ADR 0044)'],
        'app.platform.groups.index' => [self::PLATFORM, 'capability:platform.school_groups.view,platform (ADR 0045)'],
        'app.platform.groups.store' => [self::PLATFORM, 'capability:platform.school_groups.view + SchoolGroupGovernanceService requires platform.school_groups.manage (ADR 0045)'],
        'app.platform.groups.show' => [self::PLATFORM, 'capability:platform.school_groups.view,platform (ADR 0045)'],
        'app.platform.groups.rename' => [self::PLATFORM, 'capability:platform.school_groups.view + SchoolGroupGovernanceService requires platform.school_groups.manage (ADR 0045)'],
        'app.platform.groups.archive' => [self::PLATFORM, 'capability:platform.school_groups.view + SchoolGroupGovernanceService requires platform.school_groups.manage (ADR 0045)'],
        'app.platform.groups.grants.store' => [self::PLATFORM, 'capability:platform.school_groups.view + SchoolGroupGovernanceService requires platform.school_group_grants.manage (ADR 0045)'],
        'app.platform.groups.grants.revoke' => [self::PLATFORM, 'capability:platform.school_groups.view + SchoolGroupGovernanceService requires platform.school_group_grants.manage (ADR 0045)'],
        'app.platform.groups.schools.store' => [self::PLATFORM, 'capability:platform.school_groups.view + SchoolGroupGovernanceService requires platform.school_groups.manage (ADR 0045)'],
        'app.platform.groups.schools.destroy' => [self::PLATFORM, 'capability:platform.school_groups.view + SchoolGroupGovernanceService requires platform.school_groups.manage (ADR 0045)'],
        'app.platform.roles.index' => [self::PLATFORM, 'capability:platform.role_grants.manage,platform (ADR 0046)'],
        'app.platform.roles.grants.store' => [self::PLATFORM, 'PlatformRoleGovernanceService authorizes + fresh MFA (ADR 0046, ORG.1A)'],
        'app.platform.roles.grants.revoke' => [self::PLATFORM, 'PlatformRoleGovernanceService authorizes + fresh MFA (ADR 0046, ORG.1A)'],
        'app.platform.schools.index' => [self::PLATFORM, 'capability:platform.schools.view,platform -- the School Registry (ADR 0076 §9.2)'],
        'app.platform.schools.create' => [self::PLATFORM, 'capability:platform.schools.manage,platform (ADR 0047)'],
        'app.platform.schools.store' => [self::PLATFORM, 'SchoolLifecycleService authorizes platform.schools.manage + MFA (ADR 0047)'],
        'app.platform.schools.show' => [self::PLATFORM, 'capability:platform.schools.view,platform -- the Registry overview; audit events via the platform audit review (ADR 0076 §9.2)'],
        'app.platform.schools.review' => [self::PLATFORM, 'capability:platform.schools.manage,platform (ADR 0047)'],
        'app.platform.schools.perform' => [self::PLATFORM, 'SchoolLifecycleService authorizes platform.schools.manage + MFA (ADR 0047)'],
        'app.groups.index' => [self::PLATFORM, 'Group scope: CapabilityResolver::groupsWith() (ADR 0045, rule 84)'],
        'app.groups.show' => [self::PLATFORM, 'Group scope: canInGroup(group.schools.view), else 404 (ADR 0045)'],
        'app.groups.elevation.create' => [self::PLATFORM, 'Group scope: canInGroup + SchoolElevationService (ADR 0045)'],
        'app.groups.elevation.store' => [self::PLATFORM, 'Group scope: SchoolElevationService authorizes + MFA (ADR 0045)'],
        'app.groups.elevation.confirm' => [self::PLATFORM, 'Group scope: SchoolElevationService authorizes + MFA (ADR 0045)'],
        'app.groups.reports.curriculum-coverage' => [self::PLATFORM, 'Group scope: GroupSafeReportGate, group.reporting.view (ADR 0048)'],
        'app.account.admin.mfa.reset' => [self::PLATFORM, 'authorizeCapability(platform.users.mfa.reset, platform)'],
        'internal.mfa-demo.ping' => [self::PLATFORM, 'capability:platform.operations.view,platform + mfa; local/testing only'],
        'api.internal.operations.status' => [self::PLATFORM, 'authorizeCapability(platform.operations.view, platform) (rule 63)'],

        // E. Partner / internal service API -- service or partner credentials, never a School user.
        'api.internal.ai.audit.store' => [self::PARTNER_INTERNAL, 'service-auth assertion, AI Gateway only (ADR 0014/0023)'],
        'api.internal.ai.completions.authorize' => [self::PARTNER_INTERNAL, 'service-auth assertion; re-checks the actor capability (ADR 0023)'],
        'api.internal.ai.tools.school-echo' => [self::PARTNER_INTERNAL, 'service-auth assertion + signed context token (ADR 0023)'],
        'api.v1.partner.probe' => [self::PARTNER_INTERNAL, 'auth:partner + partner-scope:partner.probe.read (ADR 0049)'],
        'api.integrations.email-provider.events' => [self::PARTNER_INTERNAL, 'provider adapter authenticates the event (ADR 0055)'],

        // F. Authentication / account / context establishment (not School data).
        'login' => [self::CONTEXT, 'guest sign-in form'],
        'login.store' => [self::CONTEXT, 'guest sign-in, throttled'],
        'login.mfa' => [self::CONTEXT, 'guest MFA challenge form'],
        'login.mfa.store' => [self::CONTEXT, 'guest MFA challenge, throttled'],
        'logout' => [self::CONTEXT, 'signs the actor out'],
        'account-activation.edit' => [self::CONTEXT, 'one-time activation link (ADR 0059)'],
        'account-activation.update' => [self::CONTEXT, 'one-time activation link (ADR 0059)'],
        'account-recovery.create' => [self::CONTEXT, 'enumeration-resistant recovery (ADR 0056)'],
        'account-recovery.store' => [self::CONTEXT, 'enumeration-resistant recovery (ADR 0056)'],
        'account-recovery.edit' => [self::CONTEXT, 'enumeration-resistant recovery (ADR 0056)'],
        'account-recovery.update' => [self::CONTEXT, 'enumeration-resistant recovery (ADR 0056)'],
        'app.dashboard' => [self::CONTEXT, 'the /app landing; shows only the actor\'s own Schools (D9(a))'],
        'app.account.security.show' => [self::CONTEXT, 'the User\'s own account security'],
        'app.account.security.password-confirmation' => [self::CONTEXT, 'the User\'s own account security'],
        'app.account.security.mfa.begin' => [self::CONTEXT, 'the User\'s own MFA enrolment'],
        'app.account.security.mfa.confirm' => [self::CONTEXT, 'the User\'s own MFA enrolment'],
        'app.account.security.mfa.recovery-codes.regenerate' => [self::CONTEXT, 'the User\'s own MFA recovery codes'],
        'app.account.security.mfa.disable' => [self::CONTEXT, 'the User\'s own MFA'],
        'app.account.api-tokens.index' => [self::CONTEXT, 'the User\'s own API tokens (ADR 0049)'],
        'app.account.api-tokens.store' => [self::CONTEXT, 'the User\'s own API tokens (ADR 0049)'],
        'app.account.api-tokens.destroy' => [self::CONTEXT, 'the User\'s own API tokens (ADR 0049)'],
        'session.handoff' => [self::CONTEXT, 'one-time CrossHostHandoff ticket -- continuity, never authority (ADR 0054)'],
        'sanctum.csrf-cookie' => [self::CONTEXT, 'framework CSRF cookie'],

        // H. Public / non-School.
        'home' => [self::PUBLIC, 'public landing'],
        'GET up' => [self::PUBLIC, 'framework liveness probe (rule 55)'],
        'api.health.live' => [self::PUBLIC, 'liveness (rule 55)'],
        'api.health.ready' => [self::PUBLIC, 'readiness (rule 55)'],
        'api.v1.system.status' => [self::PUBLIC, 'public, unauthenticated system status'],
        'domains.probe' => [self::PUBLIC, 'custom-domain probe path (ADR 0054)'],
        'api.v1.education-boards.index' => [self::PUBLIC, 'platform reference catalog, no School data (rule 69)'],
        'storage.local' => [self::PUBLIC, 'framework private-disk serving: valid signature required; nothing signs URLs (DocumentControllerStructuralTest)'],
        'storage.local.upload' => [self::PUBLIC, 'framework private-disk upload: valid signature required; nothing signs URLs'],
    ];

    public static function key(Route $route): string
    {
        return $route->getName() ?? implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
    }

    public static function isSchoolFacing(Route $route): bool
    {
        $middleware = $route->gatherMiddleware();

        return in_array('school-context', $middleware, true)
            || in_array('school-membership', $middleware, true)
            || array_key_exists(self::key($route), SchoolRouteCoverage::special());
    }

    /** The School-route category: web / API, with owned and gated surfaces split out for reporting. */
    public static function schoolCategory(Route $route, ?Coverage $coverage): string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && in_array(explode(':', $middleware)[0], self::AVAILABILITY_MIDDLEWARE, true)) {
                return self::GATED;
            }
        }
        if ($coverage?->class === Coverage::OWNED) {
            return self::OWNED;
        }
        if ($coverage?->class === Coverage::SPECIAL) {
            return self::CONTEXT;
        }

        return in_array('school-membership', $route->gatherMiddleware(), true) ? self::SCHOOL_API : self::SCHOOL_WEB;
    }
}
