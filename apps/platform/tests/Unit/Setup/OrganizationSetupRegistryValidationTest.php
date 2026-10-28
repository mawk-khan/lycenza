<?php

namespace Tests\Unit\Setup;

use App\Support\Setup\OrganizationSetupReadService;
use App\Support\Setup\OrganizationSetupRegistry;
use App\Support\Setup\SetupCategory;
use App\Support\Setup\SetupDefinition;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupScopeLevel;
use App\Support\Setup\SetupStatus;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ORG.12A (ADR 0080 §5-§8): the setup catalogue is a valid, acyclic,
 * deterministically ordered graph, and readiness answers stay bounded.
 * Pure: no application, database or tenant is involved.
 */
class OrganizationSetupRegistryValidationTest extends TestCase
{
    /** @param  list<string>  $dependsOn */
    private function definition(string $key, int $order, array $dependsOn = [], array $overrides = []): SetupDefinition
    {
        return new SetupDefinition(...[
            'key' => $key,
            'title' => 'Title',
            'description' => 'Description.',
            'ownerModule' => 'organization',
            'category' => SetupCategory::ORGANIZATION,
            'scopeLevel' => SetupScopeLevel::SCHOOL,
            'entitlementModules' => [],
            'route' => 'app.school-setup.index',
            'viewCapabilities' => [],
            'manageCapabilities' => [],
            'dependsOn' => $dependsOn,
            'order' => $order,
            'referenceFamilies' => [],
            ...$overrides,
        ]);
    }

    private function assertRefused(array $definitions, string $message): void
    {
        try {
            new OrganizationSetupRegistry($definitions);
            $this->fail("Expected refusal: {$message}");
        } catch (LogicException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    #[Test]
    public function the_code_owned_catalogue_is_valid(): void
    {
        $registry = new OrganizationSetupRegistry;

        $this->assertCount(count(OrganizationSetupRegistry::DEFINITIONS), $registry->all());
        $this->assertSame(array_column(OrganizationSetupRegistry::DEFINITIONS, 'key'), $registry->keys(), 'DEFINITIONS is written in its explicit order');
    }

    #[Test]
    public function duplicate_keys_and_duplicate_orders_are_refused(): void
    {
        $this->assertRefused([$this->definition('a.one', 10), $this->definition('a.one', 20)], 'defined twice');
        $this->assertRefused([$this->definition('a.one', 10), $this->definition('a.two', 10)], 'order 10 is used by both');
    }

    #[Test]
    public function unknown_self_and_repeated_dependencies_are_refused(): void
    {
        $this->assertRefused([$this->definition('a.one', 10, ['a.missing'])], 'unknown setup key a.missing');
        $this->assertRefused([$this->definition('a.one', 10, ['a.one'])], 'depends on itself');
        $this->assertRefused([$this->definition('a.one', 10), $this->definition('a.two', 20, ['a.one', 'a.one'])], 'dependency twice');
    }

    #[Test]
    public function cycles_are_refused_directly_not_only_through_ordering(): void
    {
        $this->assertRefused([$this->definition('a.one', 10, ['a.two']), $this->definition('a.two', 20, ['a.one'])], 'cycle');
        $this->assertRefused([
            $this->definition('a.one', 10, ['a.three']),
            $this->definition('a.two', 20, ['a.one']),
            $this->definition('a.three', 30, ['a.two']),
        ], 'cycle');
    }

    #[Test]
    public function a_dependency_must_be_ordered_first(): void
    {
        $this->assertRefused([$this->definition('a.one', 20), $this->definition('a.two', 10, ['a.one'])], 'ordered after its dependency a.one');
    }

    #[Test]
    public function a_large_hostile_graph_is_validated_in_linear_time(): void
    {
        // Keys are letters only: n => "a.n" + base-26 letters of n.
        $key = fn (int $n): string => 'a.n'.strtr(base_convert((string) $n, 10, 26), '0123456789abcdefghijklmnop', 'abcdefghijklmnopqrstuvwxyz');
        $chain = [];
        for ($i = 1; $i <= 3000; $i++) {
            $chain[] = $this->definition($key($i), $i, $i > 1 ? [$key($i - 1)] : []);
        }
        $start = hrtime(true);
        $this->assertCount(3000, (new OrganizationSetupRegistry($chain))->all());
        $this->assertLessThan(2_000_000_000, hrtime(true) - $start, 'validation is O(V + E)');

        $chain[0] = $this->definition($key(1), 1, [$key(3000)]);
        $this->assertRefused($chain, 'cycle');
    }

    #[Test]
    public function reserved_scopes_and_inconsistent_core_flags_are_refused(): void
    {
        $this->assertRefused([$this->definition('a.one', 10, [], ['scopeLevel' => SetupScopeLevel::NETWORK_FUTURE])], 'reserved scope');
        $this->assertRefused([$this->definition('a.one', 10, [], ['scopeLevel' => SetupScopeLevel::PLATFORM_FUTURE])], 'reserved scope');
        $this->assertRefused([$this->definition('a.one', 10, [], ['ownerModule' => 'nonexistent'])], 'unknown owner module');
        $this->assertRefused([$this->definition('a.one', 10, [], ['ownerModule' => 'transport'])], 'core exactly when');
        $this->assertRefused([$this->definition('a.one', 10, [], ['entitlementModules' => ['organization']])], 'must be an optional catalogue module');
        $this->assertRefused([$this->definition('a.one', 10, [], ['ownerModule' => 'transport', 'entitlementModules' => ['library']])], 'its own optional owner module');
    }

    #[Test]
    public function keys_follow_the_stable_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition('Not A Key', 10);
    }

    #[Test]
    public function ordering_is_explicit_and_independent_of_declaration_order(): void
    {
        $definitions = [$this->definition('a.three', 30, ['a.one']), $this->definition('a.one', 10), $this->definition('a.two', 20)];
        $first = (new OrganizationSetupRegistry($definitions))->keys();
        $second = (new OrganizationSetupRegistry(array_reverse($definitions)))->keys();

        $this->assertSame(['a.one', 'a.two', 'a.three'], $first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function readiness_answers_stay_bounded_and_never_claim_not_applicable(): void
    {
        $this->assertSame(SetupStatus::NOT_CONFIGURED, SetupReadiness::fromChecks(['a' => false, 'b' => false], 'yes', 'no')->status);
        $this->assertSame(SetupStatus::PARTIALLY_CONFIGURED, SetupReadiness::fromChecks(['a' => true, 'b' => false], 'yes', 'no')->status);
        $all = SetupReadiness::fromChecks(['a' => true, 'b' => true], 'yes', 'no');
        $this->assertSame([SetupStatus::CONFIGURED, 'yes', 2, 2], [$all->status, $all->explanation, $all->completedChecks, $all->totalChecks]);

        foreach ([
            fn () => new SetupReadiness(SetupStatus::NOT_APPLICABLE, 'x'),
            fn () => new SetupReadiness(SetupStatus::CONFIGURED, ''),
            fn () => new SetupReadiness(SetupStatus::CONFIGURED, str_repeat('x', SetupReadiness::MAX_EXPLANATION_LENGTH + 1)),
            fn () => new SetupReadiness(SetupStatus::CONFIGURED, 'x', 1, null),
            fn () => new SetupReadiness(SetupStatus::CONFIGURED, 'x', 3, 2),
        ] as $i => $invalid) {
            try {
                $invalid();
                $this->fail("invalid readiness {$i} was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function an_unsatisfied_dependency_blocks_incomplete_areas_and_flags_configured_ones(): void
    {
        $own = fn (SetupStatus $status, ?int $total = 1): SetupReadiness => new SetupReadiness($status, 'x', $total === null ? null : 0, $total);
        $configured = new SetupReadiness(SetupStatus::CONFIGURED, 'x', 2, 2);
        $this->assertSame(SetupStatus::CONFIGURED, OrganizationSetupReadService::compose($configured, []));
        $this->assertSame(SetupStatus::BLOCKED, OrganizationSetupReadService::compose($own(SetupStatus::NOT_CONFIGURED), ['a.one']));
        $this->assertSame(SetupStatus::BLOCKED, OrganizationSetupReadService::compose($own(SetupStatus::PARTIALLY_CONFIGURED), ['a.one']));
        $this->assertSame(SetupStatus::ATTENTION_REQUIRED, OrganizationSetupReadService::compose($configured, ['a.one']), 'own configuration is kept');
        $this->assertSame(SetupStatus::BLOCKED, OrganizationSetupReadService::compose(SetupReadiness::noSetupRequired('x'), ['a.one']), 'an area with no own setup is its dependencies');
        $this->assertSame(SetupStatus::ATTENTION_REQUIRED, OrganizationSetupReadService::compose($own(SetupStatus::ATTENTION_REQUIRED, null), ['a.one']));
        $this->assertSame(SetupStatus::BLOCKED, OrganizationSetupReadService::compose($own(SetupStatus::BLOCKED, null), ['a.one']));

        foreach (SetupStatus::cases() as $status) {
            $this->assertSame(
                in_array($status, [SetupStatus::CONFIGURED, SetupStatus::ATTENTION_REQUIRED, SetupStatus::NOT_APPLICABLE], true),
                $status->satisfiesDependents(),
                $status->value,
            );
        }
    }

    #[Test]
    public function the_vocabularies_are_closed_and_carry_no_production_readiness(): void
    {
        $this->assertSame(['organization', 'academic', 'people', 'operations', 'finance', 'communications', 'integrations', 'portal', 'compliance'], array_column(SetupCategory::cases(), 'value'));
        $this->assertSame(['school', 'campus', 'school_and_campus', 'network_future', 'platform_future'], array_column(SetupScopeLevel::cases(), 'value'));
        $this->assertSame(['not_applicable', 'not_configured', 'partially_configured', 'configured', 'blocked', 'attention_required'], array_column(SetupStatus::cases(), 'value'));
    }
}
