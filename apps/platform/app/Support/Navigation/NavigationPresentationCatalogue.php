<?php

namespace App\Support\Navigation;

/**
 * ORG.12F (ADR 0080 §11.3, OD-12.16/OD-12.19): which navigation items a
 * School may PRESENT differently, derived from the one authoritative catalogue
 * (StaffNavigation) -- never a second list of routes or permissions.
 *
 * - Audiences: only implemented surfaces -- `staff_web` (every School entry
 *   outside the Guardian portal group) and `guardian_portal` (the Guardian
 *   portal group, development-only until POR-L1). There is no Student portal
 *   or mobile navigation, so neither audience exists here.
 * - An item is a School-scope StaffNavigation entry: its stable key, default
 *   label, code-owned group and default order (its position in the group).
 *   Platform, Group and account entries, and the shell's Home and Log out,
 *   are never School presentation.
 * - REQUIRED items can never be hidden: the presentation editor itself (so a
 *   School cannot hide the way back), and the Guardian portal's messages
 *   from the School (they carry required notices).
 * - Presentation may change order (within the group), hide an eligible item,
 *   and alias its label. It never changes the destination, capability,
 *   module, scope or ownership: those stay on StaffNavigation and the route.
 * - No icons: the shell has no icon set, and a School cannot supply one.
 */
final class NavigationPresentationCatalogue
{
    public const STAFF_WEB = 'staff_web';

    public const GUARDIAN_PORTAL = 'guardian_portal';

    public const AUDIENCES = [self::STAFF_WEB, self::GUARDIAN_PORTAL];

    public const GUARDIAN_GROUP = 'guardian-portal';

    /** Never hideable, with the reason (code-owned). */
    public const REQUIRED = [
        'presentation' => 'The presentation editor itself: hiding it would remove the way back.',
        'portal-communications' => 'Messages from the School carry required notices to Guardians.',
    ];

    public const MAX_LABEL = 40;

    /**
     * @return array<string, array{key: string, audience: string, label: string, group: string, groupLabel: string, order: int, hideable: bool, required: ?string}>
     */
    public static function items(string $audience): array
    {
        $items = [];
        $positions = [];
        foreach (StaffNavigation::entries() as $entry) {
            if ($entry->scope !== NavigationEntry::SCOPE_SCHOOL || self::audienceOf($entry) !== $audience) {
                continue;
            }
            $positions[$entry->group] = ($positions[$entry->group] ?? -1) + 1;
            $items[$entry->key] = [
                'key' => $entry->key,
                'audience' => $audience,
                'label' => $entry->label,
                'group' => $entry->group,
                'groupLabel' => StaffNavigation::GROUPS[$entry->group] ?? $entry->group,
                'order' => $positions[$entry->group],
                'hideable' => ! array_key_exists($entry->key, self::REQUIRED),
                'required' => self::REQUIRED[$entry->key] ?? null,
            ];
        }

        return $items;
    }

    public static function audienceOf(NavigationEntry $entry): ?string
    {
        if ($entry->scope !== NavigationEntry::SCOPE_SCHOOL) {
            return null;
        }

        return $entry->group === self::GUARDIAN_GROUP ? self::GUARDIAN_PORTAL : self::STAFF_WEB;
    }

    public static function isKnown(string $audience, string $key): bool
    {
        return isset(self::items($audience)[$key]);
    }
}
