<?php

namespace App\Support\Reports;

/**
 * ORG.12H (ADR 0080 §13): the ONE code-owned catalogue of Organization
 * control-plane reports. Each report is a fixed, read-only projection with
 * explicit authority -- never a report builder, query language, tenant
 * formula, export or stored snapshot.
 *
 * - `school` reports read ONE School, inside that School's TenantContext,
 *   from the existing owners: the ORG.12A Setup Registry, the ORG.12G Hub,
 *   the ORG.12F presentation readers and the role catalogue. Authority is
 *   any of `authority`, School-wide (the page floor is
 *   `school.profile.view`; each section re-checks its own).
 * - `network` reports read ONE School Group from platform tables only,
 *   scoped by GroupGovernanceAccess::viewScope() (whole Group, Region or
 *   Zone). They never read a tenant table: no Campus count, setup
 *   readiness, person, money or business record (deferred, ADR 0080
 *   §13.4).
 *
 * Keys are stable and never reused. `reference` maps to the ORG-12
 * reference capability map (RF-nn).
 */
final class OrganizationReportCatalogue
{
    public const string SCHOOL = 'school';

    public const string NETWORK = 'network';

    /** The authority marker of every Network report: a Network read scope in that Group. */
    public const string NETWORK_SCOPE = 'network_scope';

    public const array REPORTS = [
        'organization.setup_summary' => ['title' => 'Setup summary', 'audience' => self::SCHOOL, 'authority' => ['school.profile.view'], 'source' => 'OrganizationSetupRegistry via OrganizationSetupCenter (ORG.12A/B)', 'filters' => [], 'reference' => ['RF-01', 'RF-35']],
        'organization.configuration_gaps' => ['title' => 'Configuration gaps', 'audience' => self::SCHOOL, 'authority' => ['school.profile.view'], 'source' => 'OrganizationSetupRegistry via OrganizationSetupCenter (ORG.12A/B)', 'filters' => [], 'reference' => ['RF-01']],
        'organization.integration_summary' => ['title' => 'Integrations', 'audience' => self::SCHOOL, 'authority' => ['school.settings.view'], 'source' => 'IntegrationHubReadService (ORG.12G)', 'filters' => [], 'reference' => ['RF-23', 'RF-24', 'RF-25', 'RF-26', 'RF-27', 'RF-28', 'RF-29']],
        'organization.presentation_summary' => ['title' => 'Presentation', 'audience' => self::SCHOOL, 'authority' => ['school.settings.view'], 'source' => 'BrandResolver and NavigationPresentation (ORG.12F)', 'filters' => [], 'reference' => ['RF-15', 'RF-16', 'RF-20']],
        'organization.access_summary' => ['title' => 'Access administration', 'audience' => self::SCHOOL, 'authority' => ['school.roles.view'], 'source' => 'School role catalogue and membership_role_assignments (ORG.12E)', 'filters' => [], 'reference' => ['RF-10', 'RF-12', 'RF-13', 'RF-21']],
        'network.overview' => ['title' => 'Network overview', 'audience' => self::NETWORK, 'authority' => [self::NETWORK_SCOPE], 'source' => 'school_group_members, schools (platform tables)', 'filters' => [], 'reference' => ['RF-35']],
        'network.school_directory' => ['title' => 'Schools', 'audience' => self::NETWORK, 'authority' => [self::NETWORK_SCOPE], 'source' => 'SchoolGroupDirectoryReadService (ORG.12D)', 'filters' => ['q', 'relationship', 'region_id', 'zone_id', 'status', 'attention', 'sort'], 'reference' => ['RF-11', 'RF-35']],
        'network.governance_distribution' => ['title' => 'Governance distribution', 'audience' => self::NETWORK, 'authority' => [self::NETWORK_SCOPE], 'source' => 'school_group_members, school_group_regions, school_group_zones (platform tables)', 'filters' => [], 'reference' => ['RF-11', 'RF-12', 'RF-35']],
        'network.structure_attention' => ['title' => 'Governance attention', 'audience' => self::NETWORK, 'authority' => [self::NETWORK_SCOPE], 'source' => 'SchoolGroupDirectoryReadService attention filter (ORG.12D rules)', 'filters' => ['attention'], 'reference' => ['RF-35']],
    ];

    /** @return list<string> */
    public static function keys(string $audience): array
    {
        return array_keys(array_filter(self::REPORTS, fn (array $report): bool => $report['audience'] === $audience));
    }

    /**
     * @param  list<string>  $held  the viewer's effective School capabilities
     * @return list<string> the School report keys this viewer may read
     */
    public static function schoolReportsFor(array $held): array
    {
        return array_values(array_filter(self::keys(self::SCHOOL), fn (string $key): bool => array_intersect(self::REPORTS[$key]['authority'], $held) !== []));
    }
}
