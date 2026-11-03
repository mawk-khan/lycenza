<?php

namespace Tests\Unit\HR;

use App\Domain\HR\Application\Profile\ExperienceCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** HR.2A (brief §26–§27): experience is derived, merged, never double counted. */
class ExperienceCalculatorTest extends TestCase
{
    #[Test]
    public function overlapping_and_adjacent_jobs_are_merged_before_counting(): void
    {
        // 2020-01-01..2020-12-31 (366 days) overlapping 2020-07-01..2021-06-30, adjacent 2021-07-01..2021-12-31.
        $result = ExperienceCalculator::previous([
            ['2020-01-01', '2020-12-31'],
            ['2020-07-01', '2021-06-30'],
            ['2021-07-01', '2021-12-31'],
        ], '2026-11-03');

        $this->assertSame(731, $result['days'], '2020-01-01..2021-12-31 inclusive, counted once');
        $this->assertSame([2, 0, 0], [$result['years'], $result['months'], $result['openEnded']]);
    }

    #[Test]
    public function an_open_ended_previous_job_is_not_counted_and_is_reported(): void
    {
        $result = ExperienceCalculator::previous([['2019-01-01', null], ['2018-01-01', '2018-06-30']], '2026-11-03');

        $this->assertSame(181, $result['days']);
        $this->assertSame(1, $result['openEnded']);
        $this->assertSame([0, 5], [$result['years'], $result['months']], 'floor(181 / 30.4375) = 5 months: never overstated');
    }

    #[Test]
    public function a_period_past_today_is_cut_at_today_and_nothing_is_a_zero(): void
    {
        $this->assertSame(3, ExperienceCalculator::previous([['2026-11-01', '2027-12-31']], '2026-11-03')['days']);
        $this->assertSame(['days' => 0, 'years' => 0, 'months' => 0, 'openEnded' => 0], ExperienceCalculator::previous([], '2026-11-03'));
    }

    #[Test]
    public function service_adds_up_rehires_without_the_gap(): void
    {
        $result = ExperienceCalculator::service([['2020-01-01', '2020-12-31'], ['2022-01-01', null]], '2022-01-31');

        $this->assertSame(366 + 31, $result['days']);
    }
}
