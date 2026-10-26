<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearDependentsOutsideException;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Fees\Application\FeeYearDateDependency;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Models\School;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeConcessionFixtures;
use Tests\TestCase;

/**
 * SIS 5.1 (MC-3): fee instalment periods and standing concession windows lie
 * inside their Academic Year and are immutable once in force, so a draft
 * year's date correction must never strand one outside. Finance records are
 * only counted, never changed.
 */
class DraftCalendarFeeDependencyTest extends TestCase
{
    use CreatesFeeConcessionFixtures;

    private function correct(School $school, AcademicYear $year, string $startsOn, string $endsOn): AcademicYear
    {
        $admin = $this->createUserWithCapabilities($school, ['academics.years.view', 'academics.years.manage']);
        $version = $this->inSchool($school, fn () => AcademicYear::query()->findOrFail($year->id))->updated_at->toIso8601String();

        return app(AcademicYearService::class)->correctDraftDates($school, $year->id, ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'expected_updated_at' => $version], $admin);
    }

    #[Test]
    public function a_draft_years_fee_instalments_block_a_correction_that_would_strand_them(): void
    {
        $w = $this->feeWorld();
        $w['year'] = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'status' => 'draft', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);
        $structure = $this->makeCompleteDraft($w);
        $periods = $this->inSchool($w['school'], fn () => FeeStructureInstallment::query()->get(['period_starts_on', 'period_ends_on'])
            ->map(fn ($i) => [$i->period_starts_on->toDateString(), $i->period_ends_on->toDateString()])->all());
        $this->assertSame([['2027-06-01', '2028-05-31']], $periods, 'a one-time schedule spans the whole year');

        try {
            $this->correct($w['school'], $w['year'], '2027-06-01', '2028-04-30');
            $this->fail('a fee instalment was stranded');
        } catch (AcademicYearDependentsOutsideException $e) {
            $this->assertSame('These dates cannot be saved: existing fee instalments or concessions would fall outside the Academic Year (1). Correct or end them first.', $e->getMessage());
        }

        $this->assertSame('2028-06-30', $this->correct($w['school'], $w['year'], '2027-06-01', '2028-06-30')->ends_on->toDateString());
        $this->assertSame([['2027-06-01', '2028-05-31']], $this->inSchool($w['school'], fn () => FeeStructureInstallment::query()->get(['period_starts_on', 'period_ends_on'])
            ->map(fn ($i) => [$i->period_starts_on->toDateString(), $i->period_ends_on->toDateString()])->all()), 'Finance records are never changed');
        $this->assertSame('draft', $this->inSchool($w['school'], fn () => $structure->refresh())->status);
    }

    #[Test]
    public function a_standing_concession_window_counts_as_a_dependent(): void
    {
        $w = $this->concessionWorld();
        $dependency = app(FeeYearDateDependency::class);
        $count = fn () => $this->inSchool($w['school'], fn () => $dependency->recordsOutside($w['school'], $w['year']->id, '2026-07-01', '2027-05-31'));
        $before = $count();

        $this->requestStanding($w, $w['student']->id, 'percentage', '10.00', from: '2026-06-01', to: '2027-05-31');

        $this->assertSame($before + 1, $count());
    }
}
