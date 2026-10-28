<?php

namespace App\Support\Setup;

use App\Domain\Platform\Application\Modules\ModuleCatalogue;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ORG.12B (ADR 0080 §7): the Organization Setup & Readiness Center's view
 * of ONE School for one viewer -- presentation over
 * OrganizationSetupReadService, recomputed on every request and never
 * stored.
 *
 * - Progress counts setup AREAS, never people or records. Core and enabled
 *   optional areas are separate figures: configured / applicable. A
 *   NOT_APPLICABLE area is in neither denominator; only CONFIGURED counts
 *   as done. There is no "ready" verdict.
 * - Actions are resolved server-side: a link only when the viewer holds,
 *   School-wide, one of the capabilities the destination page itself checks
 *   (`open`); "Configure" when they also hold every `manage` capability;
 *   otherwise no link. The destination stays authoritative -- a link is
 *   never authority, and a missing link never hides a status the viewer
 *   may read.
 * - Next actions: at most three incomplete areas the viewer can CONFIGURE,
 *   in a fixed priority (unblocking first, then core before optional, then
 *   not configured / partial / attention), ties by registry order.
 * - Prerequisites are named only when the viewer can see them
 *   (`blockers`); otherwise only their existence is shown.
 */
class OrganizationSetupCenter
{
    public const int MAX_NEXT_ACTIONS = 3;

    /** Display labels for the closed category vocabulary. */
    public const array CATEGORY_LABELS = [
        'organization' => 'Organization',
        'academic' => 'Academics',
        'people' => 'People',
        'operations' => 'Operations',
        'finance' => 'Finance',
        'communications' => 'Communications',
        'integrations' => 'Integrations',
        'portal' => 'Portal',
        'compliance' => 'Audit & compliance',
    ];

    public const array SCOPE_LABELS = [
        'school' => 'School',
        'campus' => 'Campus',
        'school_and_campus' => 'School & Campus',
    ];

    public function __construct(
        private readonly OrganizationSetupReadService $readiness,
        private readonly OrganizationSetupRegistry $registry,
        private readonly CapabilityResolver $capabilities,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException as OrganizationSetupReadService::evaluate()
     */
    public function forViewer(School $school, User $actor): array
    {
        $report = $this->readiness->evaluate($school, $actor);

        return [
            'school' => ['name' => $school->name],
            'evaluatedAt' => $report['evaluatedAt'],
            ...self::present($report['items'], $this->capabilities->schoolCapabilities($actor, $school), $this->registry),
        ];
    }

    /**
     * Pure: the Center's content from readiness items and the viewer's
     * School capabilities.
     *
     * @param  list<array<string, mixed>>  $items  OrganizationSetupReadService items
     * @param  list<string>  $held  the viewer's effective School capabilities
     * @return array{overview: array<string, mixed>, nextActions: list<array<string, string>>, categories: list<array<string, mixed>>, notEnabled: list<array<string, string>>}
     */
    public static function present(array $items, array $held, OrganizationSetupRegistry $registry): array
    {
        $titles = array_column($items, 'title', 'key');
        $cards = [];
        foreach ($items as $item) {
            $definition = $registry->find($item['key']) ?? throw new \LogicException("Unknown setup key {$item['key']}.");
            $cards[$item['key']] = self::card($item, $definition, $held, $titles);
        }

        $applicable = array_filter($cards, fn (array $card): bool => $card['status'] !== SetupStatus::NOT_APPLICABLE->value);
        $core = array_filter($applicable, fn (array $card): bool => $card['core']);
        $optional = array_filter($applicable, fn (array $card): bool => ! $card['core']);
        $configured = fn (array $cards): int => count(array_filter($cards, fn (array $card): bool => $card['status'] === SetupStatus::CONFIGURED->value));
        $countOf = fn (SetupStatus $status): int => count(array_filter($applicable, fn (array $card): bool => $card['status'] === $status->value));

        $coreDone = $configured($core);
        $optionalDone = $configured($optional);
        // An area with no setup of its own (no checks) is configured from day
        // one, so "getting started" means no core area with checks is done yet.
        $startedCore = array_filter($core, fn (array $card): bool => $card['totalChecks'] !== null && $card['status'] === SetupStatus::CONFIGURED->value);
        $overview = [
            'state' => match (true) {
                $startedCore === [] && $coreDone < count($core) => 'getting_started',
                $coreDone < count($core) => 'core_in_progress',
                $optionalDone < count($optional) => 'core_complete',
                default => 'complete',
            },
            'core' => ['configured' => $coreDone, 'total' => count($core)],
            'optional' => ['configured' => $optionalDone, 'total' => count($optional)],
            'attention' => $countOf(SetupStatus::ATTENTION_REQUIRED),
            'blocked' => $countOf(SetupStatus::BLOCKED),
            'notEnabled' => count($cards) - count($applicable),
            'hiddenAreas' => count($registry->all()) - count($cards),
        ];

        $categories = [];
        foreach (SetupCategory::cases() as $category) {
            $inCategory = array_values(array_filter($applicable, fn (array $card): bool => $card['category'] === $category->value));
            if ($inCategory !== []) {
                $categories[] = ['key' => $category->value, 'label' => self::CATEGORY_LABELS[$category->value], 'items' => $inCategory];
            }
        }

        $notEnabled = array_values(array_map(
            fn (array $card): array => ['key' => $card['key'], 'title' => $card['title'], 'owner' => $card['owner'], 'description' => $card['description']],
            array_filter($cards, fn (array $card): bool => $card['status'] === SetupStatus::NOT_APPLICABLE->value),
        ));

        return [
            'overview' => $overview,
            'nextActions' => self::nextActions($applicable, $registry),
            'categories' => $categories,
            'notEnabled' => $notEnabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $held
     * @param  array<string, string>  $titles  visible key => title
     * @return array<string, mixed>
     */
    private static function card(array $item, SetupDefinition $definition, array $held, array $titles): array
    {
        $applicable = $item['status'] !== SetupStatus::NOT_APPLICABLE->value;
        $canOpen = array_intersect($definition->openCapabilities, $held) !== [];
        $canManage = $definition->manageCapabilities !== [] && array_diff($definition->manageCapabilities, $held) === [];
        $kind = match (true) {
            ! $applicable || ! $canOpen => 'none',
            $canManage => 'configure',
            default => 'view',
        };

        return [
            'key' => $definition->key,
            'title' => $definition->title,
            'description' => $definition->description,
            'owner' => ModuleCatalogue::MODULES[$definition->ownerModule]['label'],
            'category' => $definition->category->value,
            'scope' => self::SCOPE_LABELS[$definition->scopeLevel->value],
            'core' => $definition->isCore(),
            'status' => $item['status'],
            'explanation' => $item['explanation'],
            'completedChecks' => $item['completedChecks'],
            'totalChecks' => $item['totalChecks'],
            'prerequisites' => array_map(fn (string $key): array => ['key' => $key, 'title' => $titles[$key]], $item['blockers']),
            'hiddenPrerequisite' => $item['hiddenBlockers'] > 0,
            'action' => ['kind' => $kind, 'href' => $kind === 'none' ? null : route($definition->route, absolute: false)],
        ];
    }

    /**
     * Priority: 0 unblocks another visible area, 1-3 core not configured /
     * partial / attention, 4-6 the same for enabled optional areas. BLOCKED
     * areas are never recommended (their prerequisite is); only areas the
     * viewer can configure are.
     *
     * @param  array<string, array<string, mixed>>  $applicable
     * @return list<array<string, string>>
     */
    private static function nextActions(array $applicable, OrganizationSetupRegistry $registry): array
    {
        $rank = [
            SetupStatus::NOT_CONFIGURED->value => 1,
            SetupStatus::PARTIALLY_CONFIGURED->value => 2,
            SetupStatus::ATTENTION_REQUIRED->value => 3,
        ];
        $waitingOn = [];
        foreach ($applicable as $card) {
            if (in_array($card['status'], [SetupStatus::BLOCKED->value, SetupStatus::ATTENTION_REQUIRED->value], true)) {
                foreach ($card['prerequisites'] as $prerequisite) {
                    $waitingOn[$prerequisite['key']] = true;
                }
            }
        }

        $candidates = [];
        foreach ($applicable as $key => $card) {
            if (! isset($rank[$card['status']]) || $card['action']['kind'] !== 'configure') {
                continue;
            }
            $priority = isset($waitingOn[$key]) ? 0 : $rank[$card['status']] + ($card['core'] ? 0 : 3);
            $candidates[] = [$priority, (int) $registry->find($key)?->order, $card];
        }
        usort($candidates, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $candidate): array => [
            'key' => $candidate[2]['key'],
            'title' => $candidate[2]['title'],
            'reason' => match (true) {
                $candidate[0] === 0 => 'Other setup areas are waiting for this.',
                $candidate[2]['status'] === SetupStatus::NOT_CONFIGURED->value => 'Not configured yet.',
                $candidate[2]['status'] === SetupStatus::PARTIALLY_CONFIGURED->value => 'Partly configured.',
                default => 'Needs attention.',
            },
            'href' => $candidate[2]['action']['href'],
        ], array_slice($candidates, 0, self::MAX_NEXT_ACTIONS));
    }
}
