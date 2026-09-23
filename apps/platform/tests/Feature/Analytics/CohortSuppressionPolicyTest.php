<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Application\ClassificationTier;
use App\Domain\Analytics\Application\CohortSuppressionPolicy;
use App\Domain\Analytics\Application\Exceptions\AnalyticsReportUnavailableException;
use App\Domain\Analytics\Application\ReadModelDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0L.2-1 -- the fail-closed person-cohort policy. No value has
 * been approved, so none is used here as policy: the only configured
 * value below (7) exists purely to prove the parser and the switch, and
 * carries no meaning.
 */
class CohortSuppressionPolicyTest extends TestCase
{
    private function declaration(bool $countsPeople): ReadModelDeclaration
    {
        return new ReadModelDeclaration('test.policy', ClassificationTier::Confidential, $countsPeople, ['TestFixture'], []);
    }

    #[Test]
    public function the_shipped_configuration_has_no_minimum_person_cohort_size(): void
    {
        $this->assertNull(config('analytics.minimum_person_cohort_size'));
        $this->assertFalse(app(CohortSuppressionPolicy::class)->isPersonCohortPolicyConfigured());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidValues(): array
    {
        return [
            'null' => [null], 'empty' => [''], 'blank' => ['  '], 'zero' => ['0'], 'zero int' => [0],
            'negative' => ['-3'], 'negative int' => [-3], 'decimal' => ['2.5'], 'float' => [2.5],
            'word' => ['ten'], 'bool' => [true], 'leading zero' => ['07'],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function anything_but_a_positive_whole_number_counts_as_unset(mixed $value): void
    {
        config(['analytics.minimum_person_cohort_size' => $value]);

        $policy = app(CohortSuppressionPolicy::class);
        $this->assertNull($policy->minimumPersonCohortSize());

        $this->expectException(AnalyticsReportUnavailableException::class);
        $policy->assertServable($this->declaration(countsPeople: true));
    }

    #[Test]
    public function a_person_counting_declaration_fails_closed_with_503_while_unset(): void
    {
        try {
            app(CohortSuppressionPolicy::class)->assertServable($this->declaration(countsPeople: true));
            $this->fail('A person-counting read model must be refused while no minimum cohort size is configured.');
        } catch (AnalyticsReportUnavailableException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertStringContainsString('counts people', $e->getMessage());
        }
    }

    #[Test]
    public function a_non_person_declaration_is_served_while_unset(): void
    {
        app(CohortSuppressionPolicy::class)->assertServable($this->declaration(countsPeople: false));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_configured_value_is_the_only_switch_for_person_counting(): void
    {
        config(['analytics.minimum_person_cohort_size' => '7']);

        $policy = app(CohortSuppressionPolicy::class);
        $this->assertSame(7, $policy->minimumPersonCohortSize());
        $policy->assertServable($this->declaration(countsPeople: true));
    }
}
