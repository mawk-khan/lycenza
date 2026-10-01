<?php

namespace Tests\Unit\Students;

use App\Domain\Students\Application\Retention\StudentExit;
use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * E21.2D (E21-D7): the pure "final exit" rule every Student retention
 * operation shares. Anything ambiguous is unresolved (kept).
 */
class StudentExitResolutionTest extends TestCase
{
    private function resolve(string $status, array $enrollments, bool $activeSubject = false): StudentExit
    {
        return StudentRetentionEligibility::resolve($status, array_map(fn (array $e) => ['status' => $e[0], 'ends_on' => $e[1]], $enrollments), $activeSubject);
    }

    #[Test]
    public function an_active_student_is_current_whatever_its_placements(): void
    {
        $this->assertSame(StudentExit::CURRENT, $this->resolve('active', [['withdrawn', '2020-01-31']])->state);
        $this->assertSame(StudentExit::CURRENT, $this->resolve('active', [])->state);
    }

    #[Test]
    public function the_exit_date_is_the_latest_departure_across_years_campuses_and_re_entries(): void
    {
        // Withdrew in 2021, came back, completed in 2024: the clock starts in 2024.
        $exit = $this->resolve('inactive', [['withdrawn', '2021-11-30'], ['transferred', '2023-08-31'], ['completed', '2024-03-31']]);

        $this->assertSame(StudentExit::EXITED, $exit->state);
        $this->assertSame('2024-03-31', $exit->exitDate);
        $this->assertTrue($exit->exitedBefore('2024-04-01'));
        $this->assertFalse($exit->exitedBefore('2024-03-31'), 'strict: the exit day itself is kept');
    }

    #[Test]
    public function a_cancelled_placement_never_dates_or_blocks_a_departure(): void
    {
        $exit = $this->resolve('inactive', [['completed', '2024-03-31'], ['cancelled', '2026-01-31']]);

        $this->assertSame('2024-03-31', $exit->exitDate);
    }

    #[Test]
    public function every_ambiguous_state_is_unresolved(): void
    {
        $cases = [
            'never placed' => [[], false],
            'only cancelled' => [[['cancelled', '2024-03-31']], false],
            'still holding an active placement' => [[['completed', '2023-03-31'], ['active', null]], false],
            'an open placement with no end' => [[['withdrawn', null]], false],
            'last placement ended transferred' => [[['completed', '2023-03-31'], ['transferred', '2024-01-31']], false],
            'a tie with a non-departure' => [[['withdrawn', '2024-01-31'], ['transferred', '2024-01-31']], false],
            'an active subject enrollment' => [[['withdrawn', '2024-01-31']], true],
        ];

        foreach ($cases as $label => [$enrollments, $activeSubject]) {
            $this->assertSame(StudentExit::UNRESOLVED, $this->resolve('inactive', $enrollments, $activeSubject)->state, $label);
        }
    }
}
