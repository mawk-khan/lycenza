<?php

namespace App\Support\Authorization\Campus;

/**
 * ORG.7A (ADR 0076 §11.3, §33): the ONE code-owned declaration of which
 * School capabilities have been ADOPTED for Campus scope, and by which
 * domain. A Campus-scoped role grant confers a capability only when it is
 * listed here (fail closed until adoption); every other capability of that
 * role confers nothing through the scoped grant.
 *
 * Adoption never happens because a table merely has a `campus_id`: a domain
 * is listed only after its read and write paths apply its CampusScope
 * (`CampusScopeAdoptionArchitectureTest` checks every adopted capability
 * against its domain's scoped paths). Each domain declares its policy for
 * School-wide (NULL-Campus) records and inactive Campuses.
 *
 * Never listed: ORG-C2 whole-School authority (access administration,
 * School configuration, integrations, School-wide academic configuration).
 */
final class CampusScopeCatalogue
{
    /**
     * Adopted domains and their declared policies.
     *
     * @var array<string, array{label: string, nullCampus: string, inactiveCampus: string, sharedLookups: string}>
     */
    public const DOMAINS = [
        'visitor' => [
            'label' => 'Visitor check-in and visits',
            'nullCampus' => 'None: every Visit has a Campus (visitor_visits.campus_id NOT NULL).',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing (no read, no new Visit, no check-out); whole-School holders keep every Visit.',
            'sharedLookups' => 'The check-in lookups of the School-wide Visitor directory (active Visitors: id, name, phone) and of active host Employees (id, name) answer a Campus-scoped visits.manage holder; the directory itself (visitor.directory.*) stays School-only.',
        ],
        'inventory' => [
            'label' => 'Inventory stock',
            'nullCampus' => 'A Location without a Campus is a School-wide store: whole-School scope only (never listed, read or moved by a Campus-scoped holder).',
            'inactiveCampus' => 'A grant on an inactive Campus confers nothing (no read, no movement); whole-School holders keep everything.',
            'sharedLookups' => 'The stock pages\' active Item lookup (the School-wide catalogue: id, code, name, unit) answers a Campus-scoped stock.manage holder; the Item/Location directory (inventory.directory.*) stays School-only. A movement is visible only when every Location it touches is in scope; a transfer needs scope over both Locations.',
        ],
    ];

    /**
     * Adopted capability => its domain.
     *
     * @var array<string, string>
     */
    public const ADOPTED = [
        'visitor.visits.view' => 'visitor',
        'visitor.visits.manage' => 'visitor',
        'inventory.stock.view' => 'inventory',
        'inventory.stock.manage' => 'inventory',
    ];

    public static function isAdopted(string $capability): bool
    {
        return isset(self::ADOPTED[$capability]);
    }

    /** @return list<string> */
    public static function capabilities(): array
    {
        return array_keys(self::ADOPTED);
    }

    /** @return list<string> the product-area labels of the adopted capabilities among $capabilities */
    public static function areasOf(array $capabilities): array
    {
        $areas = [];
        foreach (self::adoptedAmong($capabilities) as $capability) {
            $areas[self::ADOPTED[$capability]] = self::DOMAINS[self::ADOPTED[$capability]]['label'];
        }

        return array_values($areas);
    }

    /** @return list<string> the adopted capabilities among $capabilities */
    public static function adoptedAmong(array $capabilities): array
    {
        return array_values(array_filter($capabilities, fn (string $c): bool => self::isAdopted($c)));
    }
}
