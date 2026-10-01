<?php

namespace Tests\Unit\HR;

use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Domain\HR\Application\Retention\EmployeeSeparation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * E21.2E (E21-D9): the pure "final separation" rule every Employee retention
 * operation shares. Anything current, future or ambiguous keeps the Employee.
 */
class EmployeeSeparationResolutionTest extends TestCase
{
    private function resolve(array $employments): EmployeeSeparation
    {
        return EmployeeRetentionEligibility::resolve(array_map(fn (array $e) => ['status' => $e[0], 'ends_on' => $e[1]], $employments));
    }

    #[Test]
    public function the_separation_date_is_the_last_terminal_end_across_rehires(): void
    {
        $separation = $this->resolve([['separated', '2020-06-30'], ['retired', '2024-03-31']]);

        $this->assertSame(EmployeeSeparation::SEPARATED, $separation->state);
        $this->assertSame('2024-03-31', $separation->separatedOn);
        $this->assertTrue($separation->separatedBefore('2024-04-01'));
        $this->assertFalse($separation->separatedBefore('2024-03-31'), 'strict: the last day itself is kept');

        foreach (['terminated', 'deceased'] as $status) {
            $this->assertSame(EmployeeSeparation::SEPARATED, $this->resolve([[$status, '2024-01-31']])->state, $status);
        }
    }

    #[Test]
    public function any_current_or_future_employment_keeps_the_employee_current(): void
    {
        foreach (['draft', 'pre_joining', 'active', 'notice_period'] as $status) {
            $this->assertSame(EmployeeSeparation::CURRENT, $this->resolve([['separated', '2020-06-30'], [$status, null]])->state, "rehired: {$status}");
        }
    }

    #[Test]
    public function an_ambiguous_separation_is_unresolved(): void
    {
        $this->assertSame(EmployeeSeparation::UNRESOLVED, $this->resolve([])->state, 'never employed');
        $this->assertSame(EmployeeSeparation::UNRESOLVED, $this->resolve([['separated', null]])->state, 'terminal without an end date');
        $this->assertSame(EmployeeSeparation::UNRESOLVED, $this->resolve([['separated', '2020-06-30'], ['terminated', null]])->state);
    }
}
