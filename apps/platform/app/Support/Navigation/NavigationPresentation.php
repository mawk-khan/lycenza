<?php

namespace App\Support\Navigation;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12F (ADR 0080 §11.4): applies a School's presentation overrides to the
 * navigation entries the actor is ALREADY allowed to see.
 *
 * Order of evaluation (never reversed):
 *   1. StaffNavigation entry exists (code-owned);
 *   2. NavigationBuilder::visibleEntries(): module entitlement, capability,
 *      Campus scope, portal availability and ownership -- security;
 *   3. here: the School's overrides for the entry's audience -- hidden (unless
 *      required), order within its group, label alias.
 *
 * It only ever REMOVES or relabels an entry it was given; it never adds one,
 * so a preference cannot surface an item security did not allow, and hiding
 * changes no route. Unknown keys (removed from code) are ignored. One query
 * per request, for the active School only; no cache.
 */
final class NavigationPresentation
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<NavigationEntry>  $visible  already security-filtered
     * @return list<array{entry: NavigationEntry, label: string}>
     */
    public function apply(array $visible, ?School $school): array
    {
        $overrides = $school === null ? [] : $this->overrides($school);
        // Default rank = the item's catalogue position, the same scale stored orders use.
        $defaults = [];
        foreach (NavigationPresentationCatalogue::AUDIENCES as $audienceKey) {
            foreach (NavigationPresentationCatalogue::items($audienceKey) as $key => $item) {
                $defaults[$key] = $item['order'];
            }
        }
        $presented = [];
        $position = [];
        foreach ($visible as $index => $entry) {
            $audience = NavigationPresentationCatalogue::audienceOf($entry);
            $override = $audience === null ? null : ($overrides[$audience][$entry->key] ?? null);
            if ($override !== null && $override->hidden && ! array_key_exists($entry->key, NavigationPresentationCatalogue::REQUIRED)) {
                continue;
            }
            $position[$entry->group] = ($position[$entry->group] ?? -1) + 1;
            $presented[] = [
                'entry' => $entry,
                'label' => (string) ($override->label_override ?? $entry->label),
                // Within its own group: the override order (or the default position), then the catalogue index.
                'rank' => (int) ($override->sort_order ?? $defaults[$entry->key] ?? $position[$entry->group]),
                'index' => $index,
            ];
        }

        // Order within each group only (groups keep their code-owned order).
        $byGroup = [];
        foreach ($presented as $item) {
            $byGroup[$item['entry']->group][] = $item;
        }
        $ordered = [];
        foreach ($byGroup as $items) {
            usort($items, fn (array $a, array $b): int => [$a['rank'], $a['index']] <=> [$b['rank'], $b['index']]);
            foreach ($items as $item) {
                $ordered[] = ['entry' => $item['entry'], 'label' => $item['label']];
            }
        }

        return $ordered;
    }

    /** @return array<string, array<string, object>> audience => key => override row */
    public function overrides(School $school): array
    {
        $read = fn () => DB::table('school_navigation_preferences')
            ->where('school_id', $school->id)
            ->get(['audience', 'navigation_key', 'hidden', 'sort_order', 'label_override']);
        // Already in this School's context (every School request): read directly, no context round trip.
        $rows = $this->context->school()?->id === $school->id ? $read() : $this->context->withSchool($school, $read);

        $byAudience = [];
        foreach ($rows as $row) {
            $byAudience[$row->audience][$row->navigation_key] = $row;
        }

        return $byAudience;
    }
}
